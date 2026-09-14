import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Archive, Building2, Landmark, Plus, Save, Wallet } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { formatMoney, isNegative } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface LedgerAccountOption {
    value: string;
    label: string;
    type: string;
    taken: boolean;
}

interface KindOption {
    value: string;
    label: string;
    account_type: string;
}

interface BankAccountRow {
    id: string;
    name: string;
    label: string;
    bank_name: string | null;
    account_number_masked: string | null;
    branch: string | null;
    kind: string;
    kind_label: string;
    currency: string;
    is_active: boolean;
    is_primary: boolean;
    is_archived: boolean;
    supports_statements: boolean;
    account: { id: string | null; code: string | null; name: string | null };
    balance: string;
    unpresented_count: number;
    unpresented_total: string;
    reconciled_through: string | null;
    notes: string | null;
}

interface AccountsProps {
    accounts: BankAccountRow[];
    ledgerAccounts: LedgerAccountOption[];
    kinds: KindOption[];
    baseCurrency: string;
    can: { manage: boolean; import: boolean; reconcile: boolean; transfer: boolean };
}

const KIND_ICON: Record<string, typeof Landmark> = {
    bank: Landmark,
    cash: Wallet,
    credit_card: Building2,
};

/**
 * Bank, cash and credit-card accounts.
 *
 * Every balance here is the LEDGER's. There is no separate banking balance to
 * drift from it, which is why the ledger account is shown on each row rather
 * than hidden behind a settings dialog: the pairing is the thing that makes
 * the figure mean something.
 */
export default function Accounts({
    accounts,
    ledgerAccounts,
    kinds,
    baseCurrency,
    can,
}: AccountsProps) {
    const [editing, setEditing] = useState<BankAccountRow | null>(null);
    const [adding, setAdding] = useState(false);

    const open = adding || editing !== null;

    return (
        <AppLayout
            title="Bank accounts"
            description="Where the money actually sits. Balances come from the ledger."
            breadcrumbs={[{ label: 'Banking' }, { label: 'Accounts' }]}
            actions={
                can.manage && !open ? (
                    <Button
                        variant="primary"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => {
                            setEditing(null);
                            setAdding(true);
                        }}
                    >
                        Add an account
                    </Button>
                ) : undefined
            }
        >
            <Head title="Bank accounts" />

            <div className="flex flex-col gap-4">
                {open && (
                    <AccountForm
                        account={editing}
                        ledgerAccounts={ledgerAccounts}
                        kinds={kinds}
                        onDone={() => {
                            setAdding(false);
                            setEditing(null);
                        }}
                    />
                )}

                {accounts.length === 0 ? (
                    <EmptyState
                        icon={Landmark}
                        title="No bank accounts yet"
                        description="Attach one to an account in your chart, and statements can be imported and reconciled against it."
                        action={
                            can.manage ? (
                                <Button
                                    variant="primary"
                                    icon={<Plus aria-hidden="true" />}
                                    onClick={() => setAdding(true)}
                                >
                                    Add an account
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {accounts.map((account) => (
                            <AccountCard
                                key={account.id}
                                account={account}
                                baseCurrency={baseCurrency}
                                can={can}
                                onEdit={() => {
                                    setAdding(false);
                                    setEditing(account);
                                }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function AccountCard({
    account,
    baseCurrency,
    can,
    onEdit,
}: {
    account: BankAccountRow;
    baseCurrency: string;
    can: AccountsProps['can'];
    onEdit: () => void;
}) {
    const Icon = KIND_ICON[account.kind] ?? Landmark;

    const archive = useForm({});

    return (
        <Card className={cn('flex flex-col gap-3', account.is_archived && 'opacity-60')}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-2">
                    <span className="bg-surface-sunken text-content-muted mt-0.5 rounded-md p-1.5">
                        <Icon className="size-4" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-content truncate text-sm font-semibold">
                            {account.name}
                        </p>
                        <p className="text-content-muted truncate text-xs">
                            {[account.bank_name, account.account_number_masked]
                                .filter(Boolean)
                                .join(' · ') || account.kind_label}
                        </p>
                    </div>
                </div>

                <div className="flex shrink-0 flex-col items-end gap-1">
                    {account.is_primary && <Badge tone="brand">Primary</Badge>}
                    {account.is_archived && <Badge tone="neutral">Archived</Badge>}
                </div>
            </div>

            <div>
                <p
                    className={cn(
                        'text-content text-xl font-semibold tabular-nums',
                        isNegative(account.balance) && 'text-danger',
                    )}
                >
                    {formatMoney(account.balance, { currency: account.currency })}
                </p>
                <p className="text-content-muted text-xs">
                    Ledger balance
                    {account.currency !== baseCurrency && ` · held in ${account.currency}`}
                </p>
            </div>

            <dl className="text-content-muted grid grid-cols-2 gap-2 text-xs">
                <div>
                    <dt>Chart account</dt>
                    <dd className="text-content-secondary">
                        {account.account.code} · {account.account.name}
                    </dd>
                </div>
                <div>
                    <dt>Reconciled to</dt>
                    <dd className="text-content-secondary">
                        {account.reconciled_through ?? 'Never'}
                    </dd>
                </div>
                <div className="col-span-2">
                    <dt>Not yet on a statement</dt>
                    <dd className="text-content-secondary tabular-nums">
                        {account.unpresented_count === 0
                            ? 'Nothing outstanding'
                            : `${account.unpresented_count} ${
                                  account.unpresented_count === 1 ? 'entry' : 'entries'
                              }, ${formatMoney(account.unpresented_total, {
                                  currency: account.currency,
                              })}`}
                    </dd>
                </div>
            </dl>

            <div className="border-line-subtle flex flex-wrap items-center gap-2 border-t pt-3">
                {account.supports_statements && can.import && (
                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => router.get(`/banking/transactions?account=${account.id}`)}
                    >
                        Transactions
                    </Button>
                )}
                {can.reconcile && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => router.get(`/banking/reconciliation?account=${account.id}`)}
                    >
                        Reconcile
                    </Button>
                )}
                {can.manage && !account.is_archived && (
                    <>
                        <Button size="sm" variant="ghost" onClick={onEdit}>
                            Edit
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            icon={<Archive aria-hidden="true" />}
                            onClick={() => {
                                /*
                                 * Confirmed, because it removes the account
                                 * from every form in the product. Its history
                                 * is kept, and the message says so.
                                 */
                                if (
                                    window.confirm(
                                        `Archive ${account.name}? Its statements and reconciliations are kept, but it stops appearing on forms.`,
                                    )
                                ) {
                                    archive.delete(`/banking/accounts/${account.id}`, {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            Archive
                        </Button>
                    </>
                )}
            </div>
        </Card>
    );
}

function AccountForm({
    account,
    ledgerAccounts,
    kinds,
    onDone,
}: {
    account: BankAccountRow | null;
    ledgerAccounts: LedgerAccountOption[];
    kinds: KindOption[];
    onDone: () => void;
}) {
    const form = useForm({
        account_id: account?.account.id ?? '',
        name: account?.name ?? '',
        bank_name: account?.bank_name ?? '',
        account_number: '',
        branch: account?.branch ?? '',
        kind: account?.kind ?? 'bank',
        is_active: account?.is_active ?? true,
        is_primary: account?.is_primary ?? false,
        notes: account?.notes ?? '',
    });

    /*
     * The chart accounts a kind may attach to. A credit card is a liability,
     * and offering the asset accounts for it would invite the one mistake
     * that puts a balance sheet out by twice the balance.
     */
    const requiredType = kinds.find((kind) => kind.value === form.data.kind)?.account_type;

    const eligible = ledgerAccounts
        .filter((option) => option.type === requiredType)
        .filter((option) => !option.taken || option.value === account?.account.id)
        .map((option) => ({ value: option.value, label: option.label }));

    const submit = (event: SyntheticEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (account === null) {
            form.post('/banking/accounts', options);
        } else {
            form.patch(`/banking/accounts/${account.id}`, options);
        }
    };

    return (
        <Card>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <div>
                    <h2 className="text-content text-md font-semibold">
                        {account === null ? 'Add a bank account' : `Edit ${account.name}`}
                    </h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        The balance stays in the chart of accounts. This only records which bank it
                        is, and how statements should be read.
                    </p>
                </div>

                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <Select
                        label="Kind"
                        options={kinds.map((kind) => ({ value: kind.value, label: kind.label }))}
                        value={form.data.kind}
                        onChange={(event) => {
                            form.setData('kind', event.target.value);
                            form.setData('account_id', '');
                        }}
                        error={form.errors.kind}
                    />

                    <Select
                        label="Chart account"
                        options={eligible}
                        placeholder="Choose an account"
                        value={form.data.account_id}
                        onChange={(event) => form.setData('account_id', event.target.value)}
                        error={form.errors.account_id}
                        hint="Where this account's balance is kept."
                    />

                    <Input
                        label="Name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                        placeholder="HBL Current"
                    />

                    <Input
                        label="Bank"
                        optional
                        value={form.data.bank_name}
                        onChange={(event) => form.setData('bank_name', event.target.value)}
                        error={form.errors.bank_name}
                    />

                    <Input
                        label="Account number"
                        optional
                        value={form.data.account_number}
                        onChange={(event) => form.setData('account_number', event.target.value)}
                        error={form.errors.account_number}
                        hint="Only the last four digits are stored."
                    />

                    <Input
                        label="Branch"
                        optional
                        value={form.data.branch}
                        onChange={(event) => form.setData('branch', event.target.value)}
                        error={form.errors.branch}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <label className="text-content-secondary flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            className="accent-brand size-4"
                            checked={form.data.is_primary}
                            onChange={(event) => form.setData('is_primary', event.target.checked)}
                        />
                        Use as the default account on forms
                    </label>

                    <label className="text-content-secondary flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            className="accent-brand size-4"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                        />
                        In use
                    </label>
                </div>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" disabled={form.processing}>
                        <Save aria-hidden="true" />
                        {form.processing ? 'Saving…' : 'Save'}
                    </Button>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    );
}
