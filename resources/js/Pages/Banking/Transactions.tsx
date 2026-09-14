import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { CircleSlash, FileUp, Landmark, Link2, Unlink } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney, isNegative } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface MatchRow {
    id: string;
    amount: string;
    entry_no: string | null;
    entry_date: string | null;
    source_type: string | null;
    origin: string;
}

interface StatementLine {
    id: string;
    date: string;
    description: string;
    summary: string;
    reference: string | null;
    payee: string | null;
    amount: string;
    is_inflow: boolean;
    statement_balance: string | null;
    status: string;
    status_label: string;
    excluded_reason: string | null;
    is_locked: boolean;
    matches: MatchRow[];
}

interface Suggestion {
    journal_line_id: string;
    entry_no: string;
    entry_date: string;
    amount: string;
    memo: string | null;
    source_type: string;
    contact_name: string | null;
    confidence: number;
    reasons: string[];
    is_strong: boolean;
}

interface ImportRow {
    id: string;
    filename: string;
    format: string;
    imported: number;
    duplicate: number;
    rejected: number;
    period_start: string | null;
    period_end: string | null;
    at: string | null;
}

interface TransactionsProps {
    accounts: { value: string; label: string; currency: string; supports_statements: boolean }[];
    account: {
        id: string;
        name: string;
        label: string;
        currency: string;
        kind: string;
        supports_statements: boolean;
    } | null;
    lines: {
        data: StatementLine[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        from?: number | null;
        to?: number | null;
    };
    filters: { status: string; search: string };
    selectedLine: StatementLine | null;
    suggestions: Suggestion[];
    imports: ImportRow[];
    summary: { unmatched: number; matched: number; excluded: number };
    formats: { value: string; label: string }[];
    baseCurrency: string;
    can: { import: boolean; reconcile: boolean; manage: boolean };
}

const STATUS_TONE: Record<string, BadgeTone> = {
    unmatched: 'warning',
    matched: 'success',
    excluded: 'neutral',
};

/**
 * The bank's version of events, and what somebody decided each line means.
 *
 * The screen states the thing people most often assume otherwise: importing
 * posts nothing. A line sits here as unexplained evidence until a person with
 * the permission to reconcile says which of our entries it is — and even then
 * nothing posts, because both sides already existed.
 */
export default function Transactions({
    accounts,
    account,
    lines,
    filters,
    selectedLine,
    suggestions,
    imports,
    summary,
    formats,
    baseCurrency,
    can,
}: TransactionsProps) {
    const [search, setSearch] = useState(filters.search);
    const [importing, setImporting] = useState(false);

    const reload = (params: Record<string, string>) => {
        router.get(
            '/banking/transactions',
            {
                account: account?.id ?? '',
                status: filters.status,
                search,
                ...params,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    if (account === null) {
        return (
            <AppLayout
                title="Bank transactions"
                breadcrumbs={[{ label: 'Banking' }, { label: 'Transactions' }]}
            >
                <Head title="Bank transactions" />
                <EmptyState
                    icon={Landmark}
                    title="No bank accounts yet"
                    description="Add one first — a statement has to be imported against an account."
                    action={
                        can.manage ? (
                            <Button
                                variant="primary"
                                onClick={() => router.get('/banking/accounts')}
                            >
                                Add an account
                            </Button>
                        ) : undefined
                    }
                />
            </AppLayout>
        );
    }

    return (
        <AppLayout
            title="Bank transactions"
            description="Imported statement lines. Nothing here has posted."
            breadcrumbs={[{ label: 'Banking' }, { label: 'Transactions' }]}
            actions={
                can.import && account.supports_statements ? (
                    <Button
                        variant="primary"
                        icon={<FileUp aria-hidden="true" />}
                        onClick={() => setImporting((was) => !was)}
                    >
                        Import a statement
                    </Button>
                ) : undefined
            }
        >
            <Head title="Bank transactions" />

            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Select
                        label="Account"
                        containerClassName="w-full sm:w-72"
                        options={accounts.map((option) => ({
                            value: option.value,
                            label: option.label,
                        }))}
                        value={account.id}
                        onChange={(event) =>
                            router.get('/banking/transactions', { account: event.target.value })
                        }
                    />

                    <Select
                        label="Status"
                        containerClassName="w-full sm:w-48"
                        options={[
                            { value: '', label: `All (${lines.total})` },
                            { value: 'unmatched', label: `Unmatched (${summary.unmatched})` },
                            { value: 'matched', label: `Matched (${summary.matched})` },
                            { value: 'excluded', label: `Excluded (${summary.excluded})` },
                        ]}
                        value={filters.status}
                        onChange={(event) => reload({ status: event.target.value })}
                    />

                    <form
                        className="flex-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            reload({});
                        }}
                    >
                        <Input
                            label="Search"
                            placeholder="Description, reference or payee"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </form>
                </div>

                {importing && can.import && (
                    <ImportPanel
                        accountId={account.id}
                        formats={formats}
                        imports={imports}
                        onDone={() => setImporting(false)}
                    />
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <Card flush className="overflow-hidden">
                        {lines.data.length === 0 ? (
                            filters.search !== '' || filters.status !== '' ? (
                                <div className="p-6">
                                    <NoResultsState
                                        query={filters.search}
                                        onClear={() => {
                                            setSearch('');
                                            router.get('/banking/transactions', {
                                                account: account.id,
                                            });
                                        }}
                                    />
                                </div>
                            ) : (
                                <div className="p-6">
                                    <EmptyState
                                        icon={FileUp}
                                        title="Nothing imported yet"
                                        description="Import a CSV, OFX or QIF statement and every line will appear here, unmatched, until somebody says what it is."
                                        action={
                                            can.import ? (
                                                <Button
                                                    variant="primary"
                                                    onClick={() => setImporting(true)}
                                                >
                                                    Import a statement
                                                </Button>
                                            ) : undefined
                                        }
                                    />
                                </div>
                            )
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[42rem] text-sm">
                                    <thead className="border-line-subtle text-content-muted border-b text-xs">
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Date
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Description
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Amount
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.data.map((line) => (
                                            <LineRow
                                                key={line.id}
                                                line={line}
                                                currency={account.currency}
                                                accountId={account.id}
                                                isSelected={selectedLine?.id === line.id}
                                                can={can}
                                            />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {lines.links.length > 3 && (
                            <div className="border-line-subtle border-t px-4 py-3">
                                <Pagination links={lines.links} />
                            </div>
                        )}
                    </Card>

                    <SuggestionPanel
                        line={selectedLine}
                        suggestions={suggestions}
                        currency={account.currency}
                        baseCurrency={baseCurrency}
                        can={can}
                    />
                </div>
            </div>
        </AppLayout>
    );
}

function LineRow({
    line,
    currency,
    accountId,
    isSelected,
    can,
}: {
    line: StatementLine;
    currency: string;
    accountId: string;
    isSelected: boolean;
    can: TransactionsProps['can'];
}) {
    const unmatch = useForm({});

    return (
        <tr
            className={cn(
                'border-line-subtle border-b last:border-0',
                isSelected && 'bg-surface-sunken',
            )}
        >
            <td className="text-content-secondary px-4 py-2 whitespace-nowrap tabular-nums">
                {line.date}
            </td>
            <td className="px-4 py-2">
                <p className="text-content truncate">{line.summary}</p>
                {line.reference !== null && (
                    <p className="text-content-muted text-xs">Ref {line.reference}</p>
                )}
                {line.matches.length > 0 && (
                    <p className="text-content-muted text-xs">
                        {line.matches.map((match) => match.entry_no ?? 'an entry').join(', ')}
                    </p>
                )}
                {line.excluded_reason !== null && (
                    <p className="text-content-muted text-xs">{line.excluded_reason}</p>
                )}
            </td>
            <td
                className={cn(
                    'px-4 py-2 text-right whitespace-nowrap tabular-nums',
                    isNegative(line.amount) ? 'text-danger' : 'text-content',
                )}
            >
                {formatMoney(line.amount, { currency, signDisplay: 'auto' })}
            </td>
            <td className="px-4 py-2">
                <Badge tone={STATUS_TONE[line.status] ?? 'neutral'} dot>
                    {line.status_label}
                </Badge>
                {line.is_locked && (
                    <span className="text-content-muted ml-1 text-xs">· reconciled</span>
                )}
            </td>
            <td className="px-4 py-2 text-right whitespace-nowrap">
                {can.reconcile && !line.is_locked && line.status === 'unmatched' && (
                    <Button
                        size="sm"
                        variant="secondary"
                        icon={<Link2 aria-hidden="true" />}
                        onClick={() =>
                            router.get(
                                '/banking/transactions',
                                { account: accountId, line: line.id },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Find a match
                    </Button>
                )}
                {can.reconcile && !line.is_locked && line.status !== 'unmatched' && (
                    <Button
                        size="sm"
                        variant="ghost"
                        icon={<Unlink aria-hidden="true" />}
                        onClick={() =>
                            unmatch.post(`/banking/transactions/${line.id}/unmatch`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Undo
                    </Button>
                )}
            </td>
        </tr>
    );
}

function SuggestionPanel({
    line,
    suggestions,
    currency,
    baseCurrency,
    can,
}: {
    line: StatementLine | null;
    suggestions: Suggestion[];
    currency: string;
    baseCurrency: string;
    can: TransactionsProps['can'];
}) {
    const match = useForm({
        journal_line_id: '',
        origin: 'suggested',
        confidence: 0,
        note: '',
    });

    const exclude = useForm({ reason: '' });

    if (line === null) {
        return (
            <Card>
                <h2 className="text-content text-sm font-semibold">Matching</h2>
                <p className="text-content-muted mt-2 text-xs">
                    Choose a line to see the entries it could refer to. Only entries for exactly the
                    same amount are offered: a near-enough match is how a reconciliation reaches
                    zero while being wrong.
                </p>
            </Card>
        );
    }

    return (
        <Card className="flex flex-col gap-3">
            <div>
                <h2 className="text-content text-sm font-semibold">{line.summary}</h2>
                <p className="text-content-muted text-xs">
                    {line.date} ·{' '}
                    <span className="tabular-nums">{formatMoney(line.amount, { currency })}</span>
                    {currency !== baseCurrency && ` (${currency})`}
                </p>
            </div>

            {suggestions.length === 0 ? (
                <div className="border-line-subtle rounded-md border border-dashed p-3">
                    <p className="text-content text-xs font-medium">Nothing matches this exactly</p>
                    <p className="text-content-muted mt-1 text-xs">
                        That is information, not a dead end: either the transaction has not been
                        recorded yet, or it was recorded for a different amount. Record it, then
                        come back — or exclude it if it is not ours.
                    </p>
                </div>
            ) : (
                <ul className="flex flex-col gap-2">
                    {suggestions.map((suggestion) => (
                        <li
                            key={suggestion.journal_line_id}
                            className="border-line-subtle rounded-md border p-3"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-content truncate text-xs font-medium">
                                        {suggestion.entry_no} · {suggestion.entry_date}
                                    </p>
                                    <p className="text-content-muted truncate text-xs">
                                        {suggestion.contact_name ?? suggestion.memo ?? '—'}
                                    </p>
                                </div>
                                <Badge tone={suggestion.is_strong ? 'success' : 'neutral'}>
                                    {suggestion.confidence}%
                                </Badge>
                            </div>

                            <ul className="text-content-muted mt-2 list-inside list-disc text-xs">
                                {suggestion.reasons.map((reason) => (
                                    <li key={reason}>{reason}</li>
                                ))}
                            </ul>

                            {can.reconcile && (
                                <Button
                                    className="mt-2"
                                    size="sm"
                                    variant="primary"
                                    disabled={match.processing}
                                    onClick={() => {
                                        match.transform(() => ({
                                            journal_line_id: suggestion.journal_line_id,
                                            origin: 'suggested',
                                            confidence: suggestion.confidence,
                                            note: '',
                                        }));

                                        match.post(`/banking/transactions/${line.id}/match`, {
                                            preserveScroll: true,
                                        });
                                    }}
                                >
                                    This is it
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {can.reconcile && line.status === 'unmatched' && (
                <form
                    className="border-line-subtle flex flex-col gap-2 border-t pt-3"
                    onSubmit={(event: SyntheticEvent) => {
                        event.preventDefault();
                        exclude.post(`/banking/transactions/${line.id}/exclude`, {
                            preserveScroll: true,
                            onSuccess: () => exclude.reset(),
                        });
                    }}
                >
                    <Input
                        label="Not ours?"
                        placeholder="Why this line should be ignored"
                        value={exclude.data.reason}
                        onChange={(event) => exclude.setData('reason', event.target.value)}
                        error={exclude.errors.reason}
                        hint="Recorded with the line, so a year later somebody can see why it was left out."
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="ghost"
                        icon={<CircleSlash aria-hidden="true" />}
                        disabled={exclude.processing || exclude.data.reason.trim().length < 3}
                    >
                        Exclude this line
                    </Button>
                </form>
            )}
        </Card>
    );
}

function ImportPanel({
    accountId,
    formats,
    imports,
    onDone,
}: {
    accountId: string;
    formats: { value: string; label: string }[];
    imports: ImportRow[];
    onDone: () => void;
}) {
    const form = useForm<{ statement: File | null; format: string }>({
        statement: null,
        format: '',
    });

    return (
        <Card className="flex flex-col gap-4">
            <form
                className="flex flex-col gap-3"
                onSubmit={(event: SyntheticEvent) => {
                    event.preventDefault();
                    form.post(`/banking/accounts/${accountId}/import`, {
                        preserveScroll: true,
                        forceFormData: true,
                        onSuccess: () => {
                            form.reset();
                            onDone();
                        },
                    });
                }}
            >
                <div>
                    <h2 className="text-content text-sm font-semibold">Import a statement</h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        CSV, OFX/QFX or QIF. Importing records what the bank says and posts nothing
                        — every line arrives unmatched. The same file imported twice adds nothing
                        the second time.
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]">
                    <div className="flex flex-col gap-1">
                        <label
                            htmlFor="statement-file"
                            className="text-content-secondary text-xs font-medium"
                        >
                            Statement file
                        </label>
                        <input
                            id="statement-file"
                            type="file"
                            accept=".csv,.txt,.ofx,.qfx,.qif"
                            className={cn(
                                'border-line text-content rounded-md border px-3 py-1.5 text-sm',
                                'file:text-content-secondary file:mr-3 file:border-0 file:bg-transparent file:text-sm',
                                'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-2',
                            )}
                            onChange={(event) =>
                                form.setData('statement', event.target.files?.[0] ?? null)
                            }
                        />
                        {form.errors.statement !== undefined && (
                            <p className="text-danger text-xs">{form.errors.statement}</p>
                        )}
                    </div>

                    <Select
                        label="Format"
                        options={[{ value: '', label: 'Detect from the file' }, ...formats]}
                        value={form.data.format}
                        onChange={(event) => form.setData('format', event.target.value)}
                        error={form.errors.format}
                    />
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        type="submit"
                        variant="primary"
                        loading={form.processing}
                        disabled={form.data.statement === null}
                    >
                        Read the file
                    </Button>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                </div>
            </form>

            {imports.length > 0 && (
                <div className="border-line-subtle border-t pt-3">
                    <h3 className="text-content-secondary text-xs font-medium">Recent imports</h3>
                    <ul className="mt-2 flex flex-col gap-1">
                        {imports.map((row) => (
                            <li
                                key={row.id}
                                className="text-content-muted flex flex-wrap justify-between gap-2 text-xs"
                            >
                                <span className="truncate">
                                    {row.filename} · {row.format}
                                </span>
                                <span className="tabular-nums">
                                    {row.imported} new, {row.duplicate} already present
                                    {row.rejected > 0 && `, ${row.rejected} unreadable`}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
}
