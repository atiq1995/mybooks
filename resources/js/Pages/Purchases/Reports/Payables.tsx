import { Fragment, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Scale,
} from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { EmptyState } from '@ui/States';
import { formatMoney, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface AgedBill {
    id: string;
    number: string;
    vendor_reference: string | null;
    issue_date: string;
    due_date: string | null;
    currency: string;
    total: string;
    balance_due: string;
    days_overdue: number;
    bucket: string;
}

interface AgedVendor {
    contact_id: string;
    contact_name: string;
    contact_email: string | null;
    buckets: Record<string, string>;
    total: string;
    oldest_days: number;
    soonest_due: string | null;
    bills: AgedBill[];
}

interface PayablesProps {
    rows: AgedVendor[];
    totals: Record<string, string>;
    reconciliation: {
        aged: string;
        control: string;
        difference: string;
        reconciles: boolean;
    };
    dueSoon: {
        within_7_days: string;
        overdue: string;
        count_within_7_days: number;
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
 * Accounts payable ageing.
 *
 * The mirror of the receivables report, ordered the other way round — soonest
 * due first — because the reader of this page is deciding what to pay this
 * week, and a list headed by a year-old disputed invoice would bury the bill
 * due on Friday.
 *
 * Two figures sit above the table for the same reason: what falls due in the
 * next seven days, and what is already late. Neither is visible in the ageing
 * buckets, where "not yet due" mixes tomorrow with two months from now.
 *
 * The reconciliation banner is the other half of its job, and it means the
 * same here as on the receivables side: a payables report that quietly
 * disagrees with the balance sheet leaves somebody paying from a figure
 * nobody can vouch for.
 */
export default function Payables({
    rows,
    totals,
    reconciliation,
    dueSoon,
    filters,
    baseCurrency,
}: PayablesProps) {
    const [expanded, setExpanded] = useState<string | null>(null);

    const setAsOf = (as_of: string) => {
        router.get(
            '/purchases/payables',
            { as_of },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            title="Payables ageing"
            description={`What you owe, and when it falls due. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Purchases' }, { label: 'Payables' }]}
        >
            <Head title="Payables ageing" />

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
                            ? `The aged total equals the payables control account: ${formatMoney(
                                  reconciliation.control,
                                  { currency: baseCurrency },
                              )}.`
                            : `The bills total ${formatMoney(reconciliation.aged, {
                                  currency: baseCurrency,
                                  showCurrency: false,
                              })} while the control account holds ${formatMoney(
                                  reconciliation.control,
                                  { currency: baseCurrency, showCurrency: false },
                              )}. A journal posted straight to payables would explain it — but until it does, neither figure can be relied on.`}
                    </p>
                </div>
            </div>

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <Card className="border-line-brand">
                    <p className="text-content-muted text-2xs flex items-center gap-1.5 uppercase">
                        <CalendarClock className="size-3" aria-hidden="true" />
                        Due within 7 days
                    </p>
                    <p className="text-content mt-1 text-lg font-semibold tabular-nums">
                        {formatMoney(dueSoon.within_7_days, {
                            currency: baseCurrency,
                            showCurrency: false,
                        })}
                    </p>
                    <p className="text-content-muted text-xs">
                        {dueSoon.count_within_7_days === 0
                            ? 'Nothing falls due this week.'
                            : `${dueSoon.count_within_7_days} ${
                                  dueSoon.count_within_7_days === 1 ? 'bill' : 'bills'
                              }.`}
                    </p>
                </Card>

                <Card className={isZero(dueSoon.overdue) ? undefined : 'border-line-danger'}>
                    <p className="text-content-muted text-2xs flex items-center gap-1.5 uppercase">
                        {!isZero(dueSoon.overdue) && (
                            <AlertTriangle className="size-3" aria-hidden="true" />
                        )}
                        Already late
                    </p>
                    <p
                        className={cn(
                            'mt-1 text-lg font-semibold tabular-nums',
                            isZero(dueSoon.overdue)
                                ? 'text-content'
                                : 'text-danger-600 dark:text-danger-400',
                        )}
                    >
                        {formatMoney(dueSoon.overdue, {
                            currency: baseCurrency,
                            showCurrency: false,
                        })}
                    </p>
                </Card>

                <Card>
                    <Input
                        label="As at"
                        name="as_of"
                        type="date"
                        value={filters.as_of}
                        onChange={(e) => setAsOf(e.target.value)}
                        hint="Ageing runs from each bill's due date."
                    />
                </Card>
            </div>

            {rows.length === 0 ? (
                <Card flush>
                    <EmptyState
                        icon={Scale}
                        title="Nothing owed"
                        description="Every approved bill has been paid or credited. There is nothing outstanding."
                    />
                </Card>
            ) : (
                <Card flush>
                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Vendor</th>
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
                                 * identify a vendor by when a new as-at date
                                 * re-orders the list.
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
                                                        aria-label={`Show bills for ${row.contact_name}`}
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

                                                    {row.soonest_due !== null && (
                                                        <span className="text-content-muted text-xs tabular-nums">
                                                            next {row.soonest_due}
                                                        </span>
                                                    )}

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
                                            row.bills.map((bill) => (
                                                <tr
                                                    key={bill.id}
                                                    className="bg-surface-sunken text-xs"
                                                >
                                                    <td className="px-4 py-1.5 pl-12">
                                                        <Link
                                                            href={`/purchases/bills/${bill.number}`}
                                                            className="text-brand-text tabular-nums hover:underline"
                                                        >
                                                            {bill.number}
                                                        </Link>
                                                        <span className="text-content-muted ml-2">
                                                            due {bill.due_date ?? '—'}
                                                            {bill.days_overdue > 0 &&
                                                                ` · ${bill.days_overdue} days late`}
                                                            {bill.vendor_reference !== null &&
                                                                ` · their ${bill.vendor_reference}`}
                                                        </span>
                                                    </td>

                                                    {BUCKETS.map((bucket) => (
                                                        <td
                                                            key={bucket.key}
                                                            className="text-content-secondary px-4 py-1.5 text-right tabular-nums"
                                                        >
                                                            {bill.bucket === bucket.key
                                                                ? formatMoney(bill.balance_due, {
                                                                      currency: bill.currency,
                                                                      showCurrency: false,
                                                                  })
                                                                : ''}
                                                        </td>
                                                    ))}

                                                    <td className="text-content-secondary px-4 py-1.5 text-right tabular-nums">
                                                        {formatMoney(bill.balance_due, {
                                                            currency: bill.currency,
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
