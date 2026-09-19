<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Reports\Enums\AccountGroup;
use Brick\Math\BigDecimal;

/**
 * One account's totals over a span, in base currency.
 *
 * Every report in the system is built from these and nothing else, which is
 * what makes them reconcile to each other by construction rather than by
 * being checked afterwards.
 *
 * Three figures, and the difference between them matters:
 *
 *   `debit` / `credit`  the raw sums, which is what a trial balance shows;
 *   `net`               debit − credit, the account's position in ledger
 *                       terms, negative for a credit balance;
 *   `signed()`          the same position read the way the account is meant
 *                       to be read — revenue of 100,000 is a positive
 *                       100,000 on a profit and loss, not a negative one.
 */
final readonly class AccountBalance
{
    public function __construct(
        public string $accountId,
        public string $code,
        public string $name,
        public AccountType $type,
        public ?string $subtype,
        public NormalBalance $normalBalance,
        /** Decimal string. */
        public string $debit,
        /** Decimal string. */
        public string $credit,
    ) {}

    public function group(): AccountGroup
    {
        return AccountGroup::for($this->type, $this->subtype);
    }

    /** Debit less credit: positive for a debit balance. */
    public function net(): BigDecimal
    {
        return BigDecimal::of($this->debit)->minus(BigDecimal::of($this->credit));
    }

    /**
     * The balance read in the account's own direction.
     *
     * A credit-normal account returns credit less debit, so revenue, payables
     * and equity all read positive when they are what they should be. Without
     * this every report would carry a minus sign in front of half its lines
     * and the reader would have to hold the convention in their head.
     */
    public function signed(): BigDecimal
    {
        return $this->normalBalance === NormalBalance::Credit
            ? $this->net()->negated()
            : $this->net();
    }

    /**
     * The balance signed by the account's TYPE rather than its own direction.
     *
     * The distinction matters exactly once, and it matters a great deal: a
     * CONTRA account is one whose normal balance runs against its type —
     * accumulated depreciation is an asset that carries a credit balance.
     *
     * On a profit and loss, {@see signed()} is what is wanted: sales returns
     * read as a positive 20,000 that is then deducted, which is how the
     * statement is written.
     *
     * On a balance sheet, that same reading would ADD accumulated
     * depreciation to non-current assets instead of subtracting it, and the
     * sheet would be out by twice the accumulated charge. Here every asset
     * reads debit-positive and every liability and equity account
     * credit-positive, whatever an individual account's own direction, so
     * contra accounts subtract themselves and the two sides agree.
     */
    public function forStatement(): BigDecimal
    {
        return match ($this->type) {
            AccountType::Asset, AccountType::Expense => $this->net(),
            AccountType::Liability, AccountType::Equity, AccountType::Income => $this->net()->negated(),
        };
    }

    public function isZero(): bool
    {
        return $this->net()->isZero();
    }
}
