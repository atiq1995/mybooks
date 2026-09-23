<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Access\Enums\Permission;
use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Inventory\Actions\ShipSalesDocument;
use App\Domain\Sales\Enums\SalesDocumentStatus;
use App\Domain\Sales\Exceptions\SalesDocumentRefused;
use App\Domain\Sales\Models\SalesDocument;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Void an issued document.
 *
 * Never a delete. A voided invoice keeps its number, its lines and its
 * journal entry, and gains a reversing entry alongside — so the pair reads as
 * "this was issued, and then withdrawn", in the order it happened. Deleting
 * it would leave a gap in the numbering, which many tax authorities read as a
 * concealed invoice.
 *
 * A document with payments applied cannot be voided. Voiding it would leave
 * money allocated to something that no longer exists, and the customer's
 * balance would stop agreeing with the ledger. Refund or unallocate first.
 *
 * @see ACCOUNTING_RULES.md §6, §4.15
 */
final readonly class VoidSalesDocument
{
    public function __construct(
        private ReverseJournalEntry $reverseJournalEntry,
        private ShipSalesDocument $shipSalesDocument,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        SalesDocument $document,
        ?User $actor = null,
        ?string $reason = null,
        ?Carbon $date = null,
    ): SalesDocument {
        if ($document->status->isVoid()) {
            throw SalesDocumentRefused::alreadyVoid($document->type->label(), $document->number);
        }

        if (! $document->status->isIssued()) {
            throw SalesDocumentRefused::cannotVoidUnissued(
                $document->type->label(),
                $document->number,
            );
        }

        if (BigDecimal::of($document->amount_paid)->isPositive()) {
            throw SalesDocumentRefused::hasPayments(
                $document->type->label(),
                $document->number,
                $document->amount_paid,
            );
        }

        /*
         * One date, resolved once, used by the sale, the cost and the goods.
         *
         * Left null, each half defaulted for itself and they defaulted
         * differently — the entry reversals to today, the stock reversal to
         * the document's issue date — so a void done from the screen, which
         * sends no date, reversed the revenue in one period and the goods in
         * another.
         */
        $date ??= Carbon::now();

        return DB::transaction(function () use ($document, $actor, $reason, $date): SalesDocument {
            $reversalId = null;

            /*
             * A commitment document never posted, so there is nothing to
             * reverse — voiding it is purely a status change. That asymmetry
             * is §6's, not a special case here.
             */
            if ($document->journal_entry_id !== null) {
                $entry = JournalEntry::query()->findOrFail($document->journal_entry_id);

                $reversal = $this->reverseJournalEntry->handle(
                    entry: $entry,
                    actor: $actor,
                    date: $date,
                    reason: $reason ?? "Void of {$document->number}",
                );

                $reversalId = $reversal->getKey();
            }

            /*
             * The goods, which are a separate fact from the money.
             *
             * An invoice that despatched stock posted a second entry for the
             * cost and took units off the shelf. Reversing only the sale left
             * the cost charged and the shelf short — the inventory account
             * and the stock report disagreeing by the cost of the goods,
             * permanently, from this date on. This puts both back at the cost
             * they left at, and refuses the void outright if those goods have
             * since been sold on.
             */
            $this->shipSalesDocument->unship(
                document: $document,
                voidedOn: $date,
                actor: $actor,
                reason: $reason,
            );

            $document->forceFill([
                'status' => SalesDocumentStatus::Void,
                'void_journal_entry_id' => $reversalId,
                'voided_at' => Carbon::now(),
                'voided_by' => $actor?->getKey(),
            ])->save();

            // A voided credit note stops crediting the invoice it credited.
            $this->releaseCredit($document);

            $this->audit->record(
                action: 'sales.document_voided',
                subject: $document,
                description: sprintf(
                    'Voided %s %s%s',
                    $document->type->label(),
                    $document->number,
                    $reason === null ? '' : ": {$reason}",
                ),
                old: ['status' => 'issued'],
                new: [
                    'status' => SalesDocumentStatus::Void->value,
                    'reason' => $reason,
                    'reversal_entry' => $reversalId,
                ],
                actor: $actor,
            );

            return $document->refresh();
        });
    }

    /**
     * Give back the credit a voided credit note had taken off an invoice.
     *
     * Without this the invoice would stay marked as credited by a document
     * that has been withdrawn — so it would look settled while the ledger,
     * correctly, shows it outstanding again.
     */
    private function releaseCredit(SalesDocument $document): void
    {
        if ($document->credits_document_id === null) {
            return;
        }

        $invoice = SalesDocument::query()->find($document->credits_document_id);

        if ($invoice === null) {
            return;
        }

        $credited = BigDecimal::of($invoice->amount_credited)
            ->minus(BigDecimal::of($document->total));

        $invoice->forceFill([
            'amount_credited' => (string) ($credited->isNegative() ? BigDecimal::zero() : $credited)
                ->toScale(4),
        ])->save();

        $invoice->refresh();

        // Back to whatever it now is: settled, part paid, or simply sent.
        $invoice->forceFill([
            'status' => match (true) {
                $invoice->isSettled() => SalesDocumentStatus::Paid,
                BigDecimal::of($invoice->amount_paid)->isPositive() => SalesDocumentStatus::PartiallyPaid,
                default => SalesDocumentStatus::Sent,
            },
        ])->save();
    }

    public static function permission(): Permission
    {
        return Permission::SalesDelete;
    }
}
