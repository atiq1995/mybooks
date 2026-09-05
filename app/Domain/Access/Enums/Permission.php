<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

use App\Domain\Access\AccessControl;

/**
 * The complete permission catalogue.
 *
 * Permissions live in CODE, not in a database table. A permission name that
 * does not exist should be a failing test or a static-analysis error, never a
 * silently ungranted capability — which is exactly what a typo'd string in a
 * `permissions` table produces.
 *
 * Roles map to sets of these ({@see Role}); a membership may then grant or
 * revoke individual ones on top.
 *
 * Naming: `<area>.<verb>`. The area matches the navigation module so a
 * permission is findable from the screen it governs.
 *
 * @see AccessControl
 * @see SECURITY.md section 4
 */
enum Permission: string
{
    // -- Organisation --------------------------------------------------
    case OrganizationView = 'organization.view';
    case OrganizationUpdate = 'organization.update';
    case OrganizationArchive = 'organization.archive';

    // -- People --------------------------------------------------------
    case UsersView = 'users.view';
    case UsersInvite = 'users.invite';
    case UsersUpdateRole = 'users.update_role';
    case UsersSuspend = 'users.suspend';
    case UsersRemove = 'users.remove';

    /*
     * -- Accounting ------------------------------------------------------
     * The most consequential group in the system. `Post` is the capability
     * to write to the ledger at all; the others are deliberately separate so
     * that "can record a transaction" and "can rewrite history" are never the
     * same grant.
     */
    case AccountingView = 'accounting.view';
    case AccountingManageAccounts = 'accounting.manage_accounts';
    case AccountingPost = 'accounting.post';
    case AccountingPostToClosedPeriod = 'accounting.post_to_closed_period';
    case AccountingReverse = 'accounting.reverse';
    case AccountingManagePeriods = 'accounting.manage_periods';
    case AccountingCloseYear = 'accounting.close_year';
    case AccountingOpeningBalances = 'accounting.opening_balances';

    // -- Sales ---------------------------------------------------------
    case SalesView = 'sales.view';
    case SalesCreate = 'sales.create';
    case SalesUpdate = 'sales.update';
    case SalesDelete = 'sales.delete';
    case SalesSend = 'sales.send';
    case SalesRecordPayment = 'sales.record_payment';

    // -- Purchases -----------------------------------------------------
    case PurchasesView = 'purchases.view';
    case PurchasesCreate = 'purchases.create';
    case PurchasesUpdate = 'purchases.update';
    case PurchasesDelete = 'purchases.delete';
    case PurchasesApprove = 'purchases.approve';
    case PurchasesRecordPayment = 'purchases.record_payment';

    // -- Expenses ------------------------------------------------------
    case ExpensesView = 'expenses.view';
    case ExpensesCreate = 'expenses.create';
    case ExpensesUpdate = 'expenses.update';
    case ExpensesDelete = 'expenses.delete';
    case ExpensesApprove = 'expenses.approve';

    // -- Banking -------------------------------------------------------
    case BankingView = 'banking.view';
    case BankingManageAccounts = 'banking.manage_accounts';
    case BankingImport = 'banking.import';
    case BankingReconcile = 'banking.reconcile';
    case BankingTransfer = 'banking.transfer';

    // -- Inventory -----------------------------------------------------
    case InventoryView = 'inventory.view';
    case InventoryManageItems = 'inventory.manage_items';
    case InventoryAdjust = 'inventory.adjust';

    // -- Contacts ------------------------------------------------------
    case ContactsView = 'contacts.view';
    case ContactsCreate = 'contacts.create';
    case ContactsUpdate = 'contacts.update';
    case ContactsDelete = 'contacts.delete';

    // -- Reports -------------------------------------------------------
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    // -- Settings ------------------------------------------------------
    case SettingsView = 'settings.view';
    case SettingsOrganization = 'settings.organization';
    case SettingsAccounting = 'settings.accounting';
    case SettingsTaxes = 'settings.taxes';
    case SettingsTemplates = 'settings.templates';
    case SettingsIntegrations = 'settings.integrations';

    // -- Oversight -----------------------------------------------------
    case AuditView = 'audit.view';
    case ApiManageTokens = 'api.manage_tokens';

    /**
     * The area this permission belongs to — the part before the dot.
     */
    public function area(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    /**
     * Human-readable label for the roles-and-permissions settings screen.
     */
    public function label(): string
    {
        return match ($this) {
            self::OrganizationView => 'View the organisation',
            self::OrganizationUpdate => 'Edit organisation details',
            self::OrganizationArchive => 'Archive the organisation',

            self::UsersView => 'See who has access',
            self::UsersInvite => 'Invite people',
            self::UsersUpdateRole => 'Change roles',
            self::UsersSuspend => 'Suspend access',
            self::UsersRemove => 'Remove people',

            self::AccountingView => 'View the ledger',
            self::AccountingManageAccounts => 'Manage the chart of accounts',
            self::AccountingPost => 'Post to the ledger',
            self::AccountingPostToClosedPeriod => 'Post into a closed period',
            self::AccountingReverse => 'Reverse posted entries',
            self::AccountingManagePeriods => 'Open and close periods',
            self::AccountingCloseYear => 'Run the year-end close',
            self::AccountingOpeningBalances => 'Enter opening balances',

            self::SalesView => 'View sales documents',
            self::SalesCreate => 'Create sales documents',
            self::SalesUpdate => 'Edit sales documents',
            self::SalesDelete => 'Void sales documents',
            self::SalesSend => 'Send documents to customers',
            self::SalesRecordPayment => 'Record customer payments',

            self::PurchasesView => 'View purchase documents',
            self::PurchasesCreate => 'Create purchase documents',
            self::PurchasesUpdate => 'Edit purchase documents',
            self::PurchasesDelete => 'Void purchase documents',
            self::PurchasesApprove => 'Approve bills and payments',
            self::PurchasesRecordPayment => 'Record vendor payments',

            self::ExpensesView => 'View expenses',
            self::ExpensesCreate => 'Record expenses',
            self::ExpensesUpdate => 'Edit expenses',
            self::ExpensesDelete => 'Delete draft expenses',
            self::ExpensesApprove => 'Approve expenses',

            self::BankingView => 'View bank accounts',
            self::BankingManageAccounts => 'Manage bank accounts',
            self::BankingImport => 'Import statements',
            self::BankingReconcile => 'Reconcile accounts',
            self::BankingTransfer => 'Record transfers',

            self::InventoryView => 'View inventory',
            self::InventoryManageItems => 'Manage items',
            self::InventoryAdjust => 'Adjust stock',

            self::ContactsView => 'View contacts',
            self::ContactsCreate => 'Add contacts',
            self::ContactsUpdate => 'Edit contacts',
            self::ContactsDelete => 'Archive contacts',

            self::ReportsView => 'View reports',
            self::ReportsExport => 'Export reports',

            self::SettingsView => 'View settings',
            self::SettingsOrganization => 'Change organisation settings',
            self::SettingsAccounting => 'Change accounting settings',
            self::SettingsTaxes => 'Manage taxes',
            self::SettingsTemplates => 'Manage document templates',
            self::SettingsIntegrations => 'Manage integrations',

            self::AuditView => 'View the audit trail',
            self::ApiManageTokens => 'Manage API tokens',
        };
    }

    /**
     * Permissions that are dangerous enough to require a confirmed second
     * factor, regardless of role.
     *
     * These either write to the ledger, rewrite history, move money, or hand
     * someone else the ability to do so. SECURITY.md section 2.
     *
     * @return list<self>
     */
    public static function requiringTwoFactor(): array
    {
        return [
            self::AccountingPost,
            self::AccountingPostToClosedPeriod,
            self::AccountingReverse,
            self::AccountingCloseYear,
            self::AccountingOpeningBalances,
            self::SalesRecordPayment,
            self::PurchasesApprove,
            self::PurchasesRecordPayment,
            self::ExpensesApprove,
            self::BankingReconcile,
            self::BankingTransfer,
            self::UsersInvite,
            self::UsersUpdateRole,
            self::UsersRemove,
            self::ApiManageTokens,
            self::OrganizationArchive,
        ];
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this, self::requiringTwoFactor(), strict: true);
    }

    /**
     * Every permission, grouped by area — for the roles settings screen.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $permission) {
            $grouped[$permission->area()][] = $permission;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
