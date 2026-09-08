<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * Accounts the machinery itself has to be able to find.
 *
 * Each organisation maps these roles onto real accounts during onboarding. The
 * posting rules reference the ROLE, never a code: an organisation may renumber
 * its chart of accounts, and the ledger has to keep working when it does.
 *
 * A system account cannot be deleted, and its type cannot change once anything
 * has been posted to it.
 *
 * @see ACCOUNTING_RULES.md §3
 */
enum SystemAccount: string
{
    case AccountsReceivable = 'accounts_receivable';
    case AccountsPayable = 'accounts_payable';
    case Inventory = 'inventory';
    case GstOutput = 'gst_output';
    case GstInput = 'gst_input';
    case WithholdingTaxReceivable = 'wht_receivable';
    case WithholdingTaxPayable = 'wht_payable';
    case CustomerAdvances = 'customer_advances';
    case VendorAdvances = 'vendor_advances';
    /*
     * What we owe our own people for money they spent on the business.
     *
     * Its own role rather than accounts payable: an employee is not a vendor,
     * and a payables ageing report full of staff expense claims would make
     * the vendor balances unreadable — while "what do we owe our staff" is a
     * figure somebody asks for on its own.
     */
    case EmployeeReimbursements = 'employee_reimbursements';
    case RetainedEarnings = 'retained_earnings';
    case CurrentYearEarnings = 'current_year_earnings';
    case FxGainLoss = 'fx_gain_loss';
    case RoundingDifference = 'rounding_difference';
    case OpeningBalanceEquity = 'opening_balance_equity';
    case CostOfGoodsSold = 'cogs';
    case SalesReturns = 'sales_returns';
    case TradeDiscounts = 'trade_discounts';

    public function label(): string
    {
        return match ($this) {
            self::AccountsReceivable => 'Accounts Receivable',
            self::AccountsPayable => 'Accounts Payable',
            self::Inventory => 'Inventory',
            self::GstOutput => 'GST Output Payable',
            self::GstInput => 'GST Input Receivable',
            self::WithholdingTaxReceivable => 'Withholding Tax Receivable',
            self::WithholdingTaxPayable => 'Withholding Tax Payable',
            self::CustomerAdvances => 'Customer Advances',
            self::VendorAdvances => 'Vendor Advances',
            self::RetainedEarnings => 'Retained Earnings',
            self::CurrentYearEarnings => 'Current Year Earnings',
            self::FxGainLoss => 'FX Gain and Loss',
            self::RoundingDifference => 'Rounding Difference',
            self::OpeningBalanceEquity => 'Opening Balance Equity',
            self::CostOfGoodsSold => 'Cost of Goods Sold',
            self::SalesReturns => 'Sales Returns',
            self::TradeDiscounts => 'Trade Discounts',
            self::EmployeeReimbursements => 'Employee Reimbursements',
        };
    }

    public function type(): AccountType
    {
        return match ($this) {
            self::AccountsReceivable,
            self::Inventory,
            self::GstInput,
            self::WithholdingTaxReceivable,
            self::VendorAdvances => AccountType::Asset,

            self::AccountsPayable,
            self::GstOutput,
            self::WithholdingTaxPayable,
            self::EmployeeReimbursements,
            self::CustomerAdvances => AccountType::Liability,

            self::RetainedEarnings,
            self::CurrentYearEarnings,
            self::OpeningBalanceEquity => AccountType::Equity,

            self::SalesReturns,
            self::TradeDiscounts,
            self::FxGainLoss => AccountType::Income,

            self::CostOfGoodsSold,
            self::RoundingDifference => AccountType::Expense,
        };
    }

    /**
     * Contra accounts sit under a type but move the opposite way.
     *
     * Sales returns and trade discounts are income accounts that get DEBITED,
     * which is what keeps gross revenue and the deductions from it separately
     * reportable instead of netted into a single figure.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::SalesReturns, self::TradeDiscounts => NormalBalance::Debit,
            default => $this->type()->normalBalance(),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
