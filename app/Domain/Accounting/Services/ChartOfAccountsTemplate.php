<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Enums\SystemAccount;

/**
 * The starting chart of accounts for a new organisation.
 *
 * Codes follow the convention accountants expect: 1000s assets, 2000s
 * liabilities, 3000s equity, 4000s income, 5000s cost of sales, 6000-9000s
 * expenses. An organisation is free to renumber afterwards, which is why the
 * posting rules reference system ROLES rather than these codes.
 *
 * Pakistan-shaped: GST output and input are separate liability and asset
 * accounts, and withholding tax appears on both sides — tax withheld BY a
 * customer from us is a receivable, tax withheld BY us from a vendor is a
 * payable. Those are the default case here, not edge cases.
 *
 * @see ACCOUNTING_RULES.md §3
 */
final class ChartOfAccountsTemplate
{
    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     type: AccountType,
     *     subtype: string|null,
     *     normal_balance: NormalBalance|null,
     *     system_role: SystemAccount|null,
     *     is_header: bool,
     * }>
     */
    public static function forJurisdiction(string $jurisdiction): array
    {
        // Only PK ships a tailored chart today; everything else starts from
        // the same structure without the local tax accounts.
        return $jurisdiction === 'PK' ? self::pakistan() : self::generic();
    }

    /**
     * @return list<array{code: string, name: string, type: AccountType, subtype: string|null, normal_balance: NormalBalance|null, system_role: SystemAccount|null, is_header: bool}>
     */
    private static function pakistan(): array
    {
        return array_merge(self::generic(), [
            self::account('2300', 'GST Output Payable', AccountType::Liability, 'tax_payable', role: SystemAccount::GstOutput),
            self::account('1400', 'GST Input Receivable', AccountType::Asset, 'tax_receivable', role: SystemAccount::GstInput),
            self::account('1450', 'Withholding Tax Receivable', AccountType::Asset, 'tax_receivable', role: SystemAccount::WithholdingTaxReceivable),
            self::account('2350', 'Withholding Tax Payable', AccountType::Liability, 'tax_payable', role: SystemAccount::WithholdingTaxPayable),
        ]);
    }

    /**
     * @return list<array{code: string, name: string, type: AccountType, subtype: string|null, normal_balance: NormalBalance|null, system_role: SystemAccount|null, is_header: bool}>
     */
    private static function generic(): array
    {
        return [
            // -- Assets ------------------------------------------------
            self::header('1000', 'Assets', AccountType::Asset),
            self::account('1010', 'Cash on Hand', AccountType::Asset, 'cash'),
            self::account('1020', 'Bank Account', AccountType::Asset, 'bank'),
            self::account('1200', 'Accounts Receivable', AccountType::Asset, 'receivable', role: SystemAccount::AccountsReceivable),
            self::account('1250', 'Vendor Advances', AccountType::Asset, 'receivable', role: SystemAccount::VendorAdvances),
            self::account('1300', 'Inventory', AccountType::Asset, 'inventory', role: SystemAccount::Inventory),
            self::account('1500', 'Prepaid Expenses', AccountType::Asset, 'current_asset'),
            self::account('1700', 'Property, Plant and Equipment', AccountType::Asset, 'fixed_asset'),
            self::account('1750', 'Accumulated Depreciation', AccountType::Asset, 'fixed_asset', normalBalance: NormalBalance::Credit),

            // -- Liabilities -------------------------------------------
            self::header('2000', 'Liabilities', AccountType::Liability),
            self::account('2100', 'Accounts Payable', AccountType::Liability, 'payable', role: SystemAccount::AccountsPayable),
            self::account('2200', 'Customer Advances', AccountType::Liability, 'payable', role: SystemAccount::CustomerAdvances),
            self::account('2400', 'Accrued Liabilities', AccountType::Liability, 'current_liability'),
            self::account('2700', 'Long-term Loans', AccountType::Liability, 'long_term_liability'),

            // -- Equity ------------------------------------------------
            self::header('3000', 'Equity', AccountType::Equity),
            self::account('3100', 'Opening Balance Equity', AccountType::Equity, 'equity', role: SystemAccount::OpeningBalanceEquity),
            self::account('3200', 'Retained Earnings', AccountType::Equity, 'equity', role: SystemAccount::RetainedEarnings),
            self::account('3300', 'Current Year Earnings', AccountType::Equity, 'equity', role: SystemAccount::CurrentYearEarnings),
            self::account('3400', 'Owner Contributions', AccountType::Equity, 'equity'),
            self::account('3500', 'Owner Drawings', AccountType::Equity, 'equity', normalBalance: NormalBalance::Debit),

            // -- Income ------------------------------------------------
            self::header('4000', 'Income', AccountType::Income),
            self::account('4010', 'Sales Revenue', AccountType::Income, 'operating_revenue'),
            self::account('4100', 'Service Revenue', AccountType::Income, 'operating_revenue'),
            self::account('4700', 'Other Income', AccountType::Income, 'other_revenue'),
            // Contra-revenue: income accounts that are DEBITED, so gross
            // sales and the deductions from it stay separately reportable.
            self::account('4800', 'Sales Returns', AccountType::Income, 'contra_revenue', normalBalance: NormalBalance::Debit, role: SystemAccount::SalesReturns),
            self::account('4900', 'Trade Discounts', AccountType::Income, 'contra_revenue', normalBalance: NormalBalance::Debit, role: SystemAccount::TradeDiscounts),

            // -- Cost of sales -----------------------------------------
            self::header('5000', 'Cost of Sales', AccountType::Expense),
            self::account('5010', 'Cost of Goods Sold', AccountType::Expense, 'cost_of_sales', role: SystemAccount::CostOfGoodsSold),
            self::account('5100', 'Freight and Delivery', AccountType::Expense, 'cost_of_sales'),

            // -- Operating expenses ------------------------------------
            self::header('6000', 'Operating Expenses', AccountType::Expense),
            self::account('6010', 'Salaries and Wages', AccountType::Expense, 'operating_expense'),
            self::account('6100', 'Rent', AccountType::Expense, 'operating_expense'),
            self::account('6200', 'Utilities', AccountType::Expense, 'operating_expense'),
            self::account('6300', 'Office Supplies', AccountType::Expense, 'operating_expense'),
            self::account('6400', 'Professional Fees', AccountType::Expense, 'operating_expense'),
            self::account('6500', 'Travel', AccountType::Expense, 'operating_expense'),
            self::account('6600', 'Marketing', AccountType::Expense, 'operating_expense'),
            self::account('6700', 'Depreciation', AccountType::Expense, 'operating_expense'),
            self::account('6800', 'Bank Charges', AccountType::Expense, 'operating_expense'),

            // -- Below the line ----------------------------------------
            self::header('7000', 'Other Expenses', AccountType::Expense),
            self::account('7100', 'Interest Expense', AccountType::Expense, 'other_expense'),
            // FX sits under income so a gain reads naturally; a loss is simply
            // a debit to the same account.
            self::account('7500', 'FX Gain and Loss', AccountType::Income, 'other_revenue', role: SystemAccount::FxGainLoss),

            // The pennies that per-line rounding cannot reconcile. Never
            // absorbed into revenue or tax, because a tax authority
            // reconciles tax to the minor unit.
            self::account('9800', 'Rounding Difference', AccountType::Expense, 'other_expense', role: SystemAccount::RoundingDifference),
        ];
    }

    /**
     * @return array{code: string, name: string, type: AccountType, subtype: string|null, normal_balance: NormalBalance|null, system_role: SystemAccount|null, is_header: bool}
     */
    private static function account(
        string $code,
        string $name,
        AccountType $type,
        ?string $subtype = null,
        ?NormalBalance $normalBalance = null,
        ?SystemAccount $role = null,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'subtype' => $subtype,
            'normal_balance' => $normalBalance,
            'system_role' => $role,
            'is_header' => false,
        ];
    }

    /**
     * @return array{code: string, name: string, type: AccountType, subtype: string|null, normal_balance: NormalBalance|null, system_role: SystemAccount|null, is_header: bool}
     */
    private static function header(string $code, string $name, AccountType $type): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'subtype' => null,
            'normal_balance' => null,
            'system_role' => null,
            'is_header' => true,
        ];
    }
}
