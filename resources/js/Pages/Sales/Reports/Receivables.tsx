import { Fragment, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Scale } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { EmptyState } from '@ui/States';
import { formatMoney, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface AgedInvoice {
    id: string;
    number: string;
    issue_date: string;
    due_date: string | null;
    currency: string;
    total: string;
    balance_due: string;
    days_overdue: number;
    bucket: string;
}

interface AgedContact {
    contact_id: string;
    contact_name: string;
    contact_email: string | null;
    buckets: Record<string, string>;
    total: string;
    oldest_days: number;
    invoices: AgedInvoice[];
}

interface ReceivablesProps {
    rows: AgedContact[];
    totals: Record<string, string>;
    reconciliation: {
        aged: string;
        control: string;
        difference: string;
        reconciles: boolean;
    };
    filters: { as_of: string };
    baseCurrency: string;
}

const BUCKETS: { key: string; label: string; overdue: boolean }[] = [
    { key: 'current', label: 'Not yet due', overdue: false },
    { key: '1_30', label: '1–30 days', overdue: true },
    { key: '31_60', label: '31–60 days', overdue: true },
    { key: '61_90', label: '61–90 days', overdue: true },
    { key: 'over_90', label: 'Over 90 days', overdue: true },
];

/**
 * Accounts receivable ageing.
 *
 * Ordered oldest-money-first, because the report exists to answer one
 * question: who is worth chasing today. An alphabetical list would make the
 * reader do that sorting in their head.
 *
 * The reconciliation banner is the other half of its job. An ageing report
 * that quietly disagrees with the AR control account is worse than no report
 * — somebody chases the wrong customer while the real discrepancy stays
 * hidden — so whether the two agree is stated at the top, in words.
 */
export default function Receivables({
    rows,
    totals,
    reconciliation,
    filters,
    baseCurrency,
}: ReceivablesProps) {
    const [expanded, setExpanded] = useState<string | null>(null);

    const setAsOf = (as_of: string) => {
        router.get(
            '/sales/receivables',
            { as_of },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            title="Receivables ageing"
            description={`What customers owe, and for how long. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Sales' }, { label: 'Receivables' }]}
        >
            <Head title="Receivables ageing" />

            <div
                className={cn(
                    'mb-4 flex flex-wrap items-center gap-3 rounded-md border px-4 py-3',
                    reconciliation.reconciles
                        ? 'border-status-success-line bg-status-success text-status-success-fg'
                        : 'border-line-danger bg-status-danger text-status-danger-fg',
                )}
                role={reconciliation.reconciles ? undefined : 'alert'}
            >
                {reconciliation.reconciles ? (
                    <CheckCircle2 className="size-5 shrink-0" aria-hidden="true" />
                ) : (
                    <AlertTriangle className="size-5 shrink-0" aria-hidden="true" />
                )}

                <div className="min-w-0">
                    <p className="text-sm font-semibold">
                        {reconciliation.reconciles
                            ? 'This report reconciles to the ledger.'
                            : `This report is out by ${formatMoney(reconciliation.difference, {
                                  currency: baseCurrency,
                              })}.`}
                    </p>
                    <p className="text-xs opacity-90">
                        {reconciliation.reconciles
                            ? `The aged total equals the receivables control account: ${formatMoney(
                                  reconciliation.control,
                                  { currency: baseCurrency },
                              )}.`
                            : `The invoices total ${formatMoney(reconciliation.aged, {
                                  currency: baseCurrency,
                                  showCurrency: false,
                              })} while the control account holds ${formatMoney(
                                  reconciliation.control,
                                  { currency: baseCurrency, showCurrency: false },
                              )}. A journal posted straight to receivables would explain it — but until it does, neither figure can be relied on.`}
                    </p>
                </div>
            </div>

            <Card className="mb-4">
                <Input
                    label="As at"
                    name="as_of"
                    type="date"
                    value={filters.as_of}
                    onChange={(e) => setAsOf(e.target.value)}
                    hint="Ageing is measured from each invoice's due date, not its issue date."
                    containerClassName="w-48"
                />
            </Card>

            {rows.length === 0 ? (
                <Card flush>
                    <EmptyState
                        icon={Scale}
                        title="Nothing outstanding"
                        description="Every issued invoice has been settled or credited. There is nothing to chase."
                    />
                </Card>
            ) : (
                <Card flush>
                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Customer</th>
                                    {BUCKETS.map((bucket) => (
                                        <th
                                            key={bucket.key}
                                            className="px-4 py-2 text-right font-medium"
                                        >
                                            {bucket.label}
                                        </th>
                                    ))}
                                    <th className="px-4 py-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {/*
                                 * The key belongs on the fragment, not on the
                                 * first row inside it: the fragment is what
                                 * map() returns, so React has nothing to
                                 * identify a customer by when the list is
                                 * re-ordered by a new as-at date.
                                 */}
                                {rows.map((row) => (
                                    <Fragment key={row.contact_id}>
                                        <tr className="hover:bg-surface-hover">
                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setExpanded(
                                                                expanded === row.contact_id
                                                                    ? null
                                                                    : row.contact_id,
                                                            )
                                                        }
                                                        aria-label={`Show invoices for ${row.contact_name}`}
                                                        aria-expanded={expanded === row.contact_id}
                                                        className="text-content-muted hover:text-content"
                                                    >
                                                        {expanded === row.contact_id ? (
                                                            <ChevronDown
                                                                className="size-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <ChevronRight
                                                                className="size-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                    </button>

                                                    <Link
                                                        href={`/sales/customers/${row.contact_id}`}
                                                        className="text-brand-text font-medium hover:underline"
                                                    >
                                                        {row.contact_name}
                                                    </Link>

                                                    {row.oldest_days > 90 && (
                                                        <Badge tone="danger">
                                                            {row.oldest_days}d
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>

                                            {BUCKETS.map((bucket) => (
                                                <Cell
                                                    key={bucket.key}
                                                    value={row.buckets[bucket.key] ?? '0'}
                                                    currency={baseCurrency}
                                                    danger={bucket.key === 'over_90'}
                                                />
                                            ))}

                                            <td className="text-content px-4 py-2.5 text-right font-semibold tabular-nums">
                                                {formatMoney(row.total, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                        </tr>

                                        {expanded === row.contact_id &&
                                            row.invoices.map((invoice) => (
                                                <tr
                                                    key={invoice.id}
                                                    className="bg-surface-sunken text-xs"
                                                >
                                                    <td className="px-4 py-1.5 pl-12">
                                                        <Link
                                                            href={`/sales/invoices/${invoice.number}`}
                                                            className="text-brand-text tabular-nums hover:underline"
                                                        >
                                                            {invoice.number}
                                                        </Link>
                                                        <span className="text-content-muted ml-2">
                                                            due {invoice.due_date ?? '—'}
                                                            {invoice.days_overdue > 0 &&
                                                                ` · ${invoice.days_overdue} days late`}
                                                        </span>
                                                    </td>

                                                    {BUCKETS.map((bucket) => (
                                                        <td
                                                            key={bucket.key}
                                                            className="text-content-secondary px-4 py-1.5 text-right tabular-nums"
                                                        >
                                                            {invoice.bucket === bucket.key
                                                                ? formatMoney(invoice.balance_due, {
                                                                      currency: invoice.currency,
                                                                      showCurrency: false,
                                                                  })
                                                                : ''}
                                                        </td>
                                                    ))}

                                                    <td className="text-content-secondary px-4 py-1.5 text-right tabular-nums">
                                                        {formatMoney(invoice.balance_due, {
                                                            currency: invoice.currency,
                                                            showCurrency: false,
                                                        })}
                                                    </td>
                                                </tr>
                                            ))}
                                    </Fragment>
                                ))}
                            </tbody>

                            <tfoot>
                                <tr className="border-line-subtle bg-surface-sunken text-content border-t-2 font-semibold">
                                    <td className="px-4 py-3">Total</td>

                                    {BUCKETS.map((bucket) => (
                                        <Cell
                                            key={bucket.key}
                                            value={totals[bucket.key] ?? '0'}
                                            currency={baseCurrency}
                                            danger={bucket.key === 'over_90'}
                                        />
                                    ))}

                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {formatMoney(totals.total ?? '0', {
                                            currency: baseCurrency,
                                            showCurrency: false,
                                        })}
                                    </td>
                                </tr>
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
    danger = false,
}: {
    value: string;
    currency: string;
    danger?: boolean;
}) {
    const nil = isZero(value);

    return (
        <td
            className={cn(
                'px-4 py-2.5 text-right tabular-nums',
                nil
                    ? 'text-content-disabled'
                    : danger
                      ? 'text-danger-600 dark:text-danger-400 font-medium'
                      : 'text-content-secondary',
            )}
        >
            {nil ? '—' : formatMoney(value, { currency, showCurrency: false })}
        </td>
    );
}
