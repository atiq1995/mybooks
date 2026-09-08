import { useMemo, useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Archive,
    ChevronRight,
    Landmark,
    Lock,
    Pencil,
    Plus,
    RotateCcw,
    Table2,
} from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { formatMoney, isNegative } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Account {
    id: string;
    code: string;
    name: string;
    description: string | null;
    type: string;
    type_label: string;
    subtype: string | null;
    normal_balance: string;
    parent_id: string | null;
    system_role: string | null;
    currency: string | null;
    is_active: boolean;
    is_header: boolean;
    is_archived: boolean;
    /** Signed by normal balance. Null for headings, which hold no postings. */
    balance: string | null;
}

interface AccountsProps {
    accounts: Account[];
    baseCurrency: string;
    showArchived: boolean;
    options: {
        types: { value: string; label: string; normal_balance: string }[];
        normal_balances: { value: string; label: string }[];
    };
    can: { manage: boolean };
}

/**
 * The chart of accounts.
 *
 * Grouped by the five root types and indented under their headings, because
 * the chart IS a hierarchy and a flat list of forty rows hides the structure
 * that gives each code its meaning.
 *
 * Balances are signed by normal balance: a positive figure always means "as
 * this kind of account expects". A negative one — an overdrawn bank account,
 * a customer in credit — is shown as negative rather than hidden behind an
 * absolute value, since that is exactly the row somebody needs to see.
 */
export default function ChartOfAccounts({
    accounts,
    baseCurrency,
    showArchived,
    options,
    can,
}: AccountsProps) {
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Account | null>(null);

    const query = search.trim().toLowerCase();

    const matches = useMemo(() => {
        return accounts.filter((account) => {
            if (typeFilter !== '' && account.type !== typeFilter) {
                return false;
            }

            if (query === '') {
                return true;
            }

            return (
                account.code.toLowerCase().includes(query) ||
                account.name.toLowerCase().includes(query)
            );
        });
    }, [accounts, query, typeFilter]);

    /*
     * While filtering, a matching child would otherwise appear without the
     * heading that explains it. Keeping ancestors of matches preserves the
     * structure without widening the result set.
     */
    const visible = useMemo(() => {
        if (query === '' && typeFilter === '') {
            return accounts;
        }

        const keep = new Set(matches.map((a) => a.id));
        const byId = new Map(accounts.map((a) => [a.id, a]));

        for (const account of matches) {
            let parentId = account.parent_id;
            while (parentId !== null && !keep.has(parentId)) {
                keep.add(parentId);
                parentId = byId.get(parentId)?.parent_id ?? null;
            }
        }

        return accounts.filter((a) => keep.has(a.id));
    }, [accounts, matches, query, typeFilter]);

    const grouped = useMemo(() => {
        return options.types
            .map((type) => ({
                ...type,
                rows: visible.filter((account) => account.type === type.value),
            }))
            .filter((group) => group.rows.length > 0);
    }, [options.types, visible]);

    const headings = accounts.filter((a) => a.is_header && !a.is_archived);

    const toggleArchived = () => {
        router.get('/accounting/accounts', showArchived ? {} : { archived: 1 }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <AppLayout
            title="Chart of Accounts"
            description={`Every account these books are kept in, and what each one holds. Balances in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Accounting' }, { label: 'Chart of Accounts' }]}
            actions={
                <div className="flex items-center gap-2">
                    <Button variant="ghost" size="md" onClick={toggleArchived}>
                        {showArchived ? 'Hide archived' : 'Show archived'}
                    </Button>
                    {can.manage && !creating && (
                        <Button
                            variant="primary"
                            size="md"
                            icon={<Plus aria-hidden="true" />}
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                        >
                            New account
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Chart of Accounts" />

            {creating && can.manage && (
                <AccountForm
                    options={options}
                    headings={headings}
                    onClose={() => setCreating(false)}
                />
            )}

            {editing !== null && can.manage && (
                <AccountForm
                    account={editing}
                    options={options}
                    headings={headings.filter((h) => h.id !== editing.id)}
                    onClose={() => setEditing(null)}
                />
            )}

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <Input
                    label="Search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Code or name"
                    containerClassName="w-full sm:w-64"
                />
                <Select
                    label="Type"
                    value={typeFilter}
                    onChange={(e) => setTypeFilter(e.target.value)}
                    options={[
                        { value: '', label: 'All types' },
                        ...options.types.map((t) => ({ value: t.value, label: t.label })),
                    ]}
                    containerClassName="w-full sm:w-48"
                />
                <p className="text-content-muted pb-2 text-xs">
                    {matches.length} of {accounts.length} accounts
                </p>
            </div>

            {accounts.length === 0 ? (
                <Card flush>
                    <EmptyState
                        icon={Table2}
                        title="No accounts yet"
                        description="Finish setup to build a chart of accounts for your jurisdiction, then add any accounts specific to how you work."
                        action={
                            <Button
                                variant="primary"
                                size="sm"
                                onClick={() => router.get('/onboarding')}
                            >
                                Go to setup
                            </Button>
                        }
                    />
                </Card>
            ) : grouped.length === 0 ? (
                <Card flush>
                    <NoResultsState
                        query={query === '' ? undefined : search}
                        onClear={() => {
                            setSearch('');
                            setTypeFilter('');
                        }}
                    />
                </Card>
            ) : (
                <div className="flex flex-col gap-4">
                    {grouped.map((group) => (
                        <Card key={group.value} flush>
                            <header className="border-line-subtle flex items-baseline justify-between border-b px-4 py-3">
                                <h2 className="text-content text-md font-semibold">
                                    {group.label}
                                </h2>
                                <p className="text-content-muted text-2xs uppercase">
                                    Normally {group.normal_balance}
                                </p>
                            </header>

                            <AccountTable
                                accounts={group.rows}
                                baseCurrency={baseCurrency}
                                can={can}
                                onEdit={(account) => {
                                    setCreating(false);
                                    setEditing(account);
                                }}
                            />
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}

function AccountTable({
    accounts,
    baseCurrency,
    can,
    onEdit,
}: {
    accounts: Account[];
    baseCurrency: string;
    can: AccountsProps['can'];
    onEdit: (account: Account) => void;
}) {
    const archive = (account: Account) => {
        if (
            !window.confirm(
                `Archive ${account.code} ${account.name}?\n\n` +
                    'Its past entries stay in the ledger and on every report — archiving only ' +
                    'removes it from the accounts you can post to.',
            )
        ) {
            return;
        }

        router.delete(`/accounting/accounts/${account.code}`, { preserveScroll: true });
    };

    return (
        <div className="table-scroll">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                        <th className="px-4 py-2 text-left font-medium">Account</th>
                        <th className="px-4 py-2 text-left font-medium">Normal</th>
                        <th className="px-4 py-2 text-right font-medium">Balance</th>
                        <th className="w-20 px-4 py-2" />
                    </tr>
                </thead>

                <tbody className="divide-line-subtle divide-y">
                    {accounts.map((account) => (
                        <tr
                            key={account.id}
                            className={cn(
                                'hover:bg-surface-hover',
                                account.is_archived && 'opacity-60',
                            )}
                        >
                            <td className="px-4 py-2.5">
                                <div
                                    className="flex items-center gap-2"
                                    // Children sit under their heading. One level
                                    // is the whole depth of this chart.
                                    style={{
                                        paddingLeft: account.parent_id !== null ? '1rem' : 0,
                                    }}
                                >
                                    {account.parent_id !== null && (
                                        <ChevronRight
                                            className="text-content-muted size-3 shrink-0"
                                            aria-hidden="true"
                                        />
                                    )}

                                    <span
                                        className={cn(
                                            'text-content tabular-nums',
                                            account.is_header ? 'font-semibold' : 'font-medium',
                                        )}
                                    >
                                        {account.code}
                                    </span>

                                    <span
                                        className={
                                            account.is_header
                                                ? 'text-content font-semibold'
                                                : 'text-content-secondary'
                                        }
                                    >
                                        {account.name}
                                    </span>

                                    {account.system_role !== null && (
                                        <span
                                            title="A system account. The application posts to it automatically."
                                            className="text-content-muted"
                                        >
                                            <Lock className="size-3" aria-hidden="true" />
                                        </span>
                                    )}

                                    {account.currency !== null && (
                                        <Badge tone="info">{account.currency}</Badge>
                                    )}

                                    {account.is_archived && <Badge tone="neutral">Archived</Badge>}
                                </div>

                                {account.description !== null && (
                                    <p className="text-content-muted mt-0.5 pl-1 text-xs">
                                        {account.description}
                                    </p>
                                )}
                            </td>

                            <td className="text-content-muted px-4 py-2.5 text-xs">
                                {account.normal_balance === 'debit' ? 'Debit' : 'Credit'}
                            </td>

                            <td
                                className={cn(
                                    'px-4 py-2.5 text-right tabular-nums',
                                    account.balance !== null && isNegative(account.balance)
                                        ? 'text-danger-600 dark:text-danger-400'
                                        : 'text-content',
                                )}
                            >
                                {account.is_header
                                    ? ''
                                    : formatMoney(account.balance, {
                                          currency: baseCurrency,
                                          showCurrency: false,
                                      })}
                            </td>

                            <td className="px-4 py-2.5 text-right">
                                {can.manage && (
                                    <div className="flex justify-end gap-1">
                                        <button
                                            type="button"
                                            onClick={() => onEdit(account)}
                                            aria-label={`Edit ${account.code} ${account.name}`}
                                            className="text-content-muted hover:bg-surface-active hover:text-content rounded p-1.5 transition-colors"
                                        >
                                            <Pencil className="size-3.5" aria-hidden="true" />
                                        </button>

                                        {account.is_archived ? (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.post(
                                                        `/accounting/accounts/${account.code}/restore`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                aria-label={`Restore ${account.code} ${account.name}`}
                                                className="text-content-muted hover:bg-surface-active hover:text-content rounded p-1.5 transition-colors"
                                            >
                                                <RotateCcw
                                                    className="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        ) : (
                                            account.system_role === null && (
                                                <button
                                                    type="button"
                                                    onClick={() => archive(account)}
                                                    aria-label={`Archive ${account.code} ${account.name}`}
                                                    className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 rounded p-1.5 transition-colors"
                                                >
                                                    <Archive
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            )
                                        )}
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * Create or edit an account.
 *
 * Inline rather than in a dialog: the chart behind it is the reference the
 * user needs while choosing a code and a parent, and a modal would cover
 * exactly that.
 */
function AccountForm({
    account,
    options,
    headings,
    onClose,
}: {
    account?: Account;
    options: AccountsProps['options'];
    headings: Account[];
    onClose: () => void;
}) {
    const isEdit = account !== undefined;

    const form = useForm({
        code: account?.code ?? '',
        name: account?.name ?? '',
        description: account?.description ?? '',
        type: account?.type ?? options.types[0]?.value ?? 'asset',
        normal_balance: account?.normal_balance ?? options.types[0]?.normal_balance ?? 'debit',
        parent_id: account?.parent_id ?? '',
        currency: account?.currency ?? '',
        is_header: account?.is_header ?? false,
    });

    /*
     * Changing the type moves the normal balance with it. A contra account
     * then overrides it deliberately, which is the only case where the two
     * disagree — and it should take a deliberate act.
     */
    const changeType = (type: string) => {
        const normal = options.types.find((t) => t.value === type)?.normal_balance;
        form.setData((data) => ({
            ...data,
            type,
            normal_balance: normal ?? data.normal_balance,
        }));
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/accounting/accounts/${account.code}`, {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.post('/accounting/accounts', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    const typeDefault = options.types.find((t) => t.value === form.data.type)?.normal_balance;
    const isContra = typeDefault !== undefined && typeDefault !== form.data.normal_balance;

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">
                        {isEdit ? `Edit ${account.code} ${account.name}` : 'New account'}
                    </h2>
                    <p className="text-content-muted text-xs">
                        {isEdit
                            ? 'The code cannot change — it appears on every report and document that has ever quoted this account.'
                            : 'The code is what you will navigate by. Group it under a heading so it rolls up on reports.'}
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-2">
                    {!isEdit && (
                        <Input
                            label="Code"
                            name="code"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                            error={form.errors.code}
                            placeholder="1210"
                            hint="Unique within this organisation."
                            required
                        />
                    )}

                    <Input
                        label="Name"
                        name="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                        placeholder="Trade Receivables — Retail"
                        required
                    />

                    <Select
                        label="Type"
                        name="type"
                        value={form.data.type}
                        onChange={(e) => changeType(e.target.value)}
                        error={form.errors.type}
                        options={options.types.map((t) => ({ value: t.value, label: t.label }))}
                        hint={
                            isEdit
                                ? 'Locked once the account has posted: changing it would flip the sign of past figures.'
                                : undefined
                        }
                        required
                    />

                    <Select
                        label="Normal balance"
                        name="normal_balance"
                        value={form.data.normal_balance}
                        onChange={(e) => form.setData('normal_balance', e.target.value)}
                        error={form.errors.normal_balance}
                        options={options.normal_balances}
                        hint={
                            isContra
                                ? 'Inverted for its type — this is a contra account, like sales returns.'
                                : 'Follows the type. Change it only for a contra account.'
                        }
                        required
                    />

                    <Select
                        label="Group under"
                        value={form.data.parent_id}
                        onChange={(e) => form.setData('parent_id', e.target.value)}
                        error={form.errors.parent_id}
                        options={[
                            { value: '', label: 'No heading' },
                            ...headings.map((h) => ({
                                value: h.id,
                                label: `${h.code} — ${h.name}`,
                            })),
                        ]}
                    />

                    {!isEdit && (
                        <Input
                            label="Currency"
                            value={form.data.currency}
                            onChange={(e) => form.setData('currency', e.target.value)}
                            error={form.errors.currency}
                            placeholder="Leave blank for base currency"
                            hint="Only bank and cash accounts held in another currency need this."
                        />
                    )}

                    <Input
                        label="Description"
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        error={form.errors.description}
                        containerClassName="sm:col-span-2"
                        placeholder="What belongs in this account, for whoever posts to it next."
                    />

                    {!isEdit && (
                        <label className="text-content-secondary flex items-center gap-2 text-sm sm:col-span-2">
                            <input
                                type="checkbox"
                                checked={form.data.is_header}
                                onChange={(e) => form.setData('is_header', e.target.checked)}
                                className="border-line accent-brand size-4 rounded"
                            />
                            <span>
                                This is a heading that only groups other accounts
                                <span className="text-content-muted">
                                    {' '}
                                    — nothing can be posted to it
                                </span>
                            </span>
                        </label>
                    )}
                </div>

                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="ghost" size="md" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={form.processing}
                        icon={<Landmark aria-hidden="true" />}
                    >
                        {isEdit ? 'Save changes' : 'Create account'}
                    </Button>
                </footer>
            </form>
        </Card>
    );
}
