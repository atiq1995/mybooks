<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\Data\CostOfSalesPosting;
use App\Domain\Inventory\Data\MovementRequest;
use App\Domain\Inventory\Enums\StockMovementKind;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\WarehouseResolver;
use App\Domain\Sales\Enums\SalesDocumentType;
use App\Domain\Sales\Models\SalesDocument;
use App\Domain\Sales\Models\SalesDocumentLine;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Goods leave the shelf, and their cost leaves with them.
 *
 * §4.10: cost of sales posts **on shipment**, at the weighted average of that
 * moment — not at the invoice date, and not at whatever the item's purchase
 * price happens to say today. The two are usually the same day, which is why
 * issuing an invoice despatches by default; they are not always, which is why
 * the date is a parameter.
 *
 * A credit note runs the same code in the other direction: the goods come
 * back on to the shelf and the cost comes back out of cost of sales. It
 * posts a fresh entry rather than reversing the original, because the
 * original shipment happened and a reversal would say it did not — the same
 * treatment the revenue side gives a credit note.
 *
 * Three refusals, each protecting something that cannot be fixed afterwards:
 *
 *   despatching twice would charge the cost twice, and the unique index on
 *   (source, line, kind) is what arbitrates between two processes trying it;
 *
 *   despatching more than is on the shelf would make the average undefined
 *   and put the inventory account into credit;
 *
 *   despatching an item whose average is zero posts nothing at all rather
 *   than a zero-value entry, which the ledger refuses outright.
 *
 * @see ACCOUNTING_RULES.md §4.10, I10
 */
final readonly class ShipSalesDocument
{
    public function __construct(
        private TenantContext $tenant,
        private StockLedger $ledger,
        private WarehouseResolver $warehouses,
        private PostJournalEntry $postJournalEntry,
        private ReverseJournalEntry $reverseJournalEntry,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  Carbon|null  $shippedOn  the date the goods actually left, which
     *                                  §4.10 distinguishes from the invoice date
     */
    public function handle(
        SalesDocument $document,
        ?User $actor = null,
        ?Carbon $shippedOn = null,
        bool $allowClosedPeriod = false,
    ): ?string {
        $lines = $this->trackedLines($document);

        if ($lines === []) {
            return null;
        }

        $shippedOn ??= Carbon::parse($document->issue_date->toDateString());

        $organization = $this->tenant->organization();
        $goodsOut = $document->type !== SalesDocumentType::CreditNote;

        return DB::transaction(function () use (
            $document,
            $lines,
            $actor,
            $shippedOn,
            $allowClosedPeriod,
            $organization,
            $goodsOut,
        ): ?string {
            $requests = [];

            foreach ($lines as $line) {
                $item = $line->item;

                if ($item === null) {
                    continue;
                }

                $quantity = BigDecimal::of($line->quantity);

                $requests[] = new MovementRequest(
                    item: $item,
                    warehouseId: $this->warehouses->forLine($line->warehouse_id)->id,
                    kind: $goodsOut ? StockMovementKind::Shipment : StockMovementKind::ReturnIn,
                    // Out is negative, in is positive. The sign IS the
                    // direction — a separate flag could disagree with it.
                    quantity: (string) ($goodsOut ? $quantity->negated() : $quantity),
                    occurredOn: $shippedOn,
                    sourceType: 'sales_document',
                    sourceId: $document->id,
                    sourceLineId: $line->id,
                    /*
                     * Goods coming back are valued at the average of the
                     * moment they return, not at what they cost when they
                     * left. The alternative — hunting down the original
                     * shipment's rate — is only defensible when the credit
                     * note names the invoice AND the line, and a partial
                     * credit against a re-priced invoice has no such line.
                     * Documented here because it is a real choice: a return
                     * during a period of rising costs moves a little margin.
                     */
                    unitCost: null,
                    memo: $document->number,
                );
            }

            if ($requests === []) {
                return null;
            }

            $planned = $this->ledger->plan($requests);
            $costByAccount = $this->ledger->valueByAccount($planned);

            /*
             * Nothing to post. An item whose average cost is zero — never
             * bought, or written down to nothing — despatches at zero, and
             * the ledger refuses a zero-value entry outright. The MOVEMENT
             * still happens: the quantity left the shelf whatever it was
             * worth.
             */
            $total = BigDecimal::zero();

            foreach ($costByAccount as $value) {
                $total = $total->plus(BigDecimal::of($value)->abs());
            }

            $entryId = null;

            if (! $total->isZero()) {
                $entry = $this->postJournalEntry->handle(
                    draft: (new CostOfSalesPosting(
                        costOfSalesAccountId: $this->systemAccountId(SystemAccount::CostOfGoodsSold),
                        costByInventoryAccount: array_map(
                            static fn (string $value): string => (string) BigDecimal::of($value)->abs(),
                            $costByAccount,
                        ),
                        currency: $organization->base_currency,
                        date: $shippedOn,
                        documentId: $document->id,
                        documentNumber: $document->number,
                        contactId: $document->contact_id,
                        direction: $goodsOut ? 'shipment' : 'restock',
                    ))->toDraft(),
                    actor: $actor,
                    allowClosedPeriod: $allowClosedPeriod,
                );

                $entryId = $entry->id;
            }

            $movements = $this->ledger->commit($planned, $entryId, $actor);

            $this->audit->record(
                action: $goodsOut ? 'inventory.goods_despatched' : 'inventory.goods_returned',
                subject: $document,
                description: sprintf(
                    '%s %d stock %s for %s on %s — cost %s',
                    $goodsOut ? 'Despatched' : 'Took back',
                    count($movements),
                    count($movements) === 1 ? 'line' : 'lines',
                    $document->number,
                    $shippedOn->toDateString(),
                    (string) $total,
                ),
                new: [
                    'document' => $document->number,
                    'shipped_on' => $shippedOn->toDateString(),
                    'lines' => count($movements),
                    'cost' => (string) $total,
                    'journal_entry' => $entryId,
                ],
                actor: $actor,
            );

            return $entryId;
        });
    }

    /**
     * Put back what a voided invoice took off the shelf, and un-charge it.
     *
     * A void on the sales side has two halves, and only one of them was being
     * done. `VoidSalesDocument` reverses the document's own entry — the
     * revenue and the receivable — but the cost of sales entry is a SEPARATE
     * entry under its own purpose, and the goods are a separate fact again.
     * Left alone, voiding a despatched invoice kept the cost charged and the
     * units off the shelf for ever: profit understated, inventory understated,
     * and I10 out by the cost of the goods from the void date on.
     *
     * Both halves are undone at the ORIGINAL cost. The stock reversal mirrors
     * each movement's own value, and the entry reversal mirrors the entry, so
     * the two sides move by the same figure however far the average has
     * travelled since.
     *
     * Runs inside the caller's transaction. `$voidedOn` is required so that
     * the caller gives the same date to the sale's own reversal — defaulting
     * it separately put the revenue in one period and the cost in another.
     */
    public function unship(
        SalesDocument $document,
        Carbon $voidedOn,
        ?User $actor = null,
        ?string $reason = null,
    ): ?string {
        $movements = $this->ledger->movementsFor('sales_document', $document->id);

        if ($movements === []) {
            return null;
        }

        /*
         * The cost entry, not the document's. `journal_entry_id` on the
         * document is the sale; this one lives under the document's id with
         * its own purpose, which is what kept the ledger from refusing it as
         * a double-post of the sale when it was written.
         */
        $entryId = null;

        $costEntries = JournalEntry::query()
            ->where('source_type', 'sales_document')
            ->where('source_id', $document->getKey())
            ->whereIn('source_purpose', ['cogs', 'cogs_reversal'])
            ->get();

        foreach ($costEntries as $entry) {
            if ($entry->isReversed()) {
                continue;
            }

            $entryId = $this->reverseJournalEntry->handle(
                entry: $entry,
                actor: $actor,
                date: $voidedOn,
                reason: $reason ?? "Void of {$document->number}",
            )->id;
        }

        $written = $this->ledger->commit(
            $this->ledger->plan($this->ledger->reversalRequests(
                movements: $movements,
                occurredOn: $voidedOn,
                sourceType: 'sales_document_void',
                sourceId: $document->id,
                memo: "Void of {$document->number}",
            )),
            $entryId,
            $actor,
        );

        $this->audit->record(
            action: 'inventory.despatch_reversed',
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
                'reversal_entry' => $entryId,
            ],
            actor: $actor,
        );

        return $entryId;
    }

    /**
     * Whether this document has already moved stock.
     *
     * Checked before issuing rather than relying on the unique index alone,
     * so a second issue fails with a sentence instead of a constraint name.
     */
    public function hasShipped(SalesDocument $document): bool
    {
        return DB::table('stock_movements')
            ->where('source_type', 'sales_document')
            ->where('source_id', $document->getKey())
            ->exists();
    }

    /**
     * The lines that move stock: tracked goods, in a positive quantity.
     *
     * @return list<SalesDocumentLine>
     */
    private function trackedLines(SalesDocument $document): array
    {
        return array_values($document->lines()
            ->with('item')
            ->get()
            ->filter(static function (SalesDocumentLine $line): bool {
                $item = $line->item;

                return $item !== null
                    && $item->is_tracked
                    && BigDecimal::of($line->quantity)->isPositive();
            })
            ->all());
    }

    private function systemAccountId(SystemAccount $role): string
    {
        $id = Account::query()->where('system_role', $role->value)->value('id');

        if (! is_string($id)) {
            throw PostingRefused::missingSystemAccount($role->value);
        }

        return $id;
    }
}
