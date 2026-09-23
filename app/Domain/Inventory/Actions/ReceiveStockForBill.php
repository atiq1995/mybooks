<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\Data\MovementRequest;
use App\Domain\Inventory\Enums\StockMovementKind;
use App\Domain\Inventory\Services\InventoryAccounts;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\WarehouseResolver;
use App\Domain\Purchases\Enums\PurchaseDocumentType;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Domain\Purchases\Models\PurchaseDocumentLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Goods arrive, at what the bill says they cost.
 *
 * This posts NOTHING, and that is the whole design. The bill has already
 * debited the inventory account — §4.6's "5xxx Expense **or** 1300
 * Inventory" — because a tracked item's line defaults to the item's inventory
 * account. All this does is record the same value in the stock ledger, so the
 * two halves of I10 are written from one figure and cannot disagree.
 *
 * That figure is `capitalisedCost()`, not the line's net. It is the taxable
 * amount after the line discount AND the document-level discount, plus any
 * tax that could not be reclaimed — §4.6 again: blocked tax is part of what
 * the stock cost, not a receivable. Using `net` would value the shelf above
 * what the ledger debited on any bill carrying a header discount.
 *
 * A vendor credit runs the same code backwards: goods went back, so the
 * quantity leaves and the value with it. It leaves at **the credit's own
 * cost**, stated explicitly, not at the weighted average — because the credit
 * has already credited the inventory account by that figure, and the shelf
 * has to move by the same one. Letting the average decide there was a real
 * defect: buy at 10, buy at 20, credit back a unit of the first batch, and
 * the ledger credits 10 while the shelf gives up 15.
 *
 * ## Currency
 *
 * The stock ledger holds base currency only, because that is the only
 * currency the control account is kept in. A USD bill in a PKR business
 * debits inventory with the converted figure, so the shelf must record the
 * converted figure too.
 *
 * Converting each line on its own would not be enough: the entry aggregates
 * the lines that share an account and converts the TOTAL once, so a set of
 * separately-rounded line conversions can miss it by a cent — and a cent that
 * never clears is exactly what I10 exists to catch. The conversion here is
 * therefore cumulative per account: each line takes the difference between
 * the converted running total up to and including it, and what has already
 * been handed out. The last line absorbs the rounding, and the sum is the
 * posted debit exactly.
 *
 * @see ACCOUNTING_RULES.md §4.6, I10
 */
final readonly class ReceiveStockForBill
{
    public function __construct(
        private StockLedger $ledger,
        private WarehouseResolver $warehouses,
        private InventoryAccounts $inventoryAccounts,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  Carbon|null  $receivedOn  defaults to the date the bill posted,
     *                                   so the stock and the ledger agree in time
     */
    public function handle(
        PurchaseDocument $document,
        ?User $actor = null,
        ?Carbon $receivedOn = null,
    ): int {
        $lines = $this->trackedLines($document);

        if ($lines === []) {
            return 0;
        }

        $receivedOn ??= Carbon::parse($document->issue_date->toDateString());

        $goodsIn = $document->type !== PurchaseDocumentType::VendorCredit;

        return DB::transaction(function () use ($document, $lines, $actor, $receivedOn, $goodsIn): int {
            $requests = [];
            $baseCost = $this->baseCostByLine($document, $lines);

            foreach ($lines as $line) {
                $item = $line->item;

                if ($item === null) {
                    continue;
                }

                $quantity = BigDecimal::of($line->quantity);

                $requests[] = new MovementRequest(
                    item: $item,
                    warehouseId: $this->warehouses->forLine($line->warehouse_id)->id,
                    kind: $goodsIn ? StockMovementKind::Receipt : StockMovementKind::ReturnOut,
                    quantity: (string) ($goodsIn ? $quantity : $quantity->negated()),
                    occurredOn: $receivedOn,
                    sourceType: 'purchase_document',
                    sourceId: $document->id,
                    sourceLineId: $line->id,
                    /*
                     * The VALUE, not a rate, and in base currency — the two
                     * things that make the shelf and the ledger one figure
                     * rather than two.
                     *
                     * Not a rate: the capitalised cost already carries blocked
                     * tax and a share of any document discount, so dividing it
                     * into a per-unit rate and multiplying back would round
                     * twice. In base currency: that is what was debited.
                     *
                     * Stated for goods going BACK as well. The credit note has
                     * already credited the inventory account by this figure;
                     * if the shelf gave up the weighted average instead, the
                     * two would part company by however far the average had
                     * moved since the goods arrived.
                     */
                    totalValue: (string) $baseCost[$line->id],
                    /*
                     * The account the ENTRY debited, frozen on the line when
                     * the bill was saved — not the item's account as it reads
                     * now. The two can differ: an item's inventory account is
                     * editable while a bill sits in draft, and the entry uses
                     * the frozen one.
                     */
                    inventoryAccountId: $line->debit_account_id,
                    memo: $document->number,
                );
            }

            if ($requests === []) {
                return 0;
            }

            $planned = $this->ledger->plan($requests);

            /*
             * The entry is the BILL's, posted moments ago by the action that
             * called this one. Recording it here is what lets somebody
             * standing in front of a stock figure get back to the document
             * that produced it.
             */
            $movements = $this->ledger->commit($planned, $document->journal_entry_id, $actor);

            $this->audit->record(
                action: $goodsIn ? 'inventory.goods_received' : 'inventory.goods_returned_to_vendor',
                subject: $document,
                description: sprintf(
                    '%s %d stock %s from %s on %s',
                    $goodsIn ? 'Received' : 'Returned',
                    count($movements),
                    count($movements) === 1 ? 'line' : 'lines',
                    $document->number,
                    $receivedOn->toDateString(),
                ),
                new: [
                    'document' => $document->number,
                    'received_on' => $receivedOn->toDateString(),
                    'lines' => count($movements),
                ],
                actor: $actor,
            );

            return count($movements);
        });
    }

    /**
     * Take back what a voided document put on the shelf — or put back what it
     * took off.
     *
     * Voiding a bill reverses its entry, and that reversal credits the
     * inventory account by what the bill debited. Without this, the goods
     * stayed on the shelf: the control account went to nil, the stock report
     * still showed the stock, and I10 was out by the whole bill from the void
     * date onwards. Nothing warned, because nothing was looking.
     *
     * The movements are reversed at **the value they carried**, matching the
     * mirror reversal on the ledger side exactly. Where the goods have since
     * been sold, that value is no longer on the shelf to give back, and
     * {@see StockRefused::valueDoesNotFit()} refuses the void and says to
     * raise a vendor credit instead — which is the correct document for
     * "these goods went back", and dates it when it happened.
     *
     * Runs inside the caller's transaction. It writes no entry of its own:
     * the caller's reversal is the ledger side, and its id is recorded on the
     * movements so the two can be found from each other.
     *
     * `$voidedOn` is required rather than defaulted, because the caller has
     * to give the SAME date to the entry reversal. Defaulting it here once
     * meant the money was reversed today and the goods on the document's own
     * issue date, which put the two sides weeks apart on every void done from
     * the screen — the screen sends no date.
     */
    public function unreceive(
        PurchaseDocument $document,
        Carbon $voidedOn,
        ?User $actor = null,
        ?string $reversalEntryId = null,
    ): int {
        $movements = $this->ledger->movementsFor('purchase_document', $document->id);

        if ($movements === []) {
            return 0;
        }

        $requests = $this->ledger->reversalRequests(
            movements: $movements,
            occurredOn: $voidedOn,
            sourceType: 'purchase_document_void',
            sourceId: $document->id,
            memo: "Void of {$document->number}",
        );

        $written = $this->ledger->commit(
            $this->ledger->plan($requests),
            $reversalEntryId,
            $actor,
        );

        $this->audit->record(
            action: 'inventory.receipt_reversed',
            subject: $document,
            description: sprintf(
                'Reversed %d stock %s for voided %s on %s',
                count($written),
                count($written) === 1 ? 'line' : 'lines',
                $document->number,
                $voidedOn->toDateString(),
            ),
            new: [
                'document' => $document->number,
                'voided_on' => $voidedOn->toDateString(),
                'lines' => count($written),
                'reversal_entry' => $reversalEntryId,
            ],
            actor: $actor,
        );

        return count($written);
    }

    /**
     * Each line's capitalised cost in BASE currency, keyed by line id.
     *
     * Cumulative per account, so that the figures put on the shelf add up to
     * the figure the entry actually debited. The entry sums the lines sharing
     * an account and converts once; converting each line separately and
     * rounding each one can land a cent away from that, and a cent between
     * the stock report and the inventory account is a difference somebody
     * spends an afternoon on. Here, every line takes what the converted
     * running total owes it, so the last line absorbs the rounding and the
     * total is exact by construction.
     *
     * A base-currency document has a rate of 1 and falls straight through.
     *
     * @param  list<PurchaseDocumentLine>  $lines
     * @return array<string, BigDecimal>
     */
    private function baseCostByLine(PurchaseDocument $document, array $lines): array
    {
        $rate = BigDecimal::of($document->exchange_rate);

        /** @var array<string, BigDecimal> $runningCost */
        $runningCost = [];
        /** @var array<string, BigDecimal> $runningBase */
        $runningBase = [];
        /** @var array<string, BigDecimal> $byLine */
        $byLine = [];

        foreach ($lines as $line) {
            $account = $line->debit_account_id ?? '';

            $cost = ($runningCost[$account] ?? BigDecimal::zero())
                ->plus(BigDecimal::of($line->capitalisedCost()));

            $base = $cost->multipliedBy($rate)->toScale(4, RoundingMode::HalfUp);

            $byLine[$line->id] = $base->minus($runningBase[$account] ?? BigDecimal::zero());

            $runningCost[$account] = $cost;
            $runningBase[$account] = $base;
        }

        return $byLine;
    }

    /**
     * The lines that moved goods — decided by where the ENTRY put the money.
     *
     * A line belongs here when the account it was costed to is an inventory
     * account, not when the item happens to be tracked right now. The two
     * come apart in a window that is easy to reach: a bill line freezes its
     * account when the bill is saved, while `is_tracked` is a live field on
     * the item. Untick tracking on a draft bill's item and the old test
     * silently dropped the line — the entry still debited inventory, and
     * nothing went on the shelf. Tick it on and the mirror happened.
     *
     * Reading the frozen account instead makes the rule exact: the ledger
     * debited stock, so stock moved. Where the item is no longer tracked the
     * stock ledger refuses the whole approval, which is the right outcome —
     * a bill that debits inventory for something not being tracked is a
     * contradiction somebody has to resolve, not something to paper over.
     *
     * @return list<PurchaseDocumentLine>
     */
    private function trackedLines(PurchaseDocument $document): array
    {
        $accounts = $this->inventoryAccounts;

        return array_values($document->lines()
            ->with('item')
            ->get()
            ->filter(static function (PurchaseDocumentLine $line) use ($accounts): bool {
                if ($line->item === null || ! BigDecimal::of($line->quantity)->isPositive()) {
                    return false;
                }

                return $accounts->has($line->debit_account_id);
            })
            ->all());
    }
}
