<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Send a submitted expense back.
 *
 * Nothing posted, so nothing is reversed — the expense becomes editable again
 * and keeps its number. A rejection is a request for a change, not a verdict:
 * "wrong category", "no receipt", "this was personal". The reason is required,
 * by a check constraint as well as here, because a rejection with no reason
 * is a dead end for whoever submitted it.
 *
 * The expense is left as `rejected` rather than pushed straight back to
 * `draft`, so the person who claimed it can see that somebody looked. Editing
 * it is what returns it to draft, which {@see SaveExpense} does.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final readonly class RejectExpense
{
    public function __construct(
        private AuditRecorder $audit,
    ) {}

    public function handle(Expense $expense, string $reason, ?User $actor = null): Expense
    {
        if (! $expense->status->isSubmitted()) {
            throw ExpenseRefused::notSubmitted($expense->number, $expense->status->label());
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException(
                'Say why it is being rejected. A rejection with no reason leaves whoever '.
                'submitted it with nothing to act on.'
            );
        }

        return DB::transaction(function () use ($expense, $reason, $actor): Expense {
            $expense->forceFill([
                'status' => ExpenseStatus::Rejected,
                'rejected_at' => Carbon::now(),
                'rejected_by' => $actor?->getKey(),
                'rejection_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->audit->record(
                action: 'expenses.rejected',
                subject: $expense,
                description: "Rejected expense {$expense->number}: {$reason}",
                old: ['status' => ExpenseStatus::Submitted->value],
                new: [
                    'status' => ExpenseStatus::Rejected->value,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }
}
