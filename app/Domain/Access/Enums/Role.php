<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

/**
 * Roles held within an organisation.
 *
 * A role is a named bundle of {@see Permission}s. Roles are per-organisation:
 * the same person may be an accountant in one set of books and read-only in
 * another, so nothing here is global.
 *
 * The bundles are shaped around SEPARATION OF DUTIES. In particular,
 * Bookkeeper can prepare work but not post it, and Approver can approve but
 * not create — so no single non-admin role can both originate a payment and
 * authorise it. Instance-level rules (an approver may not approve their own
 * submission) live in Policies, because they depend on the record, not the
 * role.
 *
 * @see SECURITY.md section 4
 */
enum Role: string
{
    /** Created the organisation. Everything, including archiving it. */
    case Owner = 'owner';

    /** Runs the organisation day to day. Everything except archiving it. */
    case Admin = 'admin';

    /** Owns the books: posts, reverses, reconciles, closes periods. */
    case Accountant = 'accountant';

    /** Prepares documents for someone else to post. Cannot post or approve. */
    case Bookkeeper = 'bookkeeper';

    /** Authorises spend. Cannot create the thing being authorised. */
    case Approver = 'approver';

    /** Read-only, including reports. Typical for an external adviser. */
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Administrator',
            self::Accountant => 'Accountant',
            self::Bookkeeper => 'Bookkeeper',
            self::Approver => 'Approver',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full control, including archiving the organisation.',
            self::Admin => 'Full control of the books, people and settings.',
            self::Accountant => 'Posts to the ledger, reconciles, and closes periods.',
            self::Bookkeeper => 'Prepares invoices, bills and expenses for approval. Cannot post.',
            self::Approver => 'Approves bills, payments and expenses. Cannot create them.',
            self::Viewer => 'Reads everything, changes nothing.',
        };
    }

    /**
     * The permissions this role carries.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Everything, without exception.
            self::Owner => Permission::cases(),

            // Everything except ending the organisation's life.
            self::Admin => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => $p !== Permission::OrganizationArchive,
            )),

            /*
             * The books are this person's job. They may post, reverse and
             * reconcile. They may NOT post into a closed period — that stays
             * with an administrator and is audited on every use, because a
             * closed period is usually closed because it has been filed.
             */
            self::Accountant => [
                Permission::OrganizationView,
                Permission::UsersView,

                Permission::AccountingView,
                Permission::AccountingManageAccounts,
                Permission::AccountingPost,
                Permission::AccountingReverse,
                Permission::AccountingManagePeriods,
                Permission::AccountingCloseYear,
                Permission::AccountingOpeningBalances,

                Permission::SalesView,
                Permission::SalesCreate,
                Permission::SalesUpdate,
                Permission::SalesDelete,
                Permission::SalesSend,
                Permission::SalesRecordPayment,

                Permission::PurchasesView,
                Permission::PurchasesCreate,
                Permission::PurchasesUpdate,
                Permission::PurchasesDelete,
                Permission::PurchasesApprove,
                Permission::PurchasesRecordPayment,

                Permission::ExpensesView,
                Permission::ExpensesCreate,
                Permission::ExpensesUpdate,
                Permission::ExpensesDelete,
                Permission::ExpensesApprove,

                Permission::BankingView,
                Permission::BankingManageAccounts,
                Permission::BankingImport,
                Permission::BankingReconcile,
                Permission::BankingTransfer,

                Permission::InventoryView,
                Permission::InventoryManageItems,
                Permission::InventoryAdjust,

                Permission::ContactsView,
                Permission::ContactsCreate,
                Permission::ContactsUpdate,
                Permission::ContactsDelete,

                Permission::ReportsView,
                Permission::ReportsExport,

                Permission::SettingsView,
                Permission::SettingsAccounting,
                Permission::SettingsTaxes,
                Permission::SettingsTemplates,

                Permission::AuditView,
            ],

            /*
             * Prepares work; someone else commits it. Deliberately has no
             * posting, approval or reconciliation permission — that gap IS
             * the control.
             */
            self::Bookkeeper => [
                Permission::OrganizationView,

                Permission::AccountingView,

                Permission::SalesView,
                Permission::SalesCreate,
                Permission::SalesUpdate,
                Permission::SalesSend,

                Permission::PurchasesView,
                Permission::PurchasesCreate,
                Permission::PurchasesUpdate,

                Permission::ExpensesView,
                Permission::ExpensesCreate,
                Permission::ExpensesUpdate,

                Permission::BankingView,
                Permission::BankingImport,

                Permission::InventoryView,
                Permission::InventoryManageItems,

                Permission::ContactsView,
                Permission::ContactsCreate,
                Permission::ContactsUpdate,

                Permission::ReportsView,
                Permission::SettingsView,
            ],

            /*
             * Authorises spend and nothing else. Cannot create the documents
             * being authorised, so approving one's own work is impossible by
             * construction rather than by policy.
             */
            self::Approver => [
                Permission::OrganizationView,

                Permission::AccountingView,
                Permission::SalesView,

                Permission::PurchasesView,
                Permission::PurchasesApprove,

                Permission::ExpensesView,
                Permission::ExpensesApprove,

                Permission::BankingView,
                Permission::InventoryView,
                Permission::ContactsView,

                Permission::ReportsView,
                Permission::SettingsView,
            ],

            // Reads everything, changes nothing.
            self::Viewer => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => str_ends_with($p->value, '.view'),
            )),
        };
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(static fn (Permission $p): string => $p->value, $this->permissions());
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }

    /**
     * Roles a person with the given role is allowed to ASSIGN to others.
     *
     * Nobody may grant a role above their own — otherwise an administrator
     * could promote themselves to owner by way of a second account.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Owner => self::cases(),
            self::Admin => [self::Admin, self::Accountant, self::Bookkeeper, self::Approver, self::Viewer],
            default => [],
        };
    }

    public function canAssign(self $role): bool
    {
        return in_array($role, $this->assignableRoles(), strict: true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
