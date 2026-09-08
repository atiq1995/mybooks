import { useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Scale } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Button } from '@ui/Button';
import { EmptyState } from '@ui/States';
import { formatMoney, isZero, sumForDisplay } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface TrialBalanceLine {
    id: string;
    code: string;
    name: string;
    type: string;
    type_label: string;
    order: number;
    debit: string;
    credit: string;
}

interface TrialBalanceProps {
    lines: TrialBalanceLine[];
    totals: { debit: string; credit: string; difference: string; balances: boolean };
    filters: { as_of: string; zero: boolean };
    baseCurrency: string;
}

/**
 * The trial balance.
 *
 * One figure on this page matters more than all the others: whether debits
 * equal credits. If they do not, the balance sheet cannot balance and every
 * report drawn from these books is suspect — so the answer is stated
 * outright at the top, in words, rather than left for the reader to subtract
 * two columns and work out.
 *
 * @see ACCOUNTING_RULES.md I11
 */
export default function TrialBalance({ lines, totals, filters, baseCurrency }: TrialBalanceProps) {
    const grouped = useMemo(() => {
        const groups = new Map<
            string,
            { label: string; order: number; rows: TrialBalanceLine[] }
        >();

        for (const line of lines) {
            const group = groups.get(line.type) ?? {
                label: line.type_label,
                order: line.order,
                rows: [],
            };

            group.rows.push(line);
            groups.set(line.type, group);
        }

        // Assets, liabilities, equity, income, expense — the order every
        // accountant reads a trial balance in.
        return [...groups.values()].sort((a, b) => a.order - b.order);
    }, [lines]);

    const apply = (next: Partial<{ as_of: string; zero: boolean }>) => {
        const merged = { ...filters, ...next };

        router.get(
            '/accounting/trial-balance',
            {
                as_of: merged.as_of,
                ...(merged.zero ? { zero: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            title="Trial Balance"
            description={`Every account's net position as at a date, in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Accounting' }, { label: 'Trial Balance' }]}
            actions={
                <Button
                    variant="secondary"
                    size="md"
                    onClick={() => apply({ zero: !filters.zero })}
                >
                    {filters.zero ? 'Hide nil accounts' : 'Show nil accounts'}
                </Button>
            }
        >
            <Head title="Trial Balance" />

            {/*
                The verdict, first. A page that shows two columns and lets the
                reader discover a discrepancy by squinting has buried the one
                thing it exists to report.
            */}
            <div
                className={cn(
                    'mb-4 flex flex-wrap items-center gap-3 rounded-md border px-4 py-3',
                    totals.balances
                        ? 'border-status-success-line bg-status-success text-status-success-fg'
                        : 'border-line-danger bg-status-danger text-status-danger-fg',
                )}
                role={totals.balances ? undefined : 'alert'}
            >
                {totals.balances ? (
                    <CheckCircle2 className="size-5 shrink-0" aria-hidden="true" />
                ) : (
                    <AlertTriangle className="size-5 shrink-0" aria-hidden="true" />
                )}

                <div className="min-w-0">
                    <p className="text-sm font-semibold">
                        {totals.balances
                            ? 'The books balance.'
                            : `The books are out by ${formatMoney(totals.difference, {
                                  currency: baseCurrency,
                              })}.`}
                    </p>
                    <p className="text-xs opacity-90">
                        {totals.balances
                            ? 'Total debits equal total credits, as at this date.'
                            : 'Debits and credits disagree, so the balance sheet cannot balance. ' +
                              'Do not rely on any report until this is investigated — run ' +
                              'my-books:verify-ledger for the detail.'}
                    </p>
                </div>
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-[12rem_1fr]">
                    <Input
                        label="As at"
                        type="date"
                        value={filters.as_of}
                        onChange={(e) => apply({ as_of: e.target.value })}
                        hint="Includes everything posted on or before this date."
                    />
                </div>
            </Card>

            {lines.length === 0 ? (
                <Card flush>
                    <EmptyState
                        icon={Scale}
                        title="Nothing to balance yet"
                        description="Once entries are posted, every account's net position appears here."
                    />
                </Card>
            ) : (
                <Card flush>
                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Account</th>
                                    <th className="px-4 py-2 text-right font-medium">Debit</th>
                                    <th className="px-4 py-2 text-right font-medium">Credit</th>
                                </tr>
                            </thead>

                            {grouped.map((group) => (
                                <tbody key={group.label} className="divide-line-subtle divide-y">
                                    <tr className="bg-surface-sunken">
                                        <th
                                            colSpan={3}
                                            scope="colgroup"
                                            className="text-content text-2xs px-4 py-1.5 text-left font-semibold uppercase"
                                        >
                                            {group.label}
                                        </th>
                                    </tr>

                                    {group.rows.map((line) => (
                                        <tr key={line.id} className="hover:bg-surface-hover">
                                            <td className="px-4 py-2">
                                                <Link
                                                    href={`/accounting/general-ledger?account=${line.id}`}
                                                    className="hover:underline"
                                                >
                                                    <span className="text-content font-medium tabular-nums">
                                                        {line.code}
                                                    </span>
                                                    <span className="text-content-secondary ml-2">
                                                        {line.name}
                                                    </span>
                                                </Link>
                                            </td>

                                            <Cell value={line.debit} currency={baseCurrency} />
                                            <Cell value={line.credit} currency={baseCurrency} />
                                        </tr>
                                    ))}

                                    <tr>
                                        <td className="text-content-muted px-4 py-1.5 pl-8 text-xs">
                                            {group.label} subtotal
                                        </td>
                                        {/* Group subtotals only. The authoritative
                                            totals row below comes from the server. */}
                                        <Cell
                                            value={sumForDisplay(group.rows.map((r) => r.debit))}
                                            currency={baseCurrency}
                                            muted
                                        />
                                        <Cell
                                            value={sumForDisplay(group.rows.map((r) => r.credit))}
                                            currency={baseCurrency}
                                            muted
                                        />
                                    </tr>
                                </tbody>
                            ))}

                            <tfoot>
                                <tr
                                    className={cn(
                                        'border-t-2 font-semibold',
                                        totals.balances
                                            ? 'border-status-success-line bg-status-success text-status-success-fg'
                                            : 'border-line-danger bg-status-danger text-status-danger-fg',
                                    )}
                                >
                                    <td className="px-4 py-3">Total</td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatMoney(totals.debit, {
                                            currency: baseCurrency,
                                            showCurrency: false,
                                        })}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatMoney(totals.credit, {
                                            currency: baseCurrency,
                                            showCurrency: false,
                                        })}
                                    </td>
                                </tr>

                                {!totals.balances && (
                                    <tr className="text-status-danger-fg bg-status-danger">
                                        <td className="px-4 py-2 text-sm" colSpan={2}>
                                            Difference
                                        </td>
                                        <td className="px-4 py-2 text-right font-semibold tabular-nums">
                                            {formatMoney(totals.difference, {
                                                currency: baseCurrency,
                                                showCurrency: false,
                                                signDisplay: 'always',
                                            })}
                                        </td>
                                    </tr>
                                )}
                            </tfoot>
                        </table>
                    </div>
                </Card>
            )}
        </AppLayout>
    );
}

function Cell({
    value,
    currency,
    muted = false,
}: {
    value: string;
    currency: string;
    muted?: boolean;
}) {
    const nil = isZero(value);

    return (
        <td
            className={cn(
                'px-4 py-2 text-right tabular-nums',
                nil
                    ? 'text-content-disabled'
                    : muted
                      ? 'text-content-muted text-xs'
                      : 'text-content',
            )}
        >
            {nil ? '—' : formatMoney(value, { currency, showCurrency: false })}
        </td>
    );
}
