import { useRef, useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Ban, Camera, Check, Paperclip, Pencil, Send, ShieldCheck, Trash2, X } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import type { ExpenseSummary } from './Index';
import { formatMoney, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface LineTax {
    name: string;
    rate: string;
    amount: string;
    is_claimable: boolean;
}

interface ExpenseLine {
    id: string;
    line_no: number;
    kind: string;
    description: string;
    quantity: string;
    unit_price: string;
    unit: string | null;
    account: string | null;
    taxable: string;
    tax_total: string;
    tax_is_claimable: boolean;
    capitalised_cost: string;
    total: string;
    taxes: LineTax[];
}

interface Receipt {
    id: string;
    name: string;
    size: string;
    is_image: boolean;
    is_pdf: boolean;
    uploaded_at: string;
}

interface ShowProps {
    expense: ExpenseSummary & {
        notes: string | null;
        approved_at: string | null;
        submitted_at: string | null;
        rejected_at: string | null;
        rejection_reason: string | null;
        voided_at: string | null;
        submitted_by_name: string | null;
        approved_by_name: string | null;
        reimburse_user_name: string | null;
        paid_through: string | null;
        journal_entry_no: string | null;
        void_journal_entry_no: string | null;
        billed_invoice: { number: string; url: string } | null;
        lines: ExpenseLine[];
        tax_summary: { name: string; rate: string; amount: string; claimable: string }[];
        receipts: Receipt[];
    };
    baseCurrency: string;
    can: Record<string, boolean>;
}

/**
 * One expense, in full.
 *
 * The screen is built around the workflow, because that is what an expense
 * has and an invoice does not. The banner at the top says which of four
 * states this is in and what happens next — waiting to be submitted, waiting
 * for somebody else, sent back with a reason, or posted.
 *
 * Whoever submitted it does not see an approve button. A control that appears
 * and then always fails teaches people the software is broken rather than
 * that the rule exists.
 *
 * @see ACCOUNTING_RULES.md §4.8, §6
 */
export default function ExpenseShow({ expense, can }: ShowProps) {
    const [voiding, setVoiding] = useState(false);
    const [rejecting, setRejecting] = useState(false);

    const submit = useForm({});
    const approve = useForm({ post_to_closed_period: false });

    const hasBlockedTax = !isZero(expense.tax_capitalised);

    return (
        <AppLayout
            title={expense.number}
            description={expense.merchant === null ? 'Expense' : `Expense at ${expense.merchant}`}
            breadcrumbs={[{ label: 'Expenses', href: '/expenses' }, { label: expense.number }]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {can.update && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<Pencil aria-hidden="true" />}
                            onClick={() => router.get(`/expenses/${expense.number}/edit`)}
                        >
                            Edit
                        </Button>
                    )}

                    {can.submit && (
                        <Button
                            variant="primary"
                            size="md"
                            loading={submit.processing}
                            icon={<Send aria-hidden="true" />}
                            onClick={() => submit.post(`/expenses/${expense.number}/submit`)}
                        >
                            Submit for approval
                        </Button>
                    )}

                    {can.approve && (
                        <Button
                            variant="primary"
                            size="md"
                            loading={approve.processing}
                            icon={<ShieldCheck aria-hidden="true" />}
                            onClick={() => {
                                if (
                                    !window.confirm(
                                        `Approve ${expense.number}?\n\n` +
                                            'This posts to the ledger. Afterwards the expense ' +
                                            'cannot be edited — only voided, which leaves both ' +
                                            'entries on the record.',
                                    )
                                ) {
                                    return;
                                }

                                approve.post(`/expenses/${expense.number}/approve`);
                            }}
                        >
                            Approve
                        </Button>
                    )}

                    {can.reject && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<X aria-hidden="true" />}
                            onClick={() => setRejecting(true)}
                        >
                            Send back
                        </Button>
                    )}

                    {can.void && (
                        <Button
                            variant="danger"
                            size="md"
                            icon={<Ban aria-hidden="true" />}
                            onClick={() => setVoiding(true)}
                        >
                            Void
                        </Button>
                    )}

                    {can.delete && (
                        <Button
                            variant="ghost"
                            size="md"
                            icon={<Trash2 aria-hidden="true" />}
                            onClick={() => {
                                if (!window.confirm(`Delete ${expense.number}?`)) {
                                    return;
                                }

                                router.delete(`/expenses/${expense.number}`);
                            }}
                        >
                            Delete
                        </Button>
                    )}
                </div>
            }
        >
            <Head title={expense.number} />

            <WorkflowBanner expense={expense} can={can} />

            {rejecting && (
                <RejectForm number={expense.number} onClose={() => setRejecting(false)} />
            )}

            {voiding && <VoidForm number={expense.number} onClose={() => setVoiding(false)} />}

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Fact label="Date">{expense.expense_date}</Fact>

                <Fact label="Whose money">
                    {expense.payment_mode_label}
                    {expense.reimburse_user_name !== null && (
                        <span className="text-content-muted block text-xs">
                            {expense.reimburse_user_name} is owed this
                        </span>
                    )}
                    {expense.paid_through !== null && (
                        <span className="text-content-muted block text-xs">
                            {expense.paid_through}
                        </span>
                    )}
                </Fact>

                <Fact label="Total">
                    {formatMoney(expense.total, { currency: expense.currency })}
                </Fact>

                <Fact label="Status">
                    <Badge tone={(expense.status_tone as BadgeTone) ?? 'neutral'}>
                        {expense.status_label}
                    </Badge>
                </Fact>
            </div>

            <div className="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <Card flush>
                    <header className="border-line-subtle flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">What was spent</h2>
                        {expense.prices_include_tax && (
                            <span className="text-content-muted text-xs">Amounts include tax</span>
                        )}
                    </header>

                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Description</th>
                                    <th className="px-4 py-2 text-left font-medium">Charged to</th>
                                    <th className="px-4 py-2 text-right font-medium">Net</th>
                                    <th className="px-4 py-2 text-right font-medium">Tax</th>
                                    <th className="px-4 py-2 text-right font-medium">Total</th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {expense.lines.map((line) => (
                                    <tr key={line.id} className="hover:bg-surface-hover">
                                        <td className="px-4 py-2.5">
                                            <span className="text-content">{line.description}</span>
                                            {line.kind === 'mileage' && (
                                                <span className="text-content-muted block text-xs">
                                                    {trimZeros(line.quantity)} {line.unit} at{' '}
                                                    {line.unit_price} per {line.unit}
                                                </span>
                                            )}
                                        </td>

                                        <td className="text-content-secondary px-4 py-2.5 text-xs">
                                            {line.account ?? '—'}
                                        </td>

                                        <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                            {formatMoney(line.taxable, {
                                                currency: expense.currency,
                                                showCurrency: false,
                                            })}
                                        </td>

                                        <td className="px-4 py-2.5 text-right tabular-nums">
                                            {isZero(line.tax_total) ? (
                                                <span className="text-content-disabled">—</span>
                                            ) : (
                                                <>
                                                    <span className="text-content-secondary">
                                                        {formatMoney(line.tax_total, {
                                                            currency: expense.currency,
                                                            showCurrency: false,
                                                        })}
                                                    </span>
                                                    {/*
                                                     * Said on the line: this
                                                     * tax is part of what the
                                                     * expense cost.
                                                     */}
                                                    {!line.tax_is_claimable && (
                                                        <Badge tone="warning">In the cost</Badge>
                                                    )}
                                                </>
                                            )}
                                        </td>

                                        <td className="text-content px-4 py-2.5 text-right font-medium tabular-nums">
                                            {formatMoney(line.total, {
                                                currency: expense.currency,
                                                showCurrency: false,
                                            })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <footer className="border-line-subtle grid gap-4 border-t px-4 py-3 lg:grid-cols-[1fr_16rem]">
                        <div className="flex flex-col gap-2 text-sm">
                            {expense.notes !== null && (
                                <div>
                                    <p className="text-content-muted text-2xs uppercase">Notes</p>
                                    <p className="text-content-secondary whitespace-pre-line">
                                        {expense.notes}
                                    </p>
                                </div>
                            )}

                            {expense.journal_entry_no !== null && (
                                <p className="text-content-muted text-xs">
                                    Posted as{' '}
                                    <Link
                                        href={`/accounting/journals/${expense.journal_entry_no}`}
                                        className="text-brand-text tabular-nums hover:underline"
                                    >
                                        {expense.journal_entry_no}
                                    </Link>
                                    {expense.approved_at === null
                                        ? ''
                                        : ` on approval, ${expense.approved_at}`}
                                    .
                                </p>
                            )}

                            {hasBlockedTax && (
                                <p className="text-content-muted text-xs">
                                    {formatMoney(expense.tax_capitalised, {
                                        currency: expense.currency,
                                        showCurrency: false,
                                    })}{' '}
                                    of the tax cannot be reclaimed, so it is part of the cost rather
                                    than a receivable.
                                </p>
                            )}

                            {expense.billed_invoice !== null && (
                                <p className="text-content-muted text-xs">
                                    Rebilled on{' '}
                                    <Link
                                        href={`/sales/${expense.billed_invoice.url}/${expense.billed_invoice.number}`}
                                        className="text-brand-text tabular-nums hover:underline"
                                    >
                                        {expense.billed_invoice.number}
                                    </Link>
                                    .
                                </p>
                            )}
                        </div>

                        <dl className="flex flex-col gap-1.5 text-sm">
                            <Total
                                label="Before tax"
                                value={expense.subtotal}
                                currency={expense.currency}
                            />

                            {expense.tax_summary.map((tax) => (
                                <Total
                                    key={`${tax.name}-${tax.rate}`}
                                    label={tax.name}
                                    value={tax.amount}
                                    currency={expense.currency}
                                    muted
                                />
                            ))}

                            <div className="border-line-subtle mt-1 border-t pt-2">
                                <Total
                                    label="Total"
                                    value={expense.total}
                                    currency={expense.currency}
                                    emphasis
                                />
                            </div>

                            {!isZero(expense.tax_total) && (
                                <Total
                                    label="Reclaimable tax"
                                    value={expense.tax_claimable_total}
                                    currency={expense.currency}
                                    muted
                                />
                            )}
                        </dl>
                    </footer>
                </Card>

                <Receipts expense={expense} can={can} />
            </div>
        </AppLayout>
    );
}

/**
 * What state this is in, and what happens next.
 *
 * One banner rather than four scattered hints, because the answer to "what do
 * I do with this" is the first thing anybody opening an expense wants.
 */
function WorkflowBanner({
    expense,
    can,
}: {
    expense: ShowProps['expense'];
    can: Record<string, boolean>;
}) {
    if (expense.is_void) {
        return (
            <Card className="border-line-danger mb-4">
                <p className="text-content-secondary flex flex-wrap items-center gap-2 text-sm">
                    <Badge tone="neutral">Void</Badge>
                    <span>
                        Withdrawn{expense.voided_at === null ? '' : ` on ${expense.voided_at}`}.
                    </span>
                    {expense.void_journal_entry_no !== null && (
                        <>
                            <span className="text-content-muted">Reversed by</span>
                            <Link
                                href={`/accounting/journals/${expense.void_journal_entry_no}`}
                                className="text-brand-text tabular-nums hover:underline"
                            >
                                {expense.void_journal_entry_no}
                            </Link>
                        </>
                    )}
                    <span className="text-content-muted text-xs">
                        The original entry survives — corrections never erase history.
                    </span>
                </p>
            </Card>
        );
    }

    if (expense.status === 'rejected') {
        return (
            <Card className="border-line-danger mb-4">
                {/*
                 * The alert role goes on the message, not on the Card: Card
                 * takes no arbitrary attributes, and a screen reader needs
                 * the text announced rather than the container labelled.
                 */}
                <p role="alert" className="text-content text-sm font-medium">
                    Sent back.
                </p>
                <p className="text-content-secondary text-sm">
                    {expense.rejection_reason ?? 'No reason given.'}
                </p>
                <p className="text-content-muted text-xs">
                    Edit it and submit again — editing clears this note, so it stays about the
                    version somebody actually read.
                </p>
            </Card>
        );
    }

    if (expense.status === 'submitted') {
        return (
            <Card className="border-line-brand mb-4">
                <p className="text-content text-sm font-medium">Waiting for approval.</p>
                <p className="text-content-secondary text-sm">
                    Submitted{expense.submitted_at === null ? '' : ` on ${expense.submitted_at}`}
                    {expense.submitted_by_name === null ? '' : ` by ${expense.submitted_by_name}`}.
                    Nothing has posted, so this cost is not on the books yet.
                </p>
                {!can.approve && !can.reject && (
                    <p className="text-content-muted text-xs">
                        {can.self_approval_allowed
                            ? 'You need approval rights to review this.'
                            : 'You submitted this, so somebody else has to approve it. An approval by the person claiming the money is not a review.'}
                    </p>
                )}
            </Card>
        );
    }

    if (expense.is_posted) {
        return (
            <Card className="border-status-success-line mb-4">
                <p className="text-content text-sm font-medium">
                    Approved
                    {expense.approved_by_name === null ? '' : ` by ${expense.approved_by_name}`}.
                </p>
                <p className="text-content-secondary text-sm">
                    {expense.payment_mode === 'reimbursable'
                        ? `${expense.reimburse_user_name ?? 'Whoever paid'} is owed ${formatMoney(
                              expense.total,
                              { currency: expense.currency },
                          )} until it is paid back.`
                        : 'Posted to the ledger and paid from a company account.'}
                </p>
            </Card>
        );
    }

    return (
        <Card className="mb-4">
            <p className="text-content text-sm font-medium">Not submitted yet.</p>
            <p className="text-content-secondary text-sm">
                Attach the receipt, then submit it. Nothing reaches the ledger until somebody with
                approval rights agrees.
            </p>
        </Card>
    );
}

/**
 * The receipts.
 *
 * Served through the application rather than from storage: a receipt is a
 * financial record, and a storage URL that works without a session is a
 * document that leaks with no audit trail.
 */
function Receipts({
    expense,
    can,
}: {
    expense: ShowProps['expense'];
    can: Record<string, boolean>;
}) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ receipt: File | null }>({ receipt: null });

    const upload = (file: File) => {
        form.setData('receipt', file);

        router.post(
            `/expenses/${expense.number}/receipts`,
            { receipt: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    if (input.current !== null) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <Card flush>
            <header className="border-line-subtle border-b px-4 py-3">
                <h2 className="text-content text-md font-semibold">Receipts</h2>
                <p className="text-content-muted text-xs">
                    A photograph, a scan or a PDF, up to 10 MB.
                </p>
            </header>

            {expense.receipts.length === 0 ? (
                <div className="flex flex-col items-center gap-2 px-4 py-8 text-center">
                    <Camera className="text-content-muted size-6" aria-hidden="true" />
                    <p className="text-content-secondary text-sm">Nothing attached.</p>
                    <p className="text-content-muted text-xs">
                        An expense without one is harder to defend at audit than to photograph now.
                    </p>
                </div>
            ) : (
                <ul className="divide-line-subtle divide-y">
                    {expense.receipts.map((receipt) => (
                        <li
                            key={receipt.id}
                            className="flex items-center justify-between gap-2 px-4 py-2.5"
                        >
                            <a
                                href={`/expenses/${expense.number}/receipts/${receipt.id}`}
                                target="_blank"
                                rel="noreferrer"
                                className="text-brand-text flex min-w-0 items-center gap-2 text-sm hover:underline"
                            >
                                <Paperclip className="size-3.5 shrink-0" aria-hidden="true" />
                                <span className="truncate">{receipt.name}</span>
                            </a>

                            <div className="flex shrink-0 items-center gap-2">
                                <span className="text-content-muted text-xs tabular-nums">
                                    {receipt.size}
                                </span>
                                {can.attach && (
                                    <button
                                        type="button"
                                        aria-label={`Remove ${receipt.name}`}
                                        onClick={() => {
                                            if (!window.confirm(`Remove ${receipt.name}?`)) {
                                                return;
                                            }

                                            router.delete(
                                                `/expenses/${expense.number}/receipts/${receipt.id}`,
                                                { preserveScroll: true },
                                            );
                                        }}
                                        className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 rounded p-1 transition-colors"
                                    >
                                        <Trash2 className="size-3.5" aria-hidden="true" />
                                    </button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {can.attach && (
                <footer className="border-line-subtle border-t px-4 py-3">
                    <label
                        htmlFor="receipt-upload"
                        className="text-content-secondary block cursor-pointer text-sm"
                    >
                        Attach a receipt
                    </label>
                    <input
                        id="receipt-upload"
                        ref={input}
                        type="file"
                        name="receipt"
                        accept="image/jpeg,image/png,image/webp,image/heic,application/pdf"
                        onChange={(e) => {
                            const file = e.target.files?.[0];

                            if (file !== undefined) {
                                upload(file);
                            }
                        }}
                        className="text-content-secondary mt-1 text-xs"
                    />

                    {form.errors.receipt !== undefined && (
                        <p
                            role="alert"
                            className="text-danger-600 dark:text-danger-400 mt-2 text-xs"
                        >
                            {form.errors.receipt}
                        </p>
                    )}
                </footer>
            )}
        </Card>
    );
}

function RejectForm({ number, onClose }: { number: string; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const send = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/expenses/${number}/reject`, { onSuccess: onClose });
    };

    return (
        <Card flush className="mb-4">
            <form onSubmit={send}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Send {number} back</h2>
                    <p className="text-content-muted text-xs">
                        Nothing has posted, so nothing is reversed. It becomes editable again and
                        keeps its number.
                    </p>
                </header>

                <div className="p-4">
                    <Input
                        label="Reason"
                        name="reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        error={form.errors.reason}
                        placeholder="Wrong category, no receipt, this looks personal"
                        hint="Required — a rejection with no reason leaves nothing to act on."
                        required
                    />
                </div>

                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="ghost" size="md" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={form.processing}
                        icon={<Check aria-hidden="true" />}
                    >
                        Send it back
                    </Button>
                </footer>
            </form>
        </Card>
    );
}

function VoidForm({ number, onClose }: { number: string; onClose: () => void }) {
    const form = useForm({ reason: '' });

    const send = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/expenses/${number}/void`, { onSuccess: onClose });
    };

    return (
        <Card flush className="border-line-danger mb-4">
            <form onSubmit={send}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Void {number}</h2>
                    <p className="text-content-muted text-xs">
                        A reversing entry is posted, and both stay in the ledger. The approval stays
                        on the record too — somebody did approve this before it was withdrawn.
                    </p>
                </header>

                <div className="p-4">
                    <Input
                        label="Reason"
                        name="reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        error={form.errors.reason}
                        placeholder="Personal, duplicate, claimed twice"
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
                        icon={<Ban aria-hidden="true" />}
                    >
                        Void it
                    </Button>
                </footer>
            </form>
        </Card>
    );
}

function Total({
    label,
    value,
    currency,
    emphasis = false,
    muted = false,
}: {
    label: string;
    value: string;
    currency: string;
    emphasis?: boolean;
    muted?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt
                className={cn(
                    muted ? 'text-content-muted text-xs' : 'text-content-secondary',
                    emphasis && 'text-content font-semibold',
                )}
            >
                {label}
            </dt>
            <dd
                className={cn(
                    'tabular-nums',
                    muted && 'text-content-muted text-xs',
                    !muted && !emphasis && 'text-content font-medium',
                    emphasis && 'text-md text-content font-semibold',
                )}
            >
                {formatMoney(value, { currency, showCurrency: false })}
            </dd>
        </div>
    );
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <Card>
            <p className="text-content-muted text-2xs uppercase">{label}</p>
            <p className="text-content mt-1 text-sm font-medium">{children}</p>
        </Card>
    );
}

/** 1.000000 reads as 1; 2.500000 reads as 2.5. */
function trimZeros(value: string): string {
    return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}
