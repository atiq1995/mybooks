<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Data;

use App\Domain\Accounting\Data\JournalDraft;
use App\Domain\Accounting\Data\JournalLineDraft;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * The §4.8 posting rule for an expense, as a pure function.
 *
 *   Dr Expense or Asset          the cost, per account
 *   Dr GST Input Receivable      the tax we may actually reclaim
 *      Cr Bank or Cash           what left the account, OR
 *      Cr Employee Reimbursements what we now owe whoever paid
 *
 * The credit side is the only thing that varies, and it varies by ONE fact:
 * whose money was spent. Everything above the line is identical either way,
 * which is why this is one rule with a switched credit rather than two rules.
 *
 * NON-CLAIMABLE INPUT TAX IS CAPITALISED, exactly as in §4.6 — the caller has
 * already folded it into the cost. This matters more on expenses than
 * anywhere else, because expenses are where blocked input tax actually turns
 * up: entertainment, staff welfare, a car. Booking that tax as a receivable
 * would overstate assets and understate the cost of the very things most
 * likely to be questioned.
 *
 * @see ACCOUNTING_RULES.md §4.6, §4.8
 */
final readonly class ExpensePosting
{
    /**
     * @param  list<array{account_id: string, amount: string}>  $costLines
     *                                                                      one entry per line: where the cost lands, and the cost —
     *                                                                      net, plus any tax that could not be reclaimed
     * @param  list<array{account_id: string, amount: string}>  $claimableTaxes
     *                                                                           one entry per tax component, summed across the
     *                                                                           claimable lines only
     * @param  string  $creditAccountId  the bank or cash account for a
     *                                   company-paid expense, or the employee
     *                                   reimbursements account for one owed
     */
    public function __construct(
        public string $creditAccountId,
        public array $costLines,
        public array $claimableTaxes,
        public string $currency,
        public string $baseCurrency,
        public string $exchangeRate,
        public Carbon $date,
        public string $expenseId,
        public string $expenseNumber,
        public ?string $contactId = null,
        /** What the memo should call the payee — a merchant name, usually. */
        public ?string $payee = null,
    ) {}

    /**
     * Build the entry.
     *
     * The credit is derived from the debits rather than passed in, so the
     * entry balances by construction: what was spent is exactly the cost plus
     * the tax that was charged on it.
     */
    public function toDraft(): JournalDraft
    {
        $debits = [];
        $total = BigDecimal::zero();

        // Grouped by account: a three-line expense split across two
        // categories is two ledger lines, not three.
        $costByAccount = [];

        foreach ($this->costLines as $line) {
            $amount = BigDecimal::of($line['amount']);

            $costByAccount[$line['account_id']] = isset($costByAccount[$line['account_id']])
                ? $costByAccount[$line['account_id']]->plus($amount)
                : $amount;
        }

        foreach ($costByAccount as $accountId => $amount) {
            $amount = $amount->toScale(4, RoundingMode::HalfUp);

            if ($amount->isZero()) {
                continue;
            }

            $total = $total->plus($amount);

            $debits[] = JournalLineDraft::debit(
                accountId: (string) $accountId,
                amount: (string) $amount,
                memo: $this->memo(),
                contactId: $this->contactId,
            );
        }

        // Per component, because the input-tax return is filed per component.
        foreach ($this->claimableTaxes as $tax) {
            $amount = BigDecimal::of($tax['amount'])->toScale(4, RoundingMode::HalfUp);

            if ($amount->isZero()) {
                continue;
            }

            $total = $total->plus($amount);

            $debits[] = JournalLineDraft::debit(
                accountId: $tax['account_id'],
                amount: (string) $amount,
                memo: "Input tax — {$this->expenseNumber}",
                contactId: $this->contactId,
            );
        }

        $credits = [JournalLineDraft::credit(
            accountId: $this->creditAccountId,
            amount: (string) $total->toScale(4, RoundingMode::HalfUp),
            memo: $this->memo(),
            contactId: $this->contactId,
        )];

        return new JournalDraft(
            date: $this->date,
            currency: $this->currency,
            baseCurrency: $this->baseCurrency,
            exchangeRate: $this->exchangeRate,
            lines: [...$debits, ...$credits],
            // The expense is the source, so a retried approval is refused by
            // the idempotency index rather than posting the cost twice.
            source: ['expense', $this->expenseId, 'issue'],
            memo: $this->memo(),
        );
    }

    /**
     * Our number, and who was paid if we know.
     *
     * The merchant matters more here than on a bill: an expense's payee is
     * usually not a contact record, so if the name is not in the memo it is
     * nowhere in the ledger at all.
     */
    private function memo(): string
    {
        if ($this->payee === null || trim($this->payee) === '') {
            return $this->expenseNumber;
        }

        return "{$this->expenseNumber} · {$this->payee}";
    }
}
