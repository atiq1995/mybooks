import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, FileText, Plus } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

export interface PurchaseSummary {
    id: string;
    number: string;
    contact_id: string;
    contact_name: string | null;
    issue_date: string;
    due_date: string | null;
    vendor_reference: string | null;
    reference: string | null;
    status: string;
    status_label: string;
    status_tone: string;
    currency: string;
    exchange_rate: string;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    tax_claimable_total: string;
    tax_capitalised: string;
    total: string;
    total_base: string;
    amount_paid: string;
    amount_credited: string;
    balance_due: string;
    is_overdue: boolean;
    days_overdue: number;
    is_editable: boolean;
    is_issued: boolean;
    is_void: boolean;
    is_foreign_currency: boolean;
}

export interface PurchaseTypeProps {
    value: string;
    label: string;
    plural: string;
    segment: string;
    posts: boolean;
    has_due_date: boolean;
    issue_verb: string;
    convertible_to: { value: string; label: string }[];
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface IndexProps {
    type: PurchaseTypeProps;
    documents: {
        data: PurchaseSummary[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: { outstanding: string; overdue: string; draft: string };
    filters: { search: string; status: string; contact: string };
    statuses: { value: string; label: string }[];
    baseCurrency: string;
    can: Record<string, boolean>;
}

/**
 * A list of one kind of purchase document.
 *
 * The three figures at the top are the point of the screen, and they are not
 * the sales screen's three read backwards: what we owe, how much of it is
 * already late, and what is sitting in draft waiting for somebody to approve
 * it. That last figure is the one nobody expects to care about and everybody
 * does — a bill nobody approved is a liability the books do not know about.
 *
 * The vendor's own reference is shown beside our number, because it is what
 * they will quote when they ring.
 */
export default function PurchaseDocumentsIndex({
    type,
    documents,
    summary,
    filters,
    statuses,
    baseCurrency,
    can,
}: IndexProps) {
    const [draft, setDraft] = useState(filters);

    const apply = (next: Partial<typeof filters>) => {
        const merged = { ...draft, ...next };
        setDraft(merged);

        router.get(`/purchases/${type.segment}`, pruneEmpty(merged), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const clear = () => {
        const empty = { search: '', status: '', contact: '' };
        setDraft(empty);
        router.get(`/purchases/${type.segment}`, {}, { preserveState: true, replace: true });
    };

    const isFiltered = Object.values(filters).some((value) => value !== '');

    return (
        <AppLayout
            title={type.plural}
            description={
                type.posts
                    ? `Approved ${type.plural.toLowerCase()} post to the ledger. Amounts in ${baseCurrency} unless a document says otherwise.`
                    : `A commitment, not a financial event — a ${type.label.toLowerCase()} never posts to the ledger.`
            }
            breadcrumbs={[{ label: 'Purchases' }, { label: type.plural }]}
            actions={
                can.create ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => router.get(`/purchases/${type.segment}/new`)}
                    >
                        New {type.label.toLowerCase()}
                    </Button>
                ) : undefined
            }
        >
            <Head title={type.plural} />

            {type.posts && (
                <div className="mb-4 grid gap-4 sm:grid-cols-3">
                    <Figure
                        label="Owed"
                        value={summary.outstanding}
                        currency={baseCurrency}
                        emphasis
                    />
                    <Figure
                        label="Overdue"
                        value={summary.overdue}
                        currency={baseCurrency}
                        tone={isZero(summary.overdue) ? 'neutral' : 'danger'}
                        onClick={() => apply({ status: 'overdue' })}
                    />
                    {/*
                     * Awaiting approval, not "in draft". The wording is the
                     * point: this money is owed but not yet in the books, and
                     * calling it a draft makes it sound optional.
                     */}
                    <Figure
                        label="Awaiting approval"
                        value={summary.draft}
                        currency={baseCurrency}
                        onClick={() => apply({ status: 'draft' })}
                    />
                </div>
            )}

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        label="Search"
                        name="search"
                        value={draft.search}
                        onChange={(e) => setDraft({ ...draft, search: e.target.value })}
                        onBlur={() => apply({})}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({});
                        }}
                        placeholder="Our number, their number, or vendor"
                    />

                    <Select
                        label="Status"
                        name="status"
                        value={draft.status}
                        onChange={(e) => apply({ status: e.target.value })}
                        options={[
                            { value: '', label: 'Any status' },
                            ...statuses,
                            ...(type.has_due_date ? [{ value: 'overdue', label: 'Overdue' }] : []),
                        ]}
                    />
                </div>
            </Card>

            <Card flush>
                {documents.total === 0 && !isFiltered ? (
                    <EmptyState
                        icon={FileText}
                        title={`No ${type.plural.toLowerCase()} yet`}
                        description={
                            type.posts
                                ? 'Enter what the vendor sent and it becomes a draft. Nothing reaches the ledger until somebody approves it.'
                                : `A ${type.label.toLowerCase()} records what you have committed to, without touching the books.`
                        }
                        action={
                            can.create ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => router.get(`/purchases/${type.segment}/new`)}
                                >
                                    New {type.label.toLowerCase()}
                                </Button>
                            ) : undefined
                        }
                    />
                ) : documents.data.length === 0 ? (
                    <NoResultsState
                        query={filters.search === '' ? undefined : filters.search}
                        onClear={clear}
                    />
                ) : (
                    <>
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">Number</th>
                                        <th className="px-4 py-2 text-left font-medium">Vendor</th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        {type.has_due_date && (
                                            <th className="px-4 py-2 text-left font-medium">Due</th>
                                        )}
                                        <th className="px-4 py-2 text-right font-medium">Total</th>
                                        {type.has_due_date && (
                                            <th className="px-4 py-2 text-right font-medium">
                                                Balance
                                            </th>
                                        )}
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {documents.data.map((document) => (
                                        <tr
                                            key={document.id}
                                            className={cn(
                                                'hover:bg-surface-hover',
                                                document.is_void && 'opacity-60',
                                            )}
                                        >
                                            <td className="px-4 py-2.5">
                                                <Link
                                                    href={`/purchases/${type.segment}/${document.number}`}
                                                    className="text-brand-text font-medium tabular-nums hover:underline"
                                                >
                                                    {document.number}
                                                </Link>
                                                {document.vendor_reference !== null && (
                                                    <span className="text-content-muted ml-2 text-xs">
                                                        their {document.vendor_reference}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5">
                                                {document.contact_id === '' ? (
                                                    (document.contact_name ?? '—')
                                                ) : (
                                                    <Link
                                                        href={`/sales/customers/${document.contact_id}`}
                                                        className="hover:underline"
                                                    >
                                                        {document.contact_name ?? '—'}
                                                    </Link>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                                {document.issue_date}
                                            </td>

                                            {type.has_due_date && (
                                                <td className="px-4 py-2.5 tabular-nums">
                                                    <span
                                                        className={
                                                            document.is_overdue
                                                                ? 'text-danger-600 dark:text-danger-400 font-medium'
                                                                : 'text-content-secondary'
                                                        }
                                                    >
                                                        {document.due_date ?? '—'}
                                                    </span>
                                                    {document.is_overdue && (
                                                        <span className="text-danger-600 dark:text-danger-400 ml-2 text-xs">
                                                            {document.days_overdue}d
                                                        </span>
                                                    )}
                                                </td>
                                            )}

                                            <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                                {formatMoney(document.total, {
                                                    currency: document.currency,
                                                    showCurrency: document.is_foreign_currency,
                                                })}
                                                {!isZero(document.tax_capitalised) && (
                                                    <span
                                                        className="text-content-muted block text-xs"
                                                        title="Input tax on this document that cannot be reclaimed, and is part of the cost"
                                                    >
                                                        incl.{' '}
                                                        {formatMoney(document.tax_capitalised, {
                                                            currency: document.currency,
                                                            showCurrency: false,
                                                        })}{' '}
                                                        unclaimable tax
                                                    </span>
                                                )}
                                            </td>

                                            {type.has_due_date && (
                                                <td
                                                    className={cn(
                                                        'px-4 py-2.5 text-right font-medium tabular-nums',
                                                        isZero(document.balance_due)
                                                            ? 'text-content-disabled'
                                                            : 'text-content',
                                                    )}
                                                >
                                                    {isZero(document.balance_due)
                                                        ? '—'
                                                        : formatMoney(document.balance_due, {
                                                              currency: document.currency,
                                                              showCurrency: false,
                                                          })}
                                                </td>
                                            )}

                                            <td className="px-4 py-2.5">
                                                <Badge tone={toneOf(document)}>
                                                    {document.is_overdue && !document.is_void
                                                        ? 'Overdue'
                                                        : document.status_label}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <footer className="border-line-subtle text-content-muted flex items-center justify-between border-t px-4 py-2.5 text-xs">
                            <span>
                                {documents.from}–{documents.to} of {documents.total}
                            </span>
                            <Pagination links={documents.links} />
                        </footer>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

/**
 * Overdue overrides the stored status in the badge, because that is the fact
 * the reader needs — an "open" bill three weeks late is not an open bill as
 * far as the vendor is concerned.
 */
function toneOf(document: PurchaseSummary): BadgeTone {
    if (document.is_overdue && !document.is_void) {
        return 'danger';
    }

    return (document.status_tone as BadgeTone) ?? 'neutral';
}

function Figure({
    label,
    value,
    currency,
    emphasis = false,
    tone = 'neutral',
    onClick,
}: {
    label: string;
    value: string;
    currency: string;
    emphasis?: boolean;
    tone?: 'neutral' | 'danger';
    onClick?: () => void;
}) {
    const body = (
        <>
            <p className="text-content-muted text-2xs flex items-center gap-1.5 uppercase">
                {tone === 'danger' && !isZero(value) && (
                    <AlertTriangle className="size-3" aria-hidden="true" />
                )}
                {label}
            </p>
            <p
                className={cn(
                    'mt-1 tabular-nums',
                    emphasis ? 'text-lg font-semibold' : 'text-md font-medium',
                    tone === 'danger' && !isZero(value)
                        ? 'text-danger-600 dark:text-danger-400'
                        : 'text-content',
                )}
            >
                {formatMoney(value, { currency, showCurrency: false })}
            </p>
        </>
    );

    if (onClick === undefined || isZero(value)) {
        return <Card className={emphasis ? 'border-line-brand' : undefined}>{body}</Card>;
    }

    return (
        <Card className={cn('p-0', tone === 'danger' ? 'border-line-danger' : undefined)}>
            <button
                type="button"
                onClick={onClick}
                className="hover:bg-surface-hover focus-visible:outline-focus w-full rounded-md p-4 text-left transition-colors focus-visible:outline-2"
            >
                {body}
            </button>
        </Card>
    );
}

function pruneEmpty(filters: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
}
