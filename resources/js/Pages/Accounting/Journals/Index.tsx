import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { BookOpen, Plus, Undo2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { formatMoney } from '@/Utils/money';

export interface JournalSummary {
    id: string;
    entry_no: string;
    entry_date: string;
    period: string | null;
    source_type: string;
    source_purpose: string;
    memo: string | null;
    currency: string;
    base_currency: string;
    total: string;
    total_base: string;
    status: string;
    status_label: string;
    is_reversal: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface JournalsProps {
    entries: {
        data: JournalSummary[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string; source: string; from: string; to: string };
    sources: string[];
    baseCurrency: string;
    can: { post: boolean; reverse: boolean };
}

/**
 * Every entry in the ledger, newest first.
 *
 * Not only manual journals: an invoice, a bill and a payment each produce an
 * entry, and this is where all of them are visible. That is the point — the
 * ledger is one book, and a screen that showed only hand-written entries
 * would hide the 95% of it the application wrote.
 */
export default function Journals({ entries, filters, sources, baseCurrency, can }: JournalsProps) {
    const [draft, setDraft] = useState(filters);

    const applyFilters = (next: Partial<typeof filters>) => {
        const merged = { ...draft, ...next };
        setDraft(merged);

        router.get('/accounting/journals', pruneEmpty(merged), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        const empty = { search: '', source: '', from: '', to: '' };
        setDraft(empty);
        router.get('/accounting/journals', {}, { preserveState: true, replace: true });
    };

    const isFiltered = Object.values(filters).some((value) => value !== '');

    return (
        <AppLayout
            title="Manual Journals"
            description={`Everything posted to the ledger, whatever produced it. Totals in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Accounting' }, { label: 'Manual Journals' }]}
            actions={
                can.post ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => router.get('/accounting/journals/new')}
                    >
                        New journal
                    </Button>
                ) : undefined
            }
        >
            <Head title="Manual Journals" />

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        label="Search"
                        value={draft.search}
                        onChange={(e) => setDraft({ ...draft, search: e.target.value })}
                        onBlur={() => applyFilters({})}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') applyFilters({});
                        }}
                        placeholder="Entry number or memo"
                    />

                    <Select
                        label="Source"
                        value={draft.source}
                        onChange={(e) => applyFilters({ source: e.target.value })}
                        options={[
                            { value: '', label: 'Everything' },
                            ...sources.map((source) => ({
                                value: source,
                                label: humanise(source),
                            })),
                        ]}
                    />

                    <Input
                        label="From"
                        type="date"
                        value={draft.from}
                        onChange={(e) => applyFilters({ from: e.target.value })}
                    />

                    <Input
                        label="To"
                        type="date"
                        value={draft.to}
                        onChange={(e) => applyFilters({ to: e.target.value })}
                    />
                </div>
            </Card>

            <Card flush>
                {entries.total === 0 && !isFiltered ? (
                    <EmptyState
                        icon={BookOpen}
                        title="Nothing posted yet"
                        description="The ledger fills itself as you invoice, pay bills and record expenses. You can also write an entry by hand."
                        action={
                            can.post ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => router.get('/accounting/journals/new')}
                                >
                                    Write a journal
                                </Button>
                            ) : undefined
                        }
                    />
                ) : entries.data.length === 0 ? (
                    <NoResultsState
                        query={filters.search === '' ? undefined : filters.search}
                        onClear={clearFilters}
                    />
                ) : (
                    <>
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">Entry</th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">Source</th>
                                        <th className="px-4 py-2 text-left font-medium">Memo</th>
                                        <th className="px-4 py-2 text-right font-medium">Amount</th>
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {entries.data.map((entry) => (
                                        <tr key={entry.id} className="hover:bg-surface-hover">
                                            <td className="px-4 py-2.5">
                                                <Link
                                                    href={`/accounting/journals/${entry.entry_no}`}
                                                    className="text-brand-text font-medium tabular-nums hover:underline"
                                                >
                                                    {entry.entry_no}
                                                </Link>
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                                {entry.entry_date}
                                                {entry.period !== null && (
                                                    <span className="text-content-muted ml-2 text-xs">
                                                        {entry.period}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-4 py-2.5">
                                                <span className="text-content-secondary">
                                                    {humanise(entry.source_type)}
                                                </span>
                                                {entry.source_purpose !== 'issue' && (
                                                    <span className="text-content-muted ml-1 text-xs">
                                                        {entry.source_purpose}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content-muted max-w-xs truncate px-4 py-2.5">
                                                {entry.memo ?? '—'}
                                            </td>

                                            <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                                {formatMoney(entry.total_base, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                                {entry.currency !== entry.base_currency && (
                                                    <span className="text-content-muted ml-1.5 text-xs">
                                                        {formatMoney(entry.total, {
                                                            currency: entry.currency,
                                                        })}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center gap-1.5">
                                                    <Badge
                                                        tone={
                                                            entry.status === 'reversed'
                                                                ? 'warning'
                                                                : 'success'
                                                        }
                                                    >
                                                        {entry.status_label}
                                                    </Badge>
                                                    {entry.is_reversal && (
                                                        <span
                                                            title="This entry reverses another"
                                                            className="text-content-muted"
                                                        >
                                                            <Undo2
                                                                className="size-3"
                                                                aria-hidden="true"
                                                            />
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <footer className="border-line-subtle text-content-muted flex items-center justify-between border-t px-4 py-2.5 text-xs">
                            <span>
                                {entries.from}–{entries.to} of {entries.total}
                            </span>

                            <Pagination links={entries.links} />
                        </footer>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

function Pagination({ links }: { links: PaginationLink[] }) {
    // The first and last entries are "Previous"/"Next" labels; the rest are
    // page numbers, and Laravel includes "..." separators as unlinked labels.
    return (
        <nav aria-label="Pages" className="flex items-center gap-1">
            {links.map((link, index) =>
                link.url === null ? (
                    <span
                        key={index}
                        className="text-content-disabled px-2 py-1"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={
                            link.active
                                ? 'bg-brand text-content-on-brand rounded px-2 py-1'
                                : 'hover:bg-surface-hover text-content-secondary rounded px-2 py-1'
                        }
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}

/** 'payment_received' → 'Payment received'. */
function humanise(value: string): string {
    const spaced = value.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

function pruneEmpty(filters: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
}
