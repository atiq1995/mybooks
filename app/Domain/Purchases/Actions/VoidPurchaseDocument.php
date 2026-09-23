<?php

declare(strict_types=1);

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\Actions\ReceiveStockForBill;
use App\Domain\Purchases\Enums\PurchaseDocumentStatus;
use App\Domain\Purchases\Exceptions\PurchaseDocumentRefused;
use App\Domain\Purchases\Models\PurchaseDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Void an approved purchase document.
 *
 * Never a delete. A voided bill keeps its number, its lines and its journal
 * entry, and gains a reversing entry alongside — so the pair reads as "this
 * was approved, then withdrawn", in the order it happened.
 *
 * A document with payments applied cannot be voided: the money would stay
 * allocated to something that no longer exists, and the vendor's balance
 * would stop agreeing with the ledger.
 *
 * @see ACCOUNTING_RULES.md §6, §4.15
 */
final readonly class VoidPurchaseDocument
{
    public function __construct(
        private ReverseJournalEntry $reverseJournalEntry,
        private ReceiveStockForBill $receiveStockForBill,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        PurchaseDocument $document,
        ?User $actor = null,
        ?string $reason = null,
        ?Carbon $date = null,
    ): PurchaseDocument {
        if ($document->status->isVoid()) {
            throw PurchaseDocumentRefused::alreadyVoid(
                $document->type->label(),
                $document->number,
            );
        }

        if (! $document->status->isIssued()) {
            throw PurchaseDocumentRefused::cannotVoidUnissued(
                $document->type->label(),
                $document->number,
            );
        }

        if (BigDecimal::of($document->amount_paid)->isPositive()) {
            throw PurchaseDocumentRefused::hasPayments(
                $document->type->label(),
                $document->number,
                $document->amount_paid,
            );
        }

        /*
         * One date, resolved once, used by both halves.
         *
         * This was a defect rather than a tidiness: the money and the goods
         * each defaulted a null date for themselves, and they defaulted
         * differently — the reversal to today, the stock reversal to the
         * document's own issue date. Since the screen never sends a date,
         * that was every void done by a real person. The two sides then
         * landed weeks apart, and the inventory account carried the goods
         * for the whole window in between while the stock report said they
         * were gone.
         */
        $date ??= Carbon::now();

        return DB::transaction(function () use (
            $document,
            $actor,
            $reason,
            $date,
        ): PurchaseDocument {
            $reversalId = null;

            /*
             * A purchase order never posted, so there is nothing to reverse —
             * voiding it is purely a status change. That asymmetry is §6's.
             */
            if ($document->journal_entry_id !== null) {
                $entry = JournalEntry::query()->findOrFail($document->journal_entry_id);

                $reversal = $this->reverseJournalEntry->handle(
                    entry: $entry,
                    actor: $actor,
                    date: $date,
                    reason: $reason ?? "Void of {$document->number}",
                );

                $reversalId = $reversal->id;
            }

            /*
             * The goods, which are a separate fact from the money.
             *
             * The reversal above credits the inventory account by what the
             * bill debited. Without taking the stock back off the shelf, the
             * control account goes to nil while the stock report still shows
             * the goods — I10 out by the whole bill, from this date on, with
             * nothing to point at. Reversed at the value the receipt carried,
             * so both halves move by one figure; refused if those goods have
             * since been sold, because then they are not there to give back.
             */
            $this->receiveStockForBill->unreceive(
                document: $document,
                voidedOn: $date,
                actor: $actor,
                reversalEntryId: $reversalId,
            );

            $document->forceFill([
                'status' => PurchaseDocumentStatus::Void,
                'void_journal_entry_id' => $reversalId,
                'voided_at' => Carbon::now(),
                'voided_by' => $actor?->getKey(),
            ])->save();

            // A voided vendor credit stops crediting the bill it credited.
            $this->releaseCredit($document);

            $this->audit->record(
                action: 'purchases.document_voided',
                subject: $document,
                description: sprintf(
                    'Voided %s %s%s',
                    $document->type->label(),
                    $document->number,
                    $reason === null ? '' : ": {$reason}",
                ),
                old: ['status' => 'approved'],
                new: [
                    'status' => PurchaseDocumentStatus::Void->value,
                    'reason' => $reason,
                    'reversal_entry' => $reversalId,
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * Give back the credit a voided vendor credit had taken off a bill.
     *
     * Without this the bill would stay marked as credited by a document that
     * has been withdrawn — so it would look settled while the ledger,
     * correctly, shows it owed again.
     */
    private function releaseCredit(PurchaseDocument $document): void
    {
        if ($document->credits_document_id === null) {
            return;
        }

        $bill = PurchaseDocument::query()->find($document->credits_document_id);

        if ($bill === null) {
            return;
        }

        $credited = BigDecimal::of($bill->amount_credited)
            ->minus(BigDecimal::of($document->total));

        $bill->forceFill([
            'amount_credited' => (string) ($credited->isNegative() ? BigDecimal::zero() : $credited)
                ->toScale(4),
        ])->save();

        $bill->refresh();

        // Back to whatever it now is: settled, part paid, or simply open.
        $bill->forceFill([
            'status' => match (true) {
                $bill->isSettled() => PurchaseDocumentStatus::Paid,
                BigDecimal::of($bill->amount_paid)->isPositive() => PurchaseDocumentStatus::PartiallyPaid,
                default => PurchaseDocumentStatus::Open,
            },
        ])->save();
    }
}
