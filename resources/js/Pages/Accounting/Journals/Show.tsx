import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Undo2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { formatMoney, isZero } from '@/Utils/money';

interface EntryLine {
    id: string;
    line_no: number;
    account_code: string | null;
    account_name: string | null;
    debit: string;
    credit: string;
    debit_base: string;
    credit_base: string;
    memo: string | null;
}

interface JournalEntry {
    id: string;
    entry_no: string;
    entry_date: string;
    period: string | null;
    source_type: string;
    source_purpose: string;
    memo: string | null;
    currency: string;
    base_currency: string;
    exchange_rate: string;
    total: string;
    total_base: string;
    status: string;
    status_label: string;
    is_reversal: boolean;
    posted_at: string;
    lines: EntryLine[];
    reversed_by: { entry_no: string; entry_date: string } | null;
    reverses: { entry_no: string; entry_date: string } | null;
}

interface ShowProps {
    entry: JournalEntry;
    baseCurrency: string;
    can: { reverse: boolean };
}

/**
 * One journal entry, in full.
 *
 * There is no edit button, and there never will be. A posted entry is
 * corrected by reversing it, which leaves both the mistake and the correction
 * in the ledger — in the order they happened. That is precisely what an
 * auditor needs, and it is why the correction chain is shown at the top rather
 * than buried.
 *
 * @see ACCOUNTING_RULES.md I4, §4.15
 */
export default function ShowJournal({ entry, baseCurrency, can }: ShowProps) {
    const [reversing, setReversing] = useState(false);

    const isForeign = entry.currency !== entry.base_currency;

    return (
        <AppLayout
            title={entry.entry_no}
            description={entry.memo ?? `${humanise(entry.source_type)} entry`}
            breadcrumbs={[
                { label: 'Accounting' },
                { label: 'Manual Journals', href: '/accounting/journals' },
                { label: entry.entry_no },
            ]}
            actions={
                can.reverse && !reversing ? (
                    <Button
                        variant="danger"
                        size="md"
                        icon={<Undo2 aria-hidden="true" />}
                        onClick={() => setReversing(true)}
                    >
                        Reverse this entry
                    </Button>
                ) : undefined
            }
        >
            <Head title={entry.entry_no} />

            {(entry.reversed_by !== null || entry.reverses !== null) && (
                <Card className="border-line-brand mb-4">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        {entry.reverses !== null && (
                            <>
                                <Badge tone="warning">Reversal</Badge>
                                <span className="text-content-secondary">This entry reverses</span>
                                <Link
                                    href={`/accounting/journals/${entry.reverses.entry_no}`}
                                    className="text-brand-text font-medium hover:underline"
                                >
                                    {entry.reverses.entry_no}
                                </Link>
                                <span className="text-content-muted text-xs">
                                    dated {entry.reverses.entry_date}
                                </span>
                            </>
                        )}

                        {entry.reversed_by !== null && (
                            <>
                                <Badge tone="warning">Reversed</Badge>
                                <span className="text-content-secondary">Undone by</span>
                                <Link
                                    href={`/accounting/journals/${entry.reversed_by.entry_no}`}
                                    className="text-brand-text inline-flex items-center gap-1 font-medium hover:underline"
                                >
                                    {entry.reversed_by.entry_no}
                                    <ArrowRight className="size-3" aria-hidden="true" />
                                </Link>
                                <span className="text-content-muted text-xs">
                                    on {entry.reversed_by.entry_date}
                                </span>
                            </>
                        )}
                    </div>
                </Card>
            )}

            {reversing && can.reverse && (
                <ReverseForm entryNo={entry.entry_no} onClose={() => setReversing(false)} />
            )}

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Fact label="Date" value={entry.entry_date} />
                <Fact label="Period" value={entry.period ?? '—'} />
                <Fact label="Source" value={humanise(entry.source_type)} />
                <Fact
                    label="Status"
                    value={
                        <Badge tone={entry.status === 'reversed' ? 'warning' : 'success'}>
                            {entry.status_label}
                        </Badge>
                    }
                />
            </div>

            <Card flush>
                <header className="border-line-subtle flex items-baseline justify-between border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Lines</h2>
                    {isForeign && (
                        <p className="text-content-muted text-xs tabular-nums">
                            1 {entry.currency} = {entry.exchange_rate} {entry.base_currency}
                            <span className="ml-2">— the rate on the day, kept for ever</span>
                        </p>
                    )}
                </header>

                <div className="table-scroll">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                <th className="w-10 px-4 py-2 text-left font-medium">#</th>
                                <th className="px-4 py-2 text-left font-medium">Account</th>
                                <th className="px-4 py-2 text-left font-medium">Memo</th>
                                {isForeign && (
                                    <>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Debit ({entry.currency})
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Credit ({entry.currency})
                                        </th>
                                    </>
                                )}
                                <th className="px-4 py-2 text-right font-medium">
                                    Debit ({baseCurrency})
                                </th>
                                <th className="px-4 py-2 text-right font-medium">
                                    Credit ({baseCurrency})
                                </th>
                            </tr>
                        </thead>

                        <tbody className="divide-line-subtle divide-y">
                            {entry.lines.map((line) => (
                                <tr key={line.id} className="hover:bg-surface-hover">
                                    <td className="text-content-muted px-4 py-2.5 tabular-nums">
                                        {line.line_no}
                                    </td>

                                    <td className="px-4 py-2.5">
                                        <span className="text-content font-medium tabular-nums">
                                            {line.account_code}
                                        </span>
                                        <span className="text-content-secondary ml-2">
                                            {line.account_name}
                                        </span>
                                    </td>

                                    <td className="text-content-muted px-4 py-2.5">
                                        {line.memo ?? '—'}
                                    </td>

                                    {isForeign && (
                                        <>
                                            <Amount value={line.debit} currency={entry.currency} />
                                            <Amount value={line.credit} currency={entry.currency} />
                                        </>
                                    )}

                                    <Amount value={line.debit_base} currency={baseCurrency} />
                                    <Amount value={line.credit_base} currency={baseCurrency} />
                                </tr>
                            ))}
                        </tbody>

                        <tfoot>
                            <tr className="border-line-subtle bg-surface-sunken text-content border-t-2 font-medium">
                                <td className="px-4 py-2.5" colSpan={3}>
                                    Total
                                </td>

                                {isForeign && (
                                    <>
                                        <Amount value={entry.total} currency={entry.currency} />
                                        <Amount value={entry.total} currency={entry.currency} />
                                    </>
                                )}

                                <Amount value={entry.total_base} currency={baseCurrency} />
                                <Amount value={entry.total_base} currency={baseCurrency} />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <footer className="border-line-subtle text-content-muted border-t px-4 py-2.5 text-xs">
                    Posted {entry.posted_at}. A posted entry is never edited or deleted — only
                    reversed.
                </footer>
            </Card>
        </AppLayout>
    );
}

function ReverseForm({ entryNo, onClose }: { entryNo: string; onClose: () => void }) {
    const form = useForm({ date: '', reason: '' });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/accounting/journals/${entryNo}/reverse`);
    };

    return (
        <Card flush className="border-line-danger mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Reverse {entryNo}</h2>
                    <p className="text-content-muted text-xs">
                        A mirror-image entry is posted, netting every account this one touched back
                        to where it was. Both entries stay in the ledger.
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-[12rem_1fr]">
                    <Input
                        label="Reversal date"
                        type="date"
                        value={form.data.date}
                        onChange={(e) => form.setData('date', e.target.value)}
                        error={form.errors.date}
                        hint="Today, unless you say otherwise."
                        optional
                    />

                    <Input
                        label="Reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        error={form.errors.reason}
                        placeholder="Why this is being undone — the only explanation on the record."
                        optional
                    />
                </div>

                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="ghost" size="md" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="danger"
                        size="md"
                        loading={form.processing}
                        icon={<Undo2 aria-hidden="true" />}
                    >
                        Post the reversal
                    </Button>
                </footer>
            </form>
        </Card>
    );
}

function Amount({ value, currency }: { value: string; currency: string }) {
    // Every line has one side, so the other is always nil. A dash makes the
    // debit and credit columns readable at a glance; a column of 0.00 does not.
    const nil = isZero(value);

    return (
        <td
            className={
                nil
                    ? 'text-content-disabled px-4 py-2.5 text-right tabular-nums'
                    : 'text-content px-4 py-2.5 text-right tabular-nums'
            }
        >
            {nil ? '—' : formatMoney(value, { currency, showCurrency: false })}
        </td>
    );
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <Card>
            <p className="text-content-muted text-2xs uppercase">{label}</p>
            <p className="text-content mt-1 text-sm font-medium">{value}</p>
        </Card>
    );
}

function humanise(value: string): string {
    const spaced = value.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
