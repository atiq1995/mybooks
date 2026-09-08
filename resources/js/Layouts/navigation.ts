import type { LucideIcon } from 'lucide-react';
import {
    ArrowLeftRight,
    BookOpen,
    Boxes,
    ChartNoAxesColumn,
    FileText,
    LayoutDashboard,
    Receipt,
    Settings,
    ShoppingCart,
    Users,
    Wallet,
} from 'lucide-react';

/**
 * The application's information architecture, in one place.
 *
 * Sidebar, command palette and breadcrumbs all read from this, so a module
 * cannot appear in the navigation but be missing from search, and renaming
 * something renames it everywhere.
 *
 * `phase` records which roadmap phase delivers each item. Anything beyond the
 * current phase routes to a placeholder rather than a broken link — the
 * structure is real from day one, and it is honest about what is not built.
 *
 * @see ROADMAP.md
 */

export interface NavItem {
    label: string;
    /** Ziggy route name. Undefined while the module is unbuilt. */
    route?: string;
    /** Path prefix used to decide the active state. */
    match: string;
    phase: number;
    /** Shown as a count badge, e.g. overdue invoices. */
    badge?: 'overdue_invoices' | 'unreconciled' | 'pending_approvals';
}

export interface NavSection {
    label: string;
    icon: LucideIcon;
    /** A section with a route and no children is a single destination. */
    route?: string;
    match: string;
    phase: number;
    items?: NavItem[];
}

export const NAVIGATION: NavSection[] = [
    {
        label: 'Dashboard',
        icon: LayoutDashboard,
        route: 'dashboard',
        match: '/dashboard',
        phase: 0,
    },
    {
        label: 'Sales',
        icon: Receipt,
        match: '/sales',
        phase: 3,
        route: 'sales.documents.index',
        items: [
            {
                label: 'Customers',
                route: 'sales.contacts.index',
                match: '/sales/customers',
                phase: 3,
            },
            { label: 'Items', route: 'sales.items.index', match: '/sales/items', phase: 3 },
            {
                label: 'Estimates',
                route: 'sales.documents.index',
                match: '/sales/estimates',
                phase: 3,
            },
            {
                label: 'Sales Orders',
                route: 'sales.documents.index',
                match: '/sales/sales-orders',
                phase: 3,
            },
            {
                label: 'Invoices',
                route: 'sales.documents.index',
                match: '/sales/invoices',
                phase: 3,
                badge: 'overdue_invoices',
            },
            {
                label: 'Payments Received',
                route: 'sales.payments.index',
                match: '/sales/payments',
                phase: 3,
            },
            {
                label: 'Credit Notes',
                route: 'sales.documents.index',
                match: '/sales/credit-notes',
                phase: 3,
            },
            {
                label: 'Receivables',
                route: 'sales.receivables',
                match: '/sales/receivables',
                phase: 3,
            },
            /*
             * Recurring invoices need a scheduler and a template model, both
             * of which are their own piece of work — so the entry stays
             * padlocked rather than pretending.
             */
            { label: 'Recurring Invoices', match: '/sales/recurring-invoices', phase: 4 },
        ],
    },
    {
        label: 'Purchases',
        icon: ShoppingCart,
        match: '/purchases',
        phase: 4,
        items: [
            { label: 'Vendors', match: '/purchases/vendors', phase: 4 },
            { label: 'Purchase Orders', match: '/purchases/orders', phase: 4 },
            { label: 'Bills', match: '/purchases/bills', phase: 4 },
            { label: 'Payments Made', match: '/purchases/payments', phase: 4 },
            { label: 'Vendor Credits', match: '/purchases/vendor-credits', phase: 4 },
        ],
    },
    {
        label: 'Expenses',
        icon: Wallet,
        match: '/expenses',
        phase: 5,
        items: [
            { label: 'All Expenses', match: '/expenses', phase: 5 },
            {
                label: 'Approvals',
                match: '/expenses/approvals',
                phase: 5,
                badge: 'pending_approvals',
            },
            { label: 'Categories', match: '/expenses/categories', phase: 5 },
            { label: 'Mileage', match: '/expenses/mileage', phase: 5 },
        ],
    },
    {
        label: 'Banking',
        icon: ArrowLeftRight,
        match: '/banking',
        phase: 6,
        items: [
            { label: 'Accounts', match: '/banking/accounts', phase: 6 },
            {
                label: 'Transactions',
                match: '/banking/transactions',
                phase: 6,
                badge: 'unreconciled',
            },
            { label: 'Reconciliation', match: '/banking/reconciliation', phase: 6 },
            { label: 'Transfers', match: '/banking/transfers', phase: 6 },
        ],
    },
    {
        label: 'Accounting',
        icon: BookOpen,
        route: 'accounting.accounts',
        match: '/accounting',
        phase: 2,
        items: [
            {
                label: 'Chart of Accounts',
                route: 'accounting.accounts',
                match: '/accounting/accounts',
                phase: 2,
            },
            {
                label: 'Manual Journals',
                route: 'accounting.journals',
                match: '/accounting/journals',
                phase: 2,
            },
            {
                label: 'General Ledger',
                route: 'accounting.general-ledger',
                match: '/accounting/general-ledger',
                phase: 2,
            },
            {
                label: 'Trial Balance',
                route: 'accounting.trial-balance',
                match: '/accounting/trial-balance',
                phase: 2,
            },
            {
                label: 'Fiscal Periods',
                route: 'accounting.periods',
                match: '/accounting/periods',
                phase: 2,
            },
            {
                label: 'Currencies',
                route: 'accounting.currencies',
                match: '/accounting/currencies',
                phase: 2,
            },
            // Still a placeholder: opening balances need the contact and item
            // records that Phase 3 brings, since most of them are unpaid
            // invoices and bills rather than plain journal lines.
            { label: 'Opening Balances', match: '/accounting/opening-balances', phase: 3 },
        ],
    },
    {
        label: 'Inventory',
        icon: Boxes,
        match: '/inventory',
        phase: 8,
        items: [
            { label: 'Items', match: '/inventory/items', phase: 3 },
            { label: 'Warehouses', match: '/inventory/warehouses', phase: 8 },
            { label: 'Adjustments', match: '/inventory/adjustments', phase: 8 },
            { label: 'Valuation', match: '/inventory/valuation', phase: 8 },
        ],
    },
    {
        label: 'Reports',
        icon: ChartNoAxesColumn,
        match: '/reports',
        phase: 7,
    },
    {
        label: 'Contacts',
        icon: Users,
        // No screen of its own: customers and vendors live under Sales, which
        // is where people look for them.
        route: 'sales.contacts.index',
        match: '/sales/customers',
        phase: 3,
    },
    {
        label: 'Documents',
        icon: FileText,
        match: '/documents',
        phase: 9,
    },
    {
        label: 'Settings',
        icon: Settings,
        route: 'settings.profile',
        match: '/settings',
        phase: 1,
    },
];

/**
 * The phase currently shipped. Items above this route to a placeholder that
 * explains what is coming, rather than a link that goes nowhere.
 *
 * Raise this as each phase lands.
 */
export const CURRENT_PHASE = 3;

export function isAvailable(phase: number): boolean {
    return phase <= CURRENT_PHASE;
}

/** Longest-prefix match, so /sales/invoices does not also light up /sales. */
export function isActive(currentPath: string, match: string): boolean {
    if (match === '/dashboard') {
        return currentPath === '/' || currentPath.startsWith('/dashboard');
    }

    return currentPath === match || currentPath.startsWith(`${match}/`);
}
