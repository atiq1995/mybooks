<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\DocumentNumberGenerator;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Expenses\Enums\ExpensePaymentMode;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Exceptions\ExpenseRefused;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Expenses\Models\MileageRate;
use App\Domain\Expenses\Services\ExpenseCalculator;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or replace an expense that has not been approved.
 *
 * Editable while it is a draft OR after it has been rejected — a rejection is
 * usually "wrong category" or "no receipt", both fixed by editing, and
 * forcing a fresh record would lose the history of having asked.
 *
 * A MILEAGE line is filled in here rather than by the caller: the distance is
 * what somebody typed, and the rate is looked up as at the expense's date and
 * COPIED onto the line. A rate that changes in April must not restate March,
 * and the only way to guarantee that is to store the figure that was claimed.
 *
 * @see ACCOUNTING_RULES.md §4.8, §5, §6
 */
final readonly class SaveExpense
{
    public function __construct(
        private TenantContext $tenant,
        private DocumentNumberGenerator $numbers,
        private ExpenseCalculator $calculator,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function handle(
        array $attributes,
        array $lines,
        ?Expense $expense = null,
        ?User $actor = null,
    ): Expense {
        if ($expense !== null && ! $expense->status->isEditable()) {
            throw ExpenseRefused::notEditable($expense->number, $expense->status->label());
        }

        $organization = $this->tenant->organization();

        $mode = ExpensePaymentMode::from(
            self::optionalText($attributes, 'payment_mode') ?? ExpensePaymentMode::Company->value,
        );

        $paidThrough = self::optionalText($attributes, 'paid_through_account_id');
        $reimburseUser = self::optionalText($attributes, 'reimburse_user_id');

        /*
         * The two modes are checked here, not only by the constraint. The
         * constraint stops a bad row reaching the table; this stops the user
         * seeing a database error where a sentence would do.
         */
        if ($mode === ExpensePaymentMode::Company && $paidThrough === null) {
            throw ExpenseRefused::needsPaymentAccount();
        }

        if ($mode === ExpensePaymentMode::Reimbursable && $reimburseUser === null) {
            throw ExpenseRefused::needsPayee();
        }

        $expenseDate = Carbon::parse(self::text($attributes, 'expense_date'));

        $contact = null;
        $contactId = self::optionalText($attributes, 'contact_id');

        if ($contactId !== null) {
            $contact = Contact::query()->find($contactId);
        }

        return DB::transaction(function () use (
            $attributes,
            $lines,
            $expense,
            $actor,
            $organization,
            $mode,
            $paidThrough,
            $reimburseUser,
            $expenseDate,
            $contact,
        ): Expense {
            $isNew = $expense === null;

            if ($isNew) {
                $expense = new Expense;

                $expense->forceFill([
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $organization->getKey(),
                    'number' => $this->numbers->next('expense', $expenseDate),
                    'status' => ExpenseStatus::Draft,
                    'created_by' => $actor?->getKey(),
                ]);
            }

            /** @var Expense $expense */
            $expense->forceFill([
                'contact_id' => $contact?->getKey(),
                'merchant' => self::optionalText($attributes, 'merchant')
                    ?? $contact?->display_name,
                'expense_date' => $expenseDate->toDateString(),
                'payment_mode' => $mode,
                'paid_through_account_id' => $mode === ExpensePaymentMode::Company
                    ? $paidThrough
                    : null,
                'reimburse_user_id' => $mode === ExpensePaymentMode::Reimbursable
                    ? $reimburseUser
                    : null,
                'reference' => self::optionalText($attributes, 'reference'),
                'notes' => self::optionalText($attributes, 'notes'),
                'prices_include_tax' => (bool) ($attributes['prices_include_tax'] ?? false),
                'currency' => self::optionalText($attributes, 'currency')
                    ?? $organization->base_currency,
                'exchange_rate' => self::optionalText($attributes, 'exchange_rate') ?? '1',
                'is_billable' => (bool) ($attributes['is_billable'] ?? false),
                'billable_contact_id' => (bool) ($attributes['is_billable'] ?? false)
                    ? self::optionalText($attributes, 'billable_contact_id')
                    : null,
                /*
                 * Editing a rejected expense puts it back to draft, and
                 * clears the rejection.
                 *
                 * Otherwise it would stay marked as rejected while showing
                 * different figures — so the reviewer's note would be about a
                 * version that no longer exists.
                 */
                'status' => $expense->status === ExpenseStatus::Rejected
                    ? ExpenseStatus::Draft
                    : $expense->status,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
            ])->save();

            $this->replaceLines($expense, $lines, $expenseDate);

            $this->calculator->recalculate($expense);

            $this->audit->record(
                action: $isNew ? 'expenses.created' : 'expenses.updated',
                subject: $expense,
                description: sprintf(
                    'Expense %s — %s %s%s',
                    $expense->number,
                    $expense->currency,
                    $expense->total,
                    $expense->merchant === null ? '' : " at {$expense->merchant}",
                ),
                new: [
                    'number' => $expense->number,
                    'merchant' => $expense->merchant,
                    'total' => $expense->total,
                    'payment_mode' => $expense->payment_mode->value,
                    'lines' => count($lines),
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(Expense $expense, array $lines, Carbon $on): void
    {
        ExpenseLine::query()->where('expense_id', $expense->id)->delete();

        $defaultExpense = $this->defaultExpenseAccountId();

        $lineNo = 1;

        foreach ($lines as $input) {
            $kind = self::optionalText($input, 'kind') === 'mileage' ? 'mileage' : 'amount';

            $unit = null;
            $rateId = null;
            $quantity = self::optionalText($input, 'quantity') ?? '1';
            $unitPrice = self::optionalText($input, 'unit_price') ?? '0';

            if ($kind === 'mileage') {
                $unit = self::optionalText($input, 'unit') === 'mi' ? 'mi' : 'km';

                /*
                 * The distance is what was typed; the rate is looked up as at
                 * the expense's own date. Where the caller supplied a rate it
                 * is respected — an unusual claim is sometimes agreed at a
                 * different figure — but the default comes from history, not
                 * from today.
                 */
                $quantity = self::optionalText($input, 'distance')
                    ?? self::optionalText($input, 'quantity')
                    ?? '0';

                $supplied = self::optionalText($input, 'unit_price');

                if ($supplied === null) {
                    $rate = MileageRate::inForce($on, $unit);

                    if ($rate === null) {
                        throw ExpenseRefused::noMileageRate($unit);
                    }

                    $unitPrice = $rate->rate;
                    $rateId = $rate->id;
                } else {
                    $unitPrice = $supplied;
                    $rateId = self::optionalText($input, 'mileage_rate_id');
                }
            }

            $line = new ExpenseLine;

            $line->forceFill([
                'id' => (string) Str::uuid7(),
                'organization_id' => $expense->organization_id,
                'expense_id' => $expense->id,
                'line_no' => $lineNo++,
                'kind' => $kind,
                'description' => self::optionalText($input, 'description') ?? '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit' => $unit,
                'mileage_rate_id' => $rateId,
                'tax_id' => self::optionalText($input, 'tax_id'),
                'debit_account_id' => self::optionalText($input, 'debit_account_id')
                    ?? $defaultExpense,
                'tax_is_claimable' => ! array_key_exists('tax_is_claimable', $input)
                    || (bool) $input['tax_is_claimable'],
                'project_id' => self::optionalText($input, 'project_id'),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function text(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("An expense needs a {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function optionalText(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * Where a cost lands when the line does not say.
     *
     * The first ordinary expense account by code. Not a system account: an
     * organisation has many expense accounts and none of them is "the" one.
     */
    private function defaultExpenseAccountId(): string
    {
        $account = Account::query()
            ->postable()
            ->where('type', 'expense')
            ->whereNull('system_role')
            ->orderBy('code')
            ->first();

        if ($account !== null) {
            return $account->id;
        }

        // An unfinished chart. Fall back to something that exists so the
        // failure surfaces at approval with a message about the chart.
        return Account::query()->postable()->orderBy('code')->sole()->id;
    }
}
