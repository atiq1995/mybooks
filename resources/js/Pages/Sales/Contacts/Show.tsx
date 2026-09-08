import { Head, Link, router } from '@inertiajs/react';
import { FileText, Plus, Wallet } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { EmptyState } from '@ui/States';
import { formatMoney, isNegative, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface StatementRow {
    id: string;
    number: string;
    type: string;
    type_label: string;
    issue_date: string;
    due_date: string | null;
    status: string;
    status_label: string;
    status_tone: string;
    total: string;
    paid: string;
    credited: string;
    balance_due: string;
    running_balance: string;
    is_overdue: boolean;
    days_overdue: number;
}

interface ShowProps {
    contact: {
        id: string;
        display_name: string;
        legal_name: string | null;
        kind: string;
        kind_label: string;
        email: string | null;
        phone: string | null;
        website: string | null;
        tax_registration_number: string | null;
        sales_tax_registration_number: string | null;
        is_tax_filer: boolean;
        currency: string;
        payment_terms_days: number;
        credit_limit: string | null;
        billing_address: Record<string, string> | null;
        notes: string | null;
        is_archived: boolean;
        outstanding: string;
    };
    statement: { rows: StatementRow[]; closing: string };
    aging: Record<string, string>;
    can: { update: boolean; invoice: boolean };
}

const BUCKETS: { key: string; label: string }[] = [
    { key: 'current', label: 'Not yet due' },
    { key: '1_30', label: '1–30 days' },
    { key: '31_60', label: '31–60 days' },
    { key: '61_90', label: '61–90 days' },
    { key: 'over_90', label: 'Over 90 days' },
];

/**
 * A customer, and the statement that reconciles to the AR control account.
 *
 * The statement is the point: every issued document in date order with a
 * running balance, which is exactly what a customer disputing a figure asks
 * for. A voided document stays on it at zero, so the numbering has no
 * unexplained gap.
 */
export default function ContactShow({ contact, statement, aging, can }: ShowProps) {
    const currency = contact.currency;

    return (
        <AppLayout
            title={contact.display_name}
            description={`${contact.kind_label}${contact.legal_name === null ? '' : ` · ${contact.legal_name}`}`}
            breadcrumbs={[
                { label: 'Sales' },
                { label: 'Customers', href: '/sales/customers' },
                { label: contact.display_name },
            ]}
            actions={
                <div className="flex items-center gap-2">
                    {!isZero(contact.outstanding) && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<Wallet aria-hidden="true" />}
                            onClick={() => router.get(`/sales/payments/new?contact=${contact.id}`)}
                        >
                            Record payment
                        </Button>
                    )}
                    {can.invoice && !contact.is_archived && (
                        <Button
                            variant="primary"
                            size="md"
                            icon={<Plus aria-hidden="true" />}
                            onClick={() => router.get('/sales/invoices/new')}
                        >
                            New invoice
                        </Button>
                    )}
                </div>
            }
        >
            <Head title={contact.display_name} />

            <div className="mb-4 grid gap-4 lg:grid-cols-[1fr_20rem]">
                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">Ageing</h2>
                        <p className="text-content-muted text-xs">
                            How old the outstanding balance is, by how far past due each invoice has
                            gone — not by how long ago it was issued.
                        </p>
                    </header>

                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
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

                            <tbody>
                                <tr>
                                    {BUCKETS.map((bucket, index) => (
                                        <td
                                            key={bucket.key}
                                            className={cn(
                                                'px-4 py-3 text-right tabular-nums',
                                                isZero(aging[bucket.key] ?? '0')
                                                    ? 'text-content-disabled'
                                                    : index >= 3
                                                      ? 'text-danger-600 dark:text-danger-400 font-medium'
                                                      : 'text-content',
                                            )}
                                        >
                                            {isZero(aging[bucket.key] ?? '0')
                                                ? '—'
                                                : formatMoney(aging[bucket.key] ?? '0', {
                                                      currency,
                                                      showCurrency: false,
                                                  })}
                                        </td>
                                    ))}
                                    <td className="text-content px-4 py-3 text-right font-semibold tabular-nums">
                                        {formatMoney(contact.outstanding, {
                                            currency,
                                            showCurrency: false,
                                        })}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card>
                    <dl className="flex flex-col gap-2 text-sm">
                        <Detail label="Terms">
                            {contact.payment_terms_days === 0
                                ? 'On receipt'
                                : `${contact.payment_terms_days} days`}
                        </Detail>
                        <Detail label="Currency">{currency}</Detail>
                        <Detail label="Email">{contact.email ?? '—'}</Detail>
                        <Detail label="Phone">{contact.phone ?? '—'}</Detail>
                        <Detail label="NTN">{contact.tax_registration_number ?? '—'}</Detail>
                        <Detail label="STRN">{contact.sales_tax_registration_number ?? '—'}</Detail>
                        <Detail label="Tax status">
                            {contact.is_tax_filer ? (
                                'Filer'
                            ) : (
                                <Badge tone="warning">Non-filer</Badge>
                            )}
                        </Detail>
                        {contact.credit_limit !== null && (
                            <Detail label="Credit limit">
                                {formatMoney(contact.credit_limit, { currency })}
                            </Detail>
                        )}
                    </dl>
                </Card>
            </div>

            <Card flush>
                <header className="border-line-subtle flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                    <div>
                        <h2 className="text-content text-md font-semibold">Statement</h2>
                        <p className="text-content-muted text-xs">
                            Every issued invoice and credit note, in date order.
                        </p>
                    </div>
                    <p className="text-content text-sm font-semibold tabular-nums">
                        {formatMoney(statement.closing, { currency })}
                    </p>
                </header>

                {statement.rows.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title="Nothing issued yet"
                        description={`Once you invoice ${contact.display_name}, the statement builds itself.`}
                        action={
                            can.invoice ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => router.get('/sales/invoices/new')}
                                >
                                    New invoice
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Date</th>
                                    <th className="px-4 py-2 text-left font-medium">Document</th>
                                    <th className="px-4 py-2 text-left font-medium">Due</th>
                                    <th className="px-4 py-2 text-right font-medium">Charged</th>
                                    <th className="px-4 py-2 text-right font-medium">Paid</th>
                                    <th className="px-4 py-2 text-right font-medium">Balance</th>
                                    <th className="px-4 py-2 text-left font-medium">Status</th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {statement.rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        className={cn(
                                            'hover:bg-surface-hover',
                                            row.status === 'void' && 'opacity-60',
                                        )}
                                    >
                                        <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                            {row.issue_date}
                                        </td>

                                        <td className="px-4 py-2.5">
                                            <Link
                                                href={`/sales/${urlFor(row.type)}/${row.number}`}
                                                className="text-brand-text font-medium tabular-nums hover:underline"
                                            >
                                                {row.number}
                                            </Link>
                                            <span className="text-content-muted ml-2 text-xs">
                                                {row.type_label}
                                            </span>
                                        </td>

                                        <td
                                            className={cn(
                                                'px-4 py-2.5 tabular-nums',
                                                row.is_overdue
                                                    ? 'text-danger-600 dark:text-danger-400'
                                                    : 'text-content-secondary',
                                            )}
                                        >
                                            {row.due_date ?? '—'}
                                        </td>

                                        <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                            {row.type === 'credit_note' ? '-' : ''}
                                            {formatMoney(row.total, {
                                                currency,
                                                showCurrency: false,
                                            })}
                                        </td>

                                        <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                            {isZero(row.paid)
                                                ? '—'
                                                : formatMoney(row.paid, {
                                                      currency,
                                                      showCurrency: false,
                                                  })}
                                        </td>

                                        <td
                                            className={cn(
                                                'px-4 py-2.5 text-right font-medium tabular-nums',
                                                isNegative(row.running_balance)
                                                    ? 'text-success-700 dark:text-success-400'
                                                    : 'text-content',
                                            )}
                                        >
                                            {formatMoney(row.running_balance, {
                                                currency,
                                                showCurrency: false,
                                            })}
                                        </td>

                                        <td className="px-4 py-2.5">
                                            <Badge
                                                tone={
                                                    row.is_overdue
                                                        ? 'danger'
                                                        : ((row.status_tone as BadgeTone) ??
                                                          'neutral')
                                                }
                                            >
                                                {row.is_overdue ? 'Overdue' : row.status_label}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>

                            <tfoot>
                                <tr className="border-line-subtle bg-surface-sunken text-content border-t-2 font-semibold">
                                    <td className="px-4 py-2.5" colSpan={5}>
                                        Closing balance
                                    </td>
                                    <td className="px-4 py-2.5 text-right tabular-nums">
                                        {formatMoney(statement.closing, {
                                            currency,
                                            showCurrency: false,
                                        })}
                                    </td>
                                    <td />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </Card>
        </AppLayout>
    );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="text-content-muted text-xs">{label}</dt>
            <dd className="text-content-secondary text-right">{children}</dd>
        </div>
    );
}

function urlFor(type: string): string {
    return type === 'credit_note' ? 'credit-notes' : 'invoices';
}
