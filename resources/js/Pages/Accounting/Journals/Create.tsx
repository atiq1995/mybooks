import { useMemo, useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Check, Plus, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import {
    absForDisplay,
    formatMoney,
    isPositiveAmount,
    moneySign,
    subtractForDisplay,
    sumForDisplay,
} from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface PostableAccount {
    id: string;
    code: string;
    name: string;
    type_label: string;
    normal_balance: string;
}

interface CreateJournalProps {
    accounts: PostableAccount[];
    baseCurrency: string;
    today: string;
    canPostToClosedPeriod: boolean;
}

interface LineDraft {
    account_id: string;
    side: 'debit' | 'credit';
    amount: string;
    memo: string;
}

const EMPTY_LINE: LineDraft = { account_id: '', side: 'debit', amount: '', memo: '' };

/**
 * Write a journal entry by hand.
 *
 * The running totals are the whole interface. An accountant knows exactly
 * what they intend to post; what they need from the screen is immediate
 * confirmation that the two sides agree, and the difference when they do not
 * — before submitting, not after.
 *
 * Arithmetic here is display only. The server recomputes every figure at full
 * decimal precision and refuses anything that does not balance, so a browser
 * that disagreed would be overruled rather than obeyed.
 *
 * @see ACCOUNTING_RULES.md I1
 */
export default function CreateJournal({
    accounts,
    baseCurrency,
    today,
    canPostToClosedPeriod,
}: CreateJournalProps) {
    const form = useForm<{
        date: string;
        memo: string;
        post_to_closed_period: boolean;
        lines: LineDraft[];
    }>({
        date: today,
        memo: '',
        post_to_closed_period: false,
        // Two lines to begin with: one cannot balance, and an empty table
        // hides the shape of what is being asked for.
        lines: [{ ...EMPTY_LINE }, { ...EMPTY_LINE, side: 'credit' }],
    });

    const [confirming, setConfirming] = useState(false);

    const totals = useMemo(() => summarise(form.data.lines), [form.data.lines]);

    const setLine = (index: number, patch: Partial<LineDraft>) => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    };

    const addLine = () => {
        // The new line takes the side that needs filling, which is right
        // almost every time and saves a click on every row.
        const side: LineDraft['side'] = moneySign(totals.difference) > 0 ? 'credit' : 'debit';

        form.setData('lines', [...form.data.lines, { ...EMPTY_LINE, side }]);
    };

    const removeLine = (index: number) => {
        if (form.data.lines.length <= 2) {
            return;
        }

        form.setData(
            'lines',
            form.data.lines.filter((_, i) => i !== index),
        );
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!confirming) {
            setConfirming(true);

            return;
        }

        form.post('/accounting/journals', {
            onError: () => setConfirming(false),
        });
    };

    const accountOptions = [
        { value: '', label: 'Choose an account' },
        ...accounts.map((account) => ({
            value: account.id,
            label: `${account.code} — ${account.name}`,
        })),
    ];

    const complete = form.data.lines.every(
        (line) => line.account_id !== '' && line.amount.trim() !== '',
    );

    const canSubmit = complete && totals.balanced && !totals.empty;

    return (
        <AppLayout
            title="New journal entry"
            description="For adjustments that no document produces — accruals, depreciation, corrections."
            breadcrumbs={[
                { label: 'Accounting' },
                { label: 'Manual Journals', href: '/accounting/journals' },
                { label: 'New' },
            ]}
        >
            <Head title="New journal entry" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Card>
                    <div className="grid gap-4 sm:grid-cols-[12rem_1fr]">
                        <Input
                            label="Date"
                            name="date"
                            type="date"
                            value={form.data.date}
                            onChange={(e) => form.setData('date', e.target.value)}
                            error={form.errors.date}
                            hint="Decides which period this lands in."
                            required
                        />

                        <Input
                            label="Memo"
                            name="memo"
                            value={form.data.memo}
                            onChange={(e) => form.setData('memo', e.target.value)}
                            error={form.errors.memo}
                            placeholder="Why this entry exists — the only explanation anybody will have in six months."
                            optional
                        />
                    </div>

                    {canPostToClosedPeriod && (
                        <label className="text-content-secondary mt-4 flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="post_to_closed_period"
                                checked={form.data.post_to_closed_period}
                                onChange={(e) =>
                                    form.setData('post_to_closed_period', e.target.checked)
                                }
                                className="border-line accent-brand mt-0.5 size-4 rounded"
                            />
                            <span>
                                Post even if the period is closed
                                <span className="text-content-muted block text-xs">
                                    Recorded against your name in the audit trail. A locked period
                                    still refuses it.
                                </span>
                            </span>
                        </label>
                    )}
                </Card>

                <Card flush>
                    <header className="border-line-subtle flex items-center justify-between border-b px-4 py-3">
                        <div>
                            <h2 className="text-content text-md font-semibold">Lines</h2>
                            <p className="text-content-muted text-xs">
                                Every line is a debit or a credit. Never both.
                            </p>
                        </div>

                        <Button
                            variant="secondary"
                            size="sm"
                            icon={<Plus aria-hidden="true" />}
                            onClick={addLine}
                        >
                            Add line
                        </Button>
                    </header>

                    {typeof form.errors.lines === 'string' && (
                        <p
                            role="alert"
                            className="border-line-danger bg-status-danger text-status-danger-fg flex items-start gap-2 border-b px-4 py-2.5 text-sm"
                        >
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            {form.errors.lines}
                        </p>
                    )}

                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-3 py-2 text-left font-medium">Account</th>
                                    <th className="w-28 px-3 py-2 text-left font-medium">Side</th>
                                    <th className="w-40 px-3 py-2 text-right font-medium">
                                        Amount
                                    </th>
                                    <th className="px-3 py-2 text-left font-medium">Memo</th>
                                    <th className="w-10 px-3 py-2" />
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {form.data.lines.map((line, index) => (
                                    <tr key={index} className="align-top">
                                        <td className="px-3 py-2">
                                            <Select
                                                aria-label={`Account for line ${index + 1}`}
                                                name={`lines.${index}.account_id`}
                                                value={line.account_id}
                                                onChange={(e) =>
                                                    setLine(index, { account_id: e.target.value })
                                                }
                                                error={form.errors[`lines.${index}.account_id`]}
                                                options={accountOptions}
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Select
                                                aria-label={`Side for line ${index + 1}`}
                                                name={`lines.${index}.side`}
                                                value={line.side}
                                                onChange={(e) =>
                                                    setLine(index, {
                                                        side: e.target.value as LineDraft['side'],
                                                    })
                                                }
                                                options={[
                                                    { value: 'debit', label: 'Debit' },
                                                    { value: 'credit', label: 'Credit' },
                                                ]}
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Input
                                                aria-label={`Amount for line ${index + 1}`}
                                                name={`lines.${index}.amount`}
                                                value={line.amount}
                                                onChange={(e) =>
                                                    setLine(index, { amount: e.target.value })
                                                }
                                                error={form.errors[`lines.${index}.amount`]}
                                                // A text field, not a number field: a number
                                                // input hands back a float, and money is never
                                                // a float.
                                                inputMode="decimal"
                                                numeric
                                                placeholder="0.00"
                                                prefix={
                                                    <span className="text-content-muted text-2xs">
                                                        {baseCurrency}
                                                    </span>
                                                }
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Input
                                                aria-label={`Memo for line ${index + 1}`}
                                                name={`lines.${index}.memo`}
                                                value={line.memo}
                                                onChange={(e) =>
                                                    setLine(index, { memo: e.target.value })
                                                }
                                                placeholder="Optional"
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <button
                                                type="button"
                                                onClick={() => removeLine(index)}
                                                disabled={form.data.lines.length <= 2}
                                                aria-label={`Remove line ${index + 1}`}
                                                className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 mt-1 rounded p-1.5 transition-colors disabled:opacity-40 disabled:hover:bg-transparent"
                                            >
                                                <Trash2 className="size-3.5" aria-hidden="true" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>

                            <tfoot>
                                <tr
                                    className={cn(
                                        'border-line-subtle border-t-2 font-medium',
                                        totals.balanced && !totals.empty
                                            ? 'bg-status-success'
                                            : 'bg-surface-sunken',
                                    )}
                                >
                                    <td className="px-3 py-2.5" colSpan={2}>
                                        <span
                                            className={
                                                totals.balanced && !totals.empty
                                                    ? 'text-status-success-fg'
                                                    : 'text-content'
                                            }
                                        >
                                            {totals.empty
                                                ? 'Nothing entered yet'
                                                : totals.balanced
                                                  ? 'Balanced'
                                                  : `Out by ${formatMoney(
                                                        absForDisplay(totals.difference),
                                                        { currency: baseCurrency },
                                                    )}`}
                                        </span>
                                    </td>

                                    <td className="px-3 py-2.5 text-right tabular-nums" colSpan={3}>
                                        <span className="text-content-muted mr-3 text-xs">
                                            Debits
                                        </span>
                                        {formatMoney(totals.debit, {
                                            currency: baseCurrency,
                                            showCurrency: false,
                                        })}
                                        <span className="text-content-muted mr-3 ml-6 text-xs">
                                            Credits
                                        </span>
                                        {formatMoney(totals.credit, {
                                            currency: baseCurrency,
                                            showCurrency: false,
                                        })}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </Card>

                <div className="flex items-center justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="md"
                        onClick={() => router.get('/accounting/journals')}
                    >
                        Cancel
                    </Button>

                    {confirming ? (
                        <>
                            <Button
                                variant="secondary"
                                size="md"
                                onClick={() => setConfirming(false)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                type="submit"
                                variant="primary"
                                size="md"
                                loading={form.processing}
                                icon={<Check aria-hidden="true" />}
                            >
                                Yes, post it
                            </Button>
                        </>
                    ) : (
                        <Button type="submit" variant="primary" size="md" disabled={!canSubmit}>
                            Post journal
                        </Button>
                    )}
                </div>

                {confirming && (
                    <p
                        role="status"
                        className="text-content-secondary bg-status-warning border-line-subtle rounded-md border px-4 py-3 text-sm"
                    >
                        A posted entry cannot be edited or deleted — only reversed, which leaves
                        both entries visible for ever. That is what keeps the ledger auditable.
                    </p>
                )}
            </form>
        </AppLayout>
    );
}

/**
 * Running totals, for the screen.
 *
 * Decimal strings throughout, summed by the money module's display helpers.
 * The server recomputes all of this in PHP at full precision and refuses
 * anything that does not balance, so these figures inform the user — they
 * never decide anything.
 *
 * Lines that are blank or not yet a positive amount are skipped rather than
 * treated as zero, so a half-typed row does not flash an imbalance the user
 * has not made yet.
 */
function summarise(lines: LineDraft[]): {
    debit: string;
    credit: string;
    difference: string;
    balanced: boolean;
    empty: boolean;
} {
    const usable = lines.filter((line) => isPositiveAmount(line.amount));

    const debit = sumForDisplay(
        usable.filter((line) => line.side === 'debit').map((line) => line.amount),
    );

    const credit = sumForDisplay(
        usable.filter((line) => line.side === 'credit').map((line) => line.amount),
    );

    const difference = subtractForDisplay(debit, credit);

    return {
        debit,
        credit,
        difference,
        // Compared as a decimal string at the ledger's own scale, so the
        // verdict here matches the one the server will reach.
        balanced: moneySign(difference) === 0,
        empty: usable.length === 0,
    };
}
