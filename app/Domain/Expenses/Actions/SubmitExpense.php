<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Claim an expense.
 *
 * Nothing posts. Submitting only says "I am claiming this money back, or I
 * spent the company's money and here is what on" — and records WHO said it,
 * which is the fact the approval step later has to be different from.
 *
 * The receipt check happens here rather than at approval, deliberately: the
 * person who has the receipt is the person submitting, and telling them at
 * approval time means telling somebody else to go and ask them.
 *
 * @see ACCOUNTING_RULES.md §6
 */
final readonly class SubmitExpense
{
    public function __construct(
        private AuditRecorder $audit,
    ) {}

    public function handle(
        Expense $expense,
        ?User $actor = null,
        bool $requireReceipt = false,
    ): Expense {
        if ($expense->status === ExpenseStatus::Submitted) {
            throw ExpenseRefused::alreadySubmitted($expense->number);
        }

        if (! $expense->status->isEditable()) {
            throw ExpenseRefused::notEditable($expense->number, $expense->status->label());
        }

        if ($expense->lines()->count() === 0) {
            throw ExpenseRefused::hasNoLines($expense->number);
        }

        if (BigDecimal::of($expense->total)->isZero()) {
            throw ExpenseRefused::nothingToApprove($expense->number);
        }

        if ($requireReceipt && ! $expense->hasReceipt()) {
            throw ExpenseRefused::receiptRequired($expense->number);
        }

        return DB::transaction(function () use ($expense, $actor): Expense {
            $expense->forceFill([
                'status' => ExpenseStatus::Submitted,
                'submitted_at' => Carbon::now(),
                'submitted_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'expenses.submitted',
                subject: $expense,
                description: sprintf(
                    'Submitted expense %s for approval — %s %s',
                    $expense->number,
                    $expense->currency,
                    $expense->total,
                ),
                new: [
                    'number' => $expense->number,
                    'total' => $expense->total,
                    'has_receipt' => $expense->hasReceipt(),
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }
}
