import { Head, Link, router } from '@inertiajs/react';
import { BookOpenCheck, Search } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { formatMoney, isNegative, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface LedgerRow {
    entry_no: string;
    entry_date: string;
    source_type: string;
    status: string;
    memo: string | null;
    debit: string;
    credit: string;
    /** Running balance after this row, signed by the account's normal balance. */
    balance: string;
}

interface GeneralLedgerProps {
    accounts: { id: string; code: string; name: string; label: string }[];
    selected: {
        id: string;
        code: string;
        name: string;
        type_label: string;
        normal_balance: string;
    } | null;
    filters: { account: string | null; from: string; to: string };
    ledger: {
        opening: string;
        rows: LedgerRow[];
        movement_debit: string;
        movement_credit: string;
        closing: string;
    } | null;
    baseCurrency: string;
}

/**
 * Every posting to one account, in order, with a running balance.
 *
 * The screen somebody opens when a figure looks wrong, so it answers the
 * question they actually have: what did this account start the period at,
 * what moved, and what does that leave. Without the opening balance the
 * movements below it would be arithmetic without a starting point.
 *
 * @see ACCOUNTING_RULES.md §6
 */
export default function GeneralLedger({
    accounts,
    selected,
    filters,
    ledger,
    baseCurrency,
}: GeneralLedgerProps) {
    const apply = (next: Partial<{ account: string; from: string; to: string }>) => {
        router.get(
            '/accounting/general-ledger',
            {
                account: next.account ?? filters.account ?? '',
                from: next.from ?? filters.from,
                to: next.to ?? filters.to,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            title="General Ledger"
            description={`One account at a time, with a running balance in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Accounting' }, { label: 'General Ledger' }]}
        >
            <Head title="General Ledger" />

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_10rem_10rem]">
                    <Select
                        label="Account"
                        value={filters.account ?? ''}
                        onChange={(e) => apply({ account: e.target.value })}
                        options={[
                            { value: '', label: 'Choose an account' },
                            ...accounts.map((a) => ({ value: a.id, label: a.label })),
                        ]}
                    />

                    <Input
                        label="From"
                        type="date"
                        value={filters.from}
                        onChange={(e) => apply({ from: e.target.value })}
                    />

                    <Input
                        label="To"
                        type="date"
                        value={filters.to}
                        onChange={(e) => apply({ to: e.target.value })}
                    />
                </div>
            </Card>

            {selected === null || ledger === null ? (
                <Card flush>
                    <EmptyState
                        icon={Search}
                        title="Choose an account"
                        description="Pick an account above to see everything posted to it, in date order, with the balance after each entry."
                    />
                </Card>
            ) : (
                <>
                    <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Figure
                            label={`Opening ${filters.from}`}
                            value={ledger.opening}
                            currency={baseCurrency}
                        />
                        <Figure
                            label="Debits in period"
                            value={ledger.movement_debit}
                            currency={baseCurrency}
                            neutral
                        />
                        <Figure
                            label="Credits in period"
                            value={ledger.movement_credit}
                            currency={baseCurrency}
                            neutral
                        />
                        <Figure
                            label={`Closing ${filters.to}`}
                            value={ledger.closing}
                            currency={baseCurrency}
                            emphasis
                        />
                    </div>

                    <Card flush>
                        <header className="border-line-subtle flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                            <h2 className="text-content text-md font-semibold">
                                <span className="tabular-nums">{selected.code}</span>{' '}
                                {selected.name}
                            </h2>
                            <div className="flex items-center gap-2">
                                <Badge tone="neutral">{selected.type_label}</Badge>
                                <span className="text-content-muted text-xs">
                                    Normally {selected.normal_balance}
                                </span>
                            </div>
                        </header>

                        {ledger.rows.length === 0 ? (
                            <EmptyState
                                icon={BookOpenCheck}
                                title="Nothing posted in this period"
                                description={
                                    <>
                                        The account opened at{' '}
                                        <span className="text-content font-medium">
                                            {formatMoney(ledger.opening, {
                                                currency: baseCurrency,
                                            })}
                                        </span>{' '}
                                        and has not moved between these dates. Widen the range to
                                        see earlier entries.
                                    </>
                                }
                            />
                        ) : (
                            <div className="table-scroll">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                            <th className="px-4 py-2 text-left font-medium">
                                                Date
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Entry
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Source
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Memo
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Debit
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Credit
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Balance
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-line-subtle divide-y">
                                        <tr className="bg-surface-sunken">
                                            <td
                                                className="text-content-secondary px-4 py-2 text-xs italic"
                                                colSpan={6}
                                            >
                                                Opening balance
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-2 text-right font-medium tabular-nums',
                                                    isNegative(ledger.opening)
                                                        ? 'text-danger-600 dark:text-danger-400'
                                                        : 'text-content',
                                                )}
                                            >
                                                {formatMoney(ledger.opening, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                        </tr>

                                        {ledger.rows.map((row, index) => (
                                            <tr
                                                key={`${row.entry_no}-${index}`}
                                                className={cn(
                                                    'hover:bg-surface-hover',
                                                    row.status === 'reversed' &&
                                                        'text-content-muted',
                                                )}
                                            >
                                                <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                                    {row.entry_date}
                                                </td>

                                                <td className="px-4 py-2.5">
                                                    <Link
                                                        href={`/accounting/journals/${row.entry_no}`}
                                                        className="text-brand-text tabular-nums hover:underline"
                                                    >
                                                        {row.entry_no}
                                                    </Link>
                                                </td>

                                                <td className="text-content-secondary px-4 py-2.5">
                                                    {humanise(row.source_type)}
                                                </td>

                                                <td className="text-content-muted max-w-xs truncate px-4 py-2.5">
                                                    {row.memo ?? '—'}
                                                </td>

                                                <Cell value={row.debit} currency={baseCurrency} />
                                                <Cell value={row.credit} currency={baseCurrency} />

                                                <td
                                                    className={cn(
                                                        'px-4 py-2.5 text-right font-medium tabular-nums',
                                                        isNegative(row.balance)
                                                            ? 'text-danger-600 dark:text-danger-400'
                                                            : 'text-content',
                                                    )}
                                                >
                                                    {formatMoney(row.balance, {
                                                        currency: baseCurrency,
                                                        showCurrency: false,
                                                    })}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>

                                    <tfoot>
                                        <tr className="border-line-subtle bg-surface-sunken text-content border-t-2 font-medium">
                                            <td className="px-4 py-2.5" colSpan={4}>
                                                Closing balance
                                            </td>
                                            <Cell
                                                value={ledger.movement_debit}
                                                currency={baseCurrency}
                                            />
                                            <Cell
                                                value={ledger.movement_credit}
                                                currency={baseCurrency}
                                            />
                                            <td
                                                className={cn(
                                                    'px-4 py-2.5 text-right tabular-nums',
                                                    isNegative(ledger.closing)
                                                        ? 'text-danger-600 dark:text-danger-400'
                                                        : 'text-content',
                                                )}
                                            >
                                                {formatMoney(ledger.closing, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </Card>
                </>
            )}
        </AppLayout>
    );
}

function Cell({ value, currency }: { value: string; currency: string }) {
    // A nil movement renders as a dash. A column of 0.00 reads as a column of
    // figures, and hides the rows that actually moved.
    const nil = isZero(value);

    return (
        <td
            className={cn(
                'px-4 py-2.5 text-right tabular-nums',
                nil ? 'text-content-disabled' : 'text-content',
            )}
        >
            {nil ? '—' : formatMoney(value, { currency, showCurrency: false })}
        </td>
    );
}

function Figure({
    label,
    value,
    currency,
    emphasis = false,
    neutral = false,
}: {
    label: string;
    value: string;
    currency: string;
    emphasis?: boolean;
    neutral?: boolean;
}) {
    return (
        <Card className={emphasis ? 'border-line-brand' : undefined}>
            <p className="text-content-muted text-2xs uppercase">{label}</p>
            <p
                className={cn(
                    'mt-1 tabular-nums',
                    emphasis ? 'text-lg font-semibold' : 'text-md font-medium',
                    !neutral && isNegative(value)
                        ? 'text-danger-600 dark:text-danger-400'
                        : 'text-content',
                )}
            >
                {formatMoney(value, { currency, showCurrency: false })}
            </p>
        </Card>
    );
}

function humanise(value: string): string {
    const spaced = value.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
