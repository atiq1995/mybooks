<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Accounting\Actions\ReverseJournalEntry;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Void an approved expense.
 *
 * Never a delete: the pair of entries reads as "this was approved, then
 * withdrawn", in the order it happened. An approved expense that could be
 * deleted would leave a numbering gap and no explanation for it.
 *
 * An expense already rebilled to a customer is refused. Voiding it would
 * leave the customer invoiced for a cost the books say never happened, and
 * the invoice is the harder of the two to unwind — so the credit note comes
 * first.
 *
 * @see ACCOUNTING_RULES.md §6, §4.15
 */
final readonly class VoidExpense
{
    public function __construct(
        private ReverseJournalEntry $reverseJournalEntry,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        Expense $expense,
        ?User $actor = null,
        ?string $reason = null,
        ?Carbon $date = null,
    ): Expense {
        if ($expense->status->isVoid()) {
            throw ExpenseRefused::alreadyVoid($expense->number);
        }

        if (! $expense->status->isPosted()) {
            throw ExpenseRefused::cannotVoidUnapproved($expense->number);
        }

        if ($expense->billed_document_id !== null) {
            // The foreign key is nullOnDelete, so a non-null id means the
            // invoice is there.
            throw ExpenseRefused::alreadyBilled(
                $expense->number,
                $expense->billedDocument()->firstOrFail()->number,
            );
        }

        return DB::transaction(function () use ($expense, $actor, $reason, $date): Expense {
            $reversalId = null;

            if ($expense->journal_entry_id !== null) {
                $entry = JournalEntry::query()->findOrFail($expense->journal_entry_id);

                $reversal = $this->reverseJournalEntry->handle(
                    entry: $entry,
                    actor: $actor,
                    date: $date,
                    reason: $reason ?? "Void of expense {$expense->number}",
                );

                $reversalId = $reversal->getKey();
            }

            /*
             * `approved_at` is deliberately left in place.
             *
             * A check constraint ties it to `journal_entry_id`, and the
             * original entry survives a void — so clearing the approval would
             * break the constraint and, worse, would erase the fact that
             * somebody did approve this before it was withdrawn.
             */
            $expense->forceFill([
                'status' => ExpenseStatus::Void,
                'void_journal_entry_id' => $reversalId,
                'voided_at' => Carbon::now(),
                'voided_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'expenses.voided',
                subject: $expense,
                description: sprintf(
                    'Voided expense %s%s',
                    $expense->number,
                    $reason === null ? '' : ": {$reason}",
                ),
                old: ['status' => ExpenseStatus::Approved->value],
                new: [
                    'status' => ExpenseStatus::Void->value,
                    'reason' => $reason,
                    'reversal_entry' => $reversalId,
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }
}
