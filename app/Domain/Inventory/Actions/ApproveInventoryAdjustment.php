<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\Data\InventoryAdjustmentPosting;
use App\Domain\Inventory\Data\MovementRequest;
use App\Domain\Inventory\Enums\StockMovementKind;
use App\Domain\Inventory\Exceptions\StockRefused;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\InventoryAdjustmentLine;
use App\Domain\Inventory\Services\StockLedger;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approving an adjustment is what moves the stock and posts the entry.
 *
 * §8 puts the posting moment here rather than at entry, and the reason is the
 * same one bills have: an adjustment is the only route by which stock changes
 * without a sale or a purchase behind it, which makes it the only route by
 * which a shortfall could be made to disappear quietly. Somebody writes it
 * down; somebody agrees to it.
 *
 * The stock moves and the ledger posts in one transaction, from one set of
 * figures — the movements are costed first, the entry is built from what they
 * came to, and both commit together. That is what keeps I10 true across an
 * adjustment.
 *
 * @see ACCOUNTING_RULES.md §4.16, I10
 */
final readonly class ApproveInventoryAdjustment
{
    public function __construct(
        private TenantContext $tenant,
        private StockLedger $ledger,
        private DocumentNumberGenerator $numbers,
        private PostJournalEntry $postJournalEntry,
        private ReverseJournalEntry $reverseJournalEntry,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        InventoryAdjustment $adjustment,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
    ): InventoryAdjustment {
        if ($adjustment->isApproved()) {
            throw StockRefused::adjustmentAlreadyApproved($adjustment->number);
        }

        $lines = $adjustment->lines()->with('item')->get();

        if ($lines->isEmpty()) {
            throw StockRefused::adjustmentChangesNothing($adjustment->number);
        }

        $organization = $this->tenant->organization();
        $date = Carbon::parse($adjustment->adjustment_date->toDateString());

        return DB::transaction(function () use (
            $adjustment,
            $lines,
            $actor,
            $allowClosedPeriod,
            $organization,
            $date,
        ): InventoryAdjustment {
            $requests = [];

            foreach ($lines as $line) {
                /** @var InventoryAdjustmentLine $line */
                $item = $line->item;

                if ($item === null) {
                    continue;
                }

                $quantityChange = BigDecimal::of($line->quantity_change);
                $valueChange = BigDecimal::of($line->value_change);

                $requests[] = new MovementRequest(
                    item: $item,
                    warehouseId: $adjustment->warehouse_id,
                    kind: $quantityChange->isZero()
                        ? StockMovementKind::Revaluation
                        : ($adjustment->kind === 'opening'
                            ? StockMovementKind::Opening
                            : StockMovementKind::Adjustment),
                    quantity: (string) $quantityChange,
                    occurredOn: $date,
                    sourceType: 'inventory_adjustment',
                    sourceId: $adjustment->id,
                    sourceLineId: $line->id,
                    unitCost: $line->unit_cost,
                    /*
                     * A revaluation states a VALUE and no quantity, so the
                     * value is what moves. A quantity adjustment with a
                     * stated value is the same thing said the other way
                     * round — somebody who knows what the units are worth.
                     */
                    totalValue: $valueChange->isZero() ? null : (string) $valueChange,
                    memo: $line->memo,
                );
            }

            if ($requests === []) {
                throw StockRefused::adjustmentChangesNothing($adjustment->number);
            }

            /*
             * The journal sequence before the stock levels, because the
             * purchase and sales sides take them in that order and two orders
             * is a deadlock. See {@see DocumentNumberGenerator::reserve()}.
             */
            $this->numbers->reserve('journal');

            $planned = $this->ledger->plan($requests);
            $valueByAccount = $this->ledger->valueByAccount($planned);

            /*
             * The total is the sum of the ABSOLUTE movements per account, not
             * their net.
             *
             * Netting them was a defect with a narrow but total failure: an
             * adjustment writing 500 off one inventory account and 500 on to
             * another nets to zero, so nothing posted — while the stock moved
             * on both. Both accounts were then permanently out by 500, and
             * the adjustment screen showed a value of zero, so there was
             * nothing to look at. The entry for that case is a perfectly good
             * one; it simply has no contra line, because the two inventory
             * lines already balance each other.
             */
            $total = BigDecimal::zero();

            foreach ($valueByAccount as $value) {
                $total = $total->plus(BigDecimal::of($value)->abs());
            }

            /*
             * An adjustment that moves quantity but no value posts nothing.
             * Stock written down to zero still takes units off the shelf, and
             * the ledger refuses a zero-value entry — correctly, since no
             * value moved.
             */
            $entryId = null;

            if (! $total->isZero()) {
                $entry = $this->postJournalEntry->handle(
                    draft: (new InventoryAdjustmentPosting(
                        contraAccountId: $adjustment->account_id,
                        valueByInventoryAccount: $valueByAccount,
                        currency: $organization->base_currency,
                        date: $date,
                        adjustmentId: $adjustment->id,
                        adjustmentNumber: $adjustment->number,
                        reason: $adjustment->reason,
                    ))->toDraft(),
                    actor: $actor,
                    allowClosedPeriod: $allowClosedPeriod,
                );

                $entryId = $entry->id;
            }

            $this->ledger->commit($planned, $entryId, $actor);

            $adjustment->forceFill([
                'status' => 'approved',
                'total_value' => (string) $total->toScale(4, RoundingMode::HalfUp),
                'journal_entry_id' => $entryId,
                'approved_at' => Carbon::now(),
                'approved_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'inventory.adjustment_approved',
                subject: $adjustment,
                description: sprintf(
                    'Approved adjustment %s — %s, %s %s',
                    $adjustment->number,
                    $adjustment->reason,
                    $organization->base_currency,
                    (string) $total->toScale(4, RoundingMode::HalfUp),
                ),
                new: [
                    'number' => $adjustment->number,
                    'lines' => count($planned),
                    'value' => (string) $total->toScale(4, RoundingMode::HalfUp),
                    'journal_entry' => $entryId,
                ],
                actor: $actor,
            );

            return $adjustment->refresh();
        });
    }

    /**
     * Void an approved adjustment by putting the stock back and reversing.
     *
     * Not a delete, and not an edit. The stock ledger is append-only, so
     * undoing an adjustment means moving the quantity the other way, which is
     * itself a movement.
     *
     * Dated TODAY, not at the adjustment — §4.15's rule that a reversal is
     * dated in an open period, and the same rule the document voids follow.
     * Back-dating the undo into the period being corrected would also make it
     * unusable exactly when it is most needed: a stocktake error found after
     * the month closed could not be voided at all, because the entry would
     * land in a closed period and be refused.
     *
     * The two halves take the same date, whatever it is. That is the whole
     * lesson of this phase: a void where the money and the goods default
     * their dates separately leaves the books wrong on every date between
     * them, and right today, which is the hardest kind of wrong to find.
     *
     * The value put back is the value that was taken, read off the movements
     * themselves. "The shelf is worth what the shelf is worth" sounds like
     * the right instinct and is the wrong rule here: the ledger side is a
     * mirror reversal of the original entry, so anything else guarantees the
     * stock and the inventory account disagree by however far the average has
     * moved. Where the goods have since left, the undo cannot fit and
     * {@see StockRefused::valueDoesNotFit()} refuses it.
     */
    public function void(
        InventoryAdjustment $adjustment,
        ?User $actor = null,
        ?string $reason = null,
        ?Carbon $date = null,
    ): InventoryAdjustment {
        if ($adjustment->isVoided()) {
            throw StockRefused::adjustmentAlreadyApproved($adjustment->number);
        }

        if (! $adjustment->isApproved()) {
            throw StockRefused::cannotVoidApproved($adjustment->number);
        }

        // Resolved once, before the fork, and handed to both halves.
        $date ??= Carbon::now();

        return DB::transaction(function () use ($adjustment, $actor, $reason, $date): InventoryAdjustment {
            /*
             * Undone from the MOVEMENTS the approval wrote, at the values
             * they carried — not re-derived from the adjustment's lines and
             * re-costed at today's average.
             *
             * The ledger side of this void is a mirror reversal of the
             * original entry: `ReverseJournalEntry` copies the amounts and
             * swaps the sides. Costing the stock side at the current average
             * instead put the two halves on different numbers whenever the
             * average had moved in between — write off 10 units at 10.00,
             * buy more at 20.00, void, and the entry restores 100.00 to the
             * inventory account while the shelf takes back 152.63. The
             * difference never closed.
             */
            $requests = $this->ledger->reversalRequests(
                movements: $this->ledger->movementsFor('inventory_adjustment', $adjustment->id),
                occurredOn: $date,
                sourceType: 'inventory_adjustment_void',
                sourceId: $adjustment->id,
                memo: 'Void of '.$adjustment->number,
            );

            /*
             * The journal sequence before the stock levels, because the
             * purchase and sales sides take them in that order and two orders
             * is a deadlock. See {@see DocumentNumberGenerator::reserve()}.
             */
            $this->numbers->reserve('journal');

            $planned = $requests === [] ? [] : $this->ledger->plan($requests);

            $reversalId = null;

            if ($adjustment->journal_entry_id !== null) {
                $reversal = $this->reverseJournalEntry->handle(
                    entry: $adjustment->journalEntry()->sole(),
                    actor: $actor,
                    date: $date,
                    reason: $reason ?? "Adjustment {$adjustment->number} voided",
                );

                $reversalId = $reversal->id;
            }

            if ($planned !== []) {
                $this->ledger->commit($planned, $reversalId, $actor);
            }

            $adjustment->forceFill([
                'status' => 'void',
                'void_journal_entry_id' => $reversalId,
                'voided_at' => Carbon::now(),
                'voided_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'inventory.adjustment_voided',
                subject: $adjustment,
                description: sprintf(
                    'Voided adjustment %s%s',
                    $adjustment->number,
                    $reason === null ? '' : " — {$reason}",
                ),
                new: ['reversal' => $reversalId],
                actor: $actor,
            );

            return $adjustment->refresh();
        });
    }
}
