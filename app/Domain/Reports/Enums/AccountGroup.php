<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

use App\Domain\Accounting\Enums\AccountType;

/**
 * Where an account belongs on the financial statements.
 *
 * ONE classification, used by the profit and loss, the balance sheet and the
 * cash flow alike. That is the whole reason it exists: three statements each
 * deciding for themselves what "a current asset" means is how a set of
 * reports comes to disagree with itself, and the disagreement is always found
 * by somebody outside the business.
 *
 * It is derived from the account's `subtype`, which the chart of accounts
 * sets when it is created. A missing subtype falls back to the account TYPE —
 * the conservative reading in each case, and never a silent exclusion: an
 * account with no subtype still appears somewhere on every statement.
 *
 * @see App\Domain\Accounting\Services\ChartOfAccountsTemplate
 */
enum AccountGroup: string
{
    // Profit and loss, in the order it reads.
    case Revenue = 'revenue';
    case ContraRevenue = 'contra_revenue';
    case CostOfSales = 'cost_of_sales';
    case OperatingExpense = 'operating_expense';
    case OtherIncome = 'other_income';
    case OtherExpense = 'other_expense';

    // Balance sheet.
    case Cash = 'cash';
    case Receivable = 'receivable';
    case Inventory = 'inventory';
    case OtherCurrentAsset = 'other_current_asset';
    case FixedAsset = 'fixed_asset';
    case Payable = 'payable';
    case OtherCurrentLiability = 'other_current_liability';
    case LongTermLiability = 'long_term_liability';
    case Equity = 'equity';

    /**
     * The group an account belongs to.
     */
    public static function for(AccountType $type, ?string $subtype): self
    {
        return match ($subtype) {
            'operating_revenue' => self::Revenue,
            'contra_revenue' => self::ContraRevenue,
            'other_revenue' => self::OtherIncome,
            'cost_of_sales' => self::CostOfSales,
            'operating_expense' => self::OperatingExpense,
            'other_expense' => self::OtherExpense,
            'cash', 'bank' => self::Cash,
            'receivable' => self::Receivable,
            'inventory' => self::Inventory,
            /*
             * Tax receivable and tax payable are working capital like any
             * other, and they belong with the current items rather than in a
             * section of their own: a balance sheet that gives GST its own
             * heading tells a reader it is special, and it is not — it is
             * money held for somebody else, due within the quarter.
             */
            'current_asset', 'tax_receivable' => self::OtherCurrentAsset,
            'fixed_asset' => self::FixedAsset,
            'payable' => self::Payable,
            'current_liability', 'tax_payable' => self::OtherCurrentLiability,
            'long_term_liability' => self::LongTermLiability,
            'equity' => self::Equity,
            /*
             * No subtype. An account still has to land somewhere — leaving it
             * off a statement would misstate the statement rather than the
             * account — so the type decides, conservatively: an unclassified
             * asset is current, an unclassified cost is operating.
             */
            default => match ($type) {
                AccountType::Asset => self::OtherCurrentAsset,
                AccountType::Liability => self::OtherCurrentLiability,
                AccountType::Equity => self::Equity,
                AccountType::Income => self::Revenue,
                AccountType::Expense => self::OperatingExpense,
            },
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue',
            self::ContraRevenue => 'Less: returns and discounts',
            self::CostOfSales => 'Cost of sales',
            self::OperatingExpense => 'Operating expenses',
            self::OtherIncome => 'Other income',
            self::OtherExpense => 'Other expenses',
            self::Cash => 'Cash and bank',
            self::Receivable => 'Receivables',
            self::Inventory => 'Inventory',
            self::OtherCurrentAsset => 'Other current assets',
            self::FixedAsset => 'Non-current assets',
            self::Payable => 'Payables',
            self::OtherCurrentLiability => 'Other current liabilities',
            self::LongTermLiability => 'Non-current liabilities',
            self::Equity => 'Equity',
        };
    }

    /** Whether this group appears on the profit and loss rather than the balance sheet. */
    public function isProfitAndLoss(): bool
    {
        return in_array($this, [
            self::Revenue,
            self::ContraRevenue,
            self::CostOfSales,
            self::OperatingExpense,
            self::OtherIncome,
            self::OtherExpense,
        ], true);
    }

    /**
     * How a movement on this group is classified on the cash flow statement.
     *
     * Working capital — receivables, payables, inventory and the other
     * current items — is OPERATING: a sale not yet collected is profit
     * without cash, and the whole point of the statement is to show the
     * difference. Cash itself is the thing being explained, so it classifies
     * as `cash` and is never an adjustment.
     */
    public function cashFlowSection(): CashFlowSection
    {
        return match ($this) {
            self::Cash => CashFlowSection::Cash,
            self::Receivable,
            self::Inventory,
            self::OtherCurrentAsset,
            self::Payable,
            self::OtherCurrentLiability => CashFlowSection::Operating,
            self::FixedAsset => CashFlowSection::Investing,
            self::LongTermLiability, self::Equity => CashFlowSection::Financing,
            // Everything on the profit and loss is already inside the profit
            // figure the statement starts from.
            default => CashFlowSection::Profit,
        };
    }

    /**
     * Sort order within a statement.
     */
    public function order(): int
    {
        return match ($this) {
            self::Revenue => 10,
            self::ContraRevenue => 20,
            self::CostOfSales => 30,
            self::OperatingExpense => 40,
            self::OtherIncome => 50,
            self::OtherExpense => 60,

            self::Cash => 110,
            self::Receivable => 120,
            self::Inventory => 130,
            self::OtherCurrentAsset => 140,
            self::FixedAsset => 150,
            self::Payable => 210,
            self::OtherCurrentLiability => 220,
            self::LongTermLiability => 230,
            self::Equity => 310,
        };
    }
}
