<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Accounting\Actions\PostJournalEntry;
use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Enums\SystemAccount;
use App\Domain\Accounting\Exceptions\PostingRefused;
use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Expenses\Data\ExpensePosting;
use App\Domain\Expenses\Enums\ExpensePaymentMode;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Expenses\Models\ExpenseLineTax;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approve a submitted expense, which posts it.
 *
 * The one place in this module where money moves in the books, and the one
 * place with a rule that has nothing to do with accounting:
 *
 * THE APPROVER CANNOT BE THE SUBMITTER. Without that, the workflow is two
 * clicks by the same person — which is not a control, it is a formality that
 * makes the books look reviewed when they are not. It is enforced here, in
 * the domain, rather than only in the controller, because an import or an API
 * call has to hit it too.
 *
 * The posting rule itself lives in {@see ExpensePosting}, so §4.8 can be
 * asserted line by line without a database.
 *
 * @see ACCOUNTING_RULES.md §4.6, §4.8, §6
 */
final readonly class ApproveExpense
{
    public function __construct(
        private TenantContext $tenant,
        private PostJournalEntry $postJournalEntry,
        private AuditRecorder $audit,
    ) {}

    public function handle(
        Expense $expense,
        ?User $actor = null,
        bool $allowClosedPeriod = false,
        bool $allowSelfApproval = false,
    ): Expense {
        if ($expense->status === ExpenseStatus::Approved) {
            throw ExpenseRefused::alreadyApproved($expense->number);
        }

        if (! $expense->status->isSubmitted()) {
            throw ExpenseRefused::notSubmitted($expense->number, $expense->status->label());
        }

        /*
         * The separation of duties.
         *
         * `allowSelfApproval` exists for the one-person business, where there
         * is nobody else to ask and refusing outright would make the module
         * unusable. It is a deliberate decision by whoever configures the
         * organisation, not a default, and the audit row records that the
         * approval was a self-approval either way.
         */
        if (! $allowSelfApproval
            && $actor !== null
            && $expense->submitted_by === $actor->getKey()) {
            throw ExpenseRefused::cannotApproveOwn($expense->number);
        }

        if (BigDecimal::of($expense->total)->isZero()) {
            throw ExpenseRefused::nothingToApprove($expense->number);
        }

        return DB::transaction(function () use ($expense, $actor, $allowClosedPeriod): Expense {
            $entry = $this->postJournalEntry->handle(
                draft: $this->draft($expense),
                actor: $actor,
                allowClosedPeriod: $allowClosedPeriod,
            );

            $expense->forceFill([
                'status' => ExpenseStatus::Approved,
                'journal_entry_id' => $entry->getKey(),
                'approved_at' => Carbon::now(),
                'approved_by' => $actor?->getKey(),
            ])->save();

            $this->audit->record(
                action: 'expenses.approved',
                subject: $expense,
                description: sprintf(
                    'Approved expense %s — %s %s, %s',
                    $expense->number,
                    $expense->currency,
                    $expense->total,
                    $expense->payment_mode === ExpensePaymentMode::Reimbursable
                        ? 'owed back to whoever paid'
                        : 'paid from a company account',
                ),
                new: [
                    'number' => $expense->number,
                    'total' => $expense->total,
                    'total_base' => $expense->total_base,
                    'tax_claimable' => $expense->tax_claimable_total,
                    'journal_entry' => $entry->entry_no,
                    // Recorded so a self-approval is visible in the trail
                    // even where the organisation permits it.
                    'self_approved' => $expense->submitted_by !== null
                        && $expense->submitted_by === $actor?->getKey(),
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    /**
     * The §4.8 figures.
     *
     * The cost per line is the taxable amount plus any tax that cannot be
     * reclaimed; the claimable tax is summed per account across the claimable
     * lines only. Together they are exactly what was spent, which is what
     * makes the credit derivable rather than passed in.
     */
    private function draft(Expense $expense): JournalDraft
    {
        $organization = $this->tenant->organization();

        $costLines = array_values(
            $expense->lines()->get()
                ->map(fn (ExpenseLine $line): array => [
                    'account_id' => $line->debit_account_id,
                    'amount' => $line->capitalisedCost(),
                ])
                ->all(),
        );

        return (new ExpensePosting(
            creditAccountId: $this->creditAccountId($expense),
            costLines: $costLines,
            claimableTaxes: $this->claimableTaxesByAccount($expense),
            currency: $expense->currency,
            baseCurrency: $organization->base_currency,
            exchangeRate: $expense->exchange_rate,
            date: Carbon::parse($expense->expense_date->toDateString()),
            expenseId: $expense->id,
            expenseNumber: $expense->number,
            contactId: $expense->contact_id,
            payee: $expense->merchant,
        ))->toDraft();
    }

    /**
     * What gets credited: the bank, or the person who paid.
     *
     * The single fact that separates the two halves of §4.8, and the reason
     * `payment_mode` is a stored column rather than an inference.
     */
    private function creditAccountId(Expense $expense): string
    {
        if ($expense->payment_mode === ExpensePaymentMode::Reimbursable) {
            return $this->systemAccountId(SystemAccount::EmployeeReimbursements);
        }

        $accountId = $expense->paid_through_account_id;

        if (! is_string($accountId)) {
            // The constraint makes this unreachable through the application;
            // it is here so a row written around it fails with a sentence.
            throw ExpenseRefused::needsPaymentAccount();
        }

        return $accountId;
    }

    /**
     * Claimable input tax, summed per receivable account.
     *
     * The non-claimable rows are skipped and capitalised into the cost
     * instead — §4.6. On expenses this is the common case rather than the
     * exception, which is why it is checked per line.
     *
     * @return list<array{account_id: string, amount: string}>
     */
    private function claimableTaxesByAccount(Expense $expense): array
    {
        $totals = [];

        foreach ($expense->lineTaxes()->get() as $lineTax) {
            /** @var ExpenseLineTax $lineTax */
            if (! $lineTax->is_claimable) {
                continue;
            }

            $accountId = $lineTax->account_id ?? $this->systemAccountId(SystemAccount::GstInput);

            $totals[$accountId] = isset($totals[$accountId])
                ? $totals[$accountId]->plus(BigDecimal::of($lineTax->tax_amount))
                : BigDecimal::of($lineTax->tax_amount);
        }

        $taxes = [];

        foreach ($totals as $accountId => $amount) {
            $taxes[] = [
                'account_id' => (string) $accountId,
                'amount' => (string) $amount->toScale(4),
            ];
        }

        return $taxes;
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
