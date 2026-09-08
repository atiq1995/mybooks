import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Ban, Check, Pencil, Send, Trash2, Wallet } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import type { DocumentSummary, DocumentTypeProps } from './Index';
import { formatMoney, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface LineTax {
    name: string;
    rate: string;
    amount: string;
}

interface DocumentLine {
    id: string;
    line_no: number;
    item_name: string | null;
    description: string;
    unit: string | null;
    quantity: string;
    unit_price: string;
    discount_amount: string;
    document_discount_amount: string;
    taxable: string;
    tax_total: string;
    total: string;
    taxes: LineTax[];
}

interface ShowProps {
    type: DocumentTypeProps;
    document: DocumentSummary & {
        notes: string | null;
        terms: string | null;
        discount_type: string | null;
        discount_value: string | null;
        prices_include_tax: boolean;
        expires_on: string | null;
        billing_address: Record<string, string> | null;
        issued_at: string | null;
        voided_at: string | null;
        journal_entry_no: string | null;
        void_journal_entry_no: string | null;
        converted_from: { number: string; type: string; url: string } | null;
        credits_document: { number: string; url: string } | null;
        lines: DocumentLine[];
        tax_summary: LineTax[];
        payments: { number: string | null; date: string | null; amount: string }[];
    };
    contact: {
        id: string;
        display_name: string;
        email: string | null;
        outstanding: string;
    };
    baseCurrency: string;
    can: Record<string, boolean>;
}

/**
 * One sales document, in full.
 *
 * A draft can be edited or deleted. An issued one cannot: it is corrected by
 * a credit note or withdrawn by a void, and both leave the original on the
 * record. The actions available say which state this is in, rather than the
 * user discovering it from an error.
 *
 * @see ACCOUNTING_RULES.md §6
 */
export default function DocumentShow({ type, document, contact, baseCurrency, can }: ShowProps) {
    const [voiding, setVoiding] = useState(false);
    const [converting, setConverting] = useState(false);

    const issue = useForm({ post_to_closed_period: false });

    const showTaxColumn = document.tax_summary.length > 0;

    return (
        <AppLayout
            title={document.number}
            description={`${type.label} for ${contact.display_name}`}
            breadcrumbs={[
                { label: 'Sales' },
                { label: type.plural, href: `/sales/${type.segment}` },
                { label: document.number },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {document.is_editable && can.update && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<Pencil aria-hidden="true" />}
                            onClick={() =>
                                router.get(`/sales/${type.segment}/${document.number}/edit`)
                            }
                        >
                            Edit
                        </Button>
                    )}

                    {document.is_editable && can.issue && (
                        <Button
                            variant="primary"
                            size="md"
                            loading={issue.processing}
                            icon={<Send aria-hidden="true" />}
                            onClick={() => {
                                if (
                                    !window.confirm(
                                        `Issue ${document.number}?\n\n` +
                                            (type.posts
                                                ? 'This posts to the ledger. Afterwards the document cannot be edited — only credited or voided, both of which leave it on the record.'
                                                : 'A commitment document has no accounting effect, so nothing is posted.'),
                                    )
                                ) {
                                    return;
                                }

                                issue.post(`/sales/${type.segment}/${document.number}/issue`);
                            }}
                        >
                            Issue
                        </Button>
                    )}

                    {can.record_payment && (
                        <Button
                            variant="primary"
                            size="md"
                            icon={<Wallet aria-hidden="true" />}
                            onClick={() =>
                                router.get(`/sales/payments/new?invoice=${document.number}`)
                            }
                        >
                            Record payment
                        </Button>
                    )}

                    {document.is_issued &&
                        !document.is_void &&
                        type.convertible_to.length > 0 &&
                        can.create && (
                            <Button
                                variant="secondary"
                                size="md"
                                icon={<ArrowRight aria-hidden="true" />}
                                onClick={() => setConverting(true)}
                            >
                                Convert
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

                    {document.is_editable && can.update && (
                        <Button
                            variant="ghost"
                            size="md"
                            icon={<Trash2 aria-hidden="true" />}
                            onClick={() => {
                                if (!window.confirm(`Delete draft ${document.number}?`)) {
                                    return;
                                }

                                router.delete(`/sales/${type.segment}/${document.number}`);
                            }}
                        >
                            Delete
                        </Button>
                    )}
                </div>
            }
        >
            <Head title={document.number} />

            {document.is_void && (
                <Card className="border-line-danger mb-4">
                    <p className="text-content-secondary flex flex-wrap items-center gap-2 text-sm">
                        <Badge tone="neutral">Void</Badge>
                        <span>
                            Withdrawn
                            {document.voided_at === null ? '' : ` on ${document.voided_at}`}.
                        </span>
                        {document.void_journal_entry_no !== null && (
                            <>
                                <span className="text-content-muted">Reversed by</span>
                                <Link
                                    href={`/accounting/journals/${document.void_journal_entry_no}`}
                                    className="text-brand-text tabular-nums hover:underline"
                                >
                                    {document.void_journal_entry_no}
                                </Link>
                            </>
                        )}
                        <span className="text-content-muted text-xs">
                            The original entry survives — corrections never erase history.
                        </span>
                    </p>
                </Card>
            )}

            {(document.converted_from !== null || document.credits_document !== null) && (
                <Card className="border-line-brand mb-4">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        {document.converted_from !== null && (
                            <>
                                <span className="text-content-secondary">Converted from</span>
                                <Link
                                    href={`/sales/${document.converted_from.url}/${document.converted_from.number}`}
                                    className="text-brand-text font-medium hover:underline"
                                >
                                    {document.converted_from.number}
                                </Link>
                            </>
                        )}

                        {document.credits_document !== null && (
                            <>
                                <span className="text-content-secondary">Credits</span>
                                <Link
                                    href={`/sales/${document.credits_document.url}/${document.credits_document.number}`}
                                    className="text-brand-text font-medium hover:underline"
                                >
                                    {document.credits_document.number}
                                </Link>
                            </>
                        )}
                    </div>
                </Card>
            )}

            {voiding && (
                <VoidForm
                    type={type}
                    number={document.number}
                    posts={type.posts}
                    onClose={() => setVoiding(false)}
                />
            )}

            {converting && (
                <ConvertForm
                    type={type}
                    number={document.number}
                    onClose={() => setConverting(false)}
                />
            )}

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Fact label="Customer">
                    <Link
                        href={`/sales/customers/${contact.id}`}
                        className="text-brand-text hover:underline"
                    >
                        {contact.display_name}
                    </Link>
                </Fact>

                <Fact label="Issued">{document.issue_date}</Fact>

                {type.has_due_date ? (
                    <Fact label="Due">
                        <span
                            className={
                                document.is_overdue
                                    ? 'text-danger-600 dark:text-danger-400'
                                    : undefined
                            }
                        >
                            {document.due_date ?? '—'}
                            {document.is_overdue && ` · ${document.days_overdue} days late`}
                        </span>
                    </Fact>
                ) : (
                    <Fact label="Expires">{document.expires_on ?? '—'}</Fact>
                )}

                <Fact label="Status">
                    <Badge
                        tone={
                            document.is_overdue && !document.is_void
                                ? 'danger'
                                : ((document.status_tone as BadgeTone) ?? 'neutral')
                        }
                    >
                        {document.is_overdue && !document.is_void
                            ? 'Overdue'
                            : document.status_label}
                    </Badge>
                </Fact>
            </div>

            <Card flush className="mb-4">
                <header className="border-line-subtle flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Lines</h2>

                    <div className="flex items-center gap-3 text-xs">
                        {document.prices_include_tax && (
                            <span className="text-content-muted">Prices include tax</span>
                        )}
                        {document.is_foreign_currency && (
                            <span className="text-content-muted tabular-nums">
                                1 {document.currency} = {document.exchange_rate} {baseCurrency}
                            </span>
                        )}
                    </div>
                </header>

                <div className="table-scroll">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                <th className="w-10 px-4 py-2 text-left font-medium">#</th>
                                <th className="px-4 py-2 text-left font-medium">Description</th>
                                <th className="px-4 py-2 text-right font-medium">Qty</th>
                                <th className="px-4 py-2 text-right font-medium">Price</th>
                                <th className="px-4 py-2 text-right font-medium">Discount</th>
                                <th className="px-4 py-2 text-right font-medium">Net</th>
                                {showTaxColumn && (
                                    <th className="px-4 py-2 text-right font-medium">Tax</th>
                                )}
                                <th className="px-4 py-2 text-right font-medium">Total</th>
                            </tr>
                        </thead>

                        <tbody className="divide-line-subtle divide-y">
                            {document.lines.map((line) => {
                                const discount = sumTwo(
                                    line.discount_amount,
                                    line.document_discount_amount,
                                );

                                return (
                                    <tr key={line.id} className="hover:bg-surface-hover">
                                        <td className="text-content-muted px-4 py-2.5 tabular-nums">
                                            {line.line_no}
                                        </td>

                                        <td className="px-4 py-2.5">
                                            <span className="text-content">{line.description}</span>
                                            {line.item_name !== null &&
                                                line.item_name !== line.description && (
                                                    <span className="text-content-muted block text-xs">
                                                        {line.item_name}
                                                    </span>
                                                )}
                                        </td>

                                        <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                            {trimZeros(line.quantity)}
                                            {line.unit !== null && (
                                                <span className="text-content-muted ml-1 text-xs">
                                                    {line.unit}
                                                </span>
                                            )}
                                        </td>

                                        <Amount
                                            value={line.unit_price}
                                            currency={document.currency}
                                        />

                                        <Amount value={discount} currency={document.currency} />

                                        <Amount value={line.taxable} currency={document.currency} />

                                        {showTaxColumn && (
                                            <td className="px-4 py-2.5 text-right tabular-nums">
                                                {isZero(line.tax_total) ? (
                                                    <span className="text-content-disabled">—</span>
                                                ) : (
                                                    <>
                                                        <span className="text-content">
                                                            {formatMoney(line.tax_total, {
                                                                currency: document.currency,
                                                                showCurrency: false,
                                                            })}
                                                        </span>
                                                        {line.taxes.map((tax) => (
                                                            <span
                                                                key={tax.name}
                                                                className="text-content-muted block text-xs"
                                                            >
                                                                {tax.name}
                                                            </span>
                                                        ))}
                                                    </>
                                                )}
                                            </td>
                                        )}

                                        <td className="text-content px-4 py-2.5 text-right font-medium tabular-nums">
                                            {formatMoney(line.total, {
                                                currency: document.currency,
                                                showCurrency: false,
                                            })}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <footer className="border-line-subtle grid gap-4 border-t px-4 py-3 lg:grid-cols-[1fr_20rem]">
                    <div className="flex flex-col gap-3 text-sm">
                        {document.notes !== null && (
                            <div>
                                <p className="text-content-muted text-2xs uppercase">Notes</p>
                                <p className="text-content-secondary whitespace-pre-line">
                                    {document.notes}
                                </p>
                            </div>
                        )}

                        {document.journal_entry_no !== null && (
                            <p className="text-content-muted text-xs">
                                Posted as{' '}
                                <Link
                                    href={`/accounting/journals/${document.journal_entry_no}`}
                                    className="text-brand-text tabular-nums hover:underline"
                                >
                                    {document.journal_entry_no}
                                </Link>
                                {document.issued_at === null ? '' : ` on ${document.issued_at}`}.
                            </p>
                        )}

                        {!type.posts && (
                            <p className="text-content-muted text-xs">
                                A {type.label.toLowerCase()} is a commitment, so it has no
                                accounting effect.
                            </p>
                        )}
                    </div>

                    <dl className="flex flex-col gap-1.5 text-sm">
                        <Total
                            label="Subtotal"
                            value={document.subtotal}
                            currency={document.currency}
                        />

                        {!isZero(document.discount_total) && (
                            <Total
                                label="Discount"
                                value={`-${document.discount_total}`}
                                currency={document.currency}
                            />
                        )}

                        {document.tax_summary.map((tax) => (
                            <Total
                                key={`${tax.name}-${tax.rate}`}
                                label={tax.name}
                                value={tax.amount}
                                currency={document.currency}
                                muted
                            />
                        ))}

                        <div className="border-line-subtle mt-1 border-t pt-2">
                            <Total
                                label="Total"
                                value={document.total}
                                currency={document.currency}
                                emphasis
                            />
                        </div>

                        {!isZero(document.amount_paid) && (
                            <Total
                                label="Paid"
                                value={`-${document.amount_paid}`}
                                currency={document.currency}
                                muted
                            />
                        )}

                        {!isZero(document.amount_credited) && (
                            <Total
                                label="Credited"
                                value={`-${document.amount_credited}`}
                                currency={document.currency}
                                muted
                            />
                        )}

                        {type.has_due_date && document.is_issued && (
                            <div className="border-line-subtle border-t pt-2">
                                <Total
                                    label="Balance due"
                                    value={document.balance_due}
                                    currency={document.currency}
                                    emphasis
                                    tone={
                                        isZero(document.balance_due)
                                            ? 'success'
                                            : document.is_overdue
                                              ? 'danger'
                                              : 'neutral'
                                    }
                                />
                            </div>
                        )}
                    </dl>
                </footer>
            </Card>

            {document.payments.length > 0 && (
                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">Payments applied</h2>
                    </header>

                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Receipt</th>
                                    <th className="px-4 py-2 text-left font-medium">Date</th>
                                    <th className="px-4 py-2 text-right font-medium">Applied</th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {document.payments.map((payment, index) => (
                                    <tr key={index} className="hover:bg-surface-hover">
                                        <td className="text-content px-4 py-2 font-medium tabular-nums">
                                            {payment.number ?? '—'}
                                        </td>
                                        <td className="text-content-secondary px-4 py-2 tabular-nums">
                                            {payment.date ?? '—'}
                                        </td>
                                        <Amount
                                            value={payment.amount}
                                            currency={document.currency}
                                        />
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </AppLayout>
    );
}

function VoidForm({
    type,
    number,
    posts,
    onClose,
}: {
    type: DocumentTypeProps;
    number: string;
    posts: boolean;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/sales/${type.segment}/${number}/void`, { onSuccess: onClose });
    };

    return (
        <Card flush className="border-line-danger mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Void {number}</h2>
                    <p className="text-content-muted text-xs">
                        {posts
                            ? 'A reversing entry is posted, and both stay in the ledger. The number is kept — a gap in the numbering reads as a concealed invoice.'
                            : 'The document is withdrawn. Nothing was posted, so there is nothing to reverse.'}
                    </p>
                </header>

                <div className="p-4">
                    <Input
                        label="Reason"
                        name="reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        error={form.errors.reason}
                        placeholder="Why this is being withdrawn — the only explanation on the record."
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

function ConvertForm({
    type,
    number,
    onClose,
}: {
    type: DocumentTypeProps;
    number: string;
    onClose: () => void;
}) {
    const form = useForm({ to: type.convertible_to[0]?.value ?? 'invoice' });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/sales/${type.segment}/${number}/convert`);
    };

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Convert {number}</h2>
                    <p className="text-content-muted text-xs">
                        A copy is created as a draft, pointing back at this one. Nothing is issued —
                        check the dates first, since the tax rates that apply are the new
                        document&rsquo;s, not this one&rsquo;s.
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-[16rem_1fr]">
                    <Select
                        label="Convert to"
                        name="to"
                        value={form.data.to}
                        onChange={(e) => form.setData('to', e.target.value)}
                        error={form.errors.to}
                        options={type.convertible_to}
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
                        Create the draft
                    </Button>
                </footer>
            </form>
        </Card>
    );
}

function Amount({ value, currency }: { value: string; currency: string }) {
    const nil = isZero(value);

    return (
        <td
            className={cn(
                'px-4 py-2.5 text-right tabular-nums',
                nil ? 'text-content-disabled' : 'text-content-secondary',
            )}
        >
            {nil ? '—' : formatMoney(value, { currency, showCurrency: false })}
        </td>
    );
}

function Total({
    label,
    value,
    currency,
    emphasis = false,
    muted = false,
    tone = 'neutral',
}: {
    label: string;
    value: string;
    currency: string;
    emphasis?: boolean;
    muted?: boolean;
    tone?: 'neutral' | 'success' | 'danger';
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
                    emphasis && 'text-md font-semibold',
                    tone === 'success' && 'text-success-700 dark:text-success-400',
                    tone === 'danger' && 'text-danger-600 dark:text-danger-400',
                    tone === 'neutral' && emphasis && 'text-content',
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

function sumTwo(a: string, b: string): string {
    return (Number(a) + Number(b)).toFixed(4);
}
