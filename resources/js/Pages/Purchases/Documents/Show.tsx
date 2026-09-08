import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Ban, Check, Pencil, ShieldCheck, Trash2, Wallet } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import type { PurchaseSummary, PurchaseTypeProps } from './Index';
import { formatMoney, isZero, sumForDisplay } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface LineTax {
    name: string;
    rate: string;
    amount: string;
    is_claimable: boolean;
}

interface TaxSummaryRow {
    name: string;
    rate: string;
    amount: string;
    claimable: string;
}

interface PurchaseLine {
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
    tax_is_claimable: boolean;
    capitalised_cost: string;
    total: string;
    taxes: LineTax[];
}

interface ShowProps {
    type: PurchaseTypeProps;
    document: PurchaseSummary & {
        notes: string | null;
        terms: string | null;
        discount_type: string | null;
        discount_value: string | null;
        prices_include_tax: boolean;
        expires_on: string | null;
        billing_address: Record<string, string> | null;
        approved_at: string | null;
        voided_at: string | null;
        journal_entry_no: string | null;
        void_journal_entry_no: string | null;
        converted_from: { number: string; type: string; url: string } | null;
        credits_document: { number: string; url: string } | null;
        lines: PurchaseLine[];
        tax_summary: TaxSummaryRow[];
        payments: { number: string | null; date: string | null; amount: string }[];
    };
    contact: {
        id: string;
        display_name: string;
        email: string | null;
        payable: string;
    };
    baseCurrency: string;
    can: Record<string, boolean>;
}

/**
 * One purchase document, in full.
 *
 * The screen is built around the moment that matters on this side: approval.
 * A draft is somebody's transcription of what a vendor sent; approving it is
 * the decision that we owe the money, and it is the step that posts. So the
 * approve button says what it will do, and the page says plainly when nothing
 * has been approved yet.
 *
 * Where a line's input tax cannot be reclaimed, the page says so on the line
 * rather than only in the total — that tax is part of the cost, and a reader
 * comparing two vendors needs to see it.
 *
 * @see ACCOUNTING_RULES.md §4.6, §6
 */
export default function PurchaseDocumentShow({
    type,
    document,
    contact,
    baseCurrency,
    can,
}: ShowProps) {
    const [voiding, setVoiding] = useState(false);
    const [converting, setConverting] = useState(false);

    const approve = useForm({ post_to_closed_period: false });

    const showTaxColumn = document.tax_summary.length > 0;
    const hasBlockedTax = !isZero(document.tax_capitalised);

    return (
        <AppLayout
            title={document.number}
            description={`${type.label} from ${contact.display_name}`}
            breadcrumbs={[
                { label: 'Purchases' },
                { label: type.plural, href: `/purchases/${type.segment}` },
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
                                router.get(`/purchases/${type.segment}/${document.number}/edit`)
                            }
                        >
                            Edit
                        </Button>
                    )}

                    {document.is_editable && can.approve && (
                        <Button
                            variant="primary"
                            size="md"
                            loading={approve.processing}
                            icon={<ShieldCheck aria-hidden="true" />}
                            onClick={() => {
                                if (
                                    !window.confirm(
                                        `${type.issue_verb} ${document.number}?\n\n` +
                                            (type.posts
                                                ? 'This recognises the liability and posts to the ledger. Afterwards the document cannot be edited — only credited or voided, both of which leave it on the record.'
                                                : 'A commitment document has no accounting effect, so nothing is posted.'),
                                    )
                                ) {
                                    return;
                                }

                                approve.post(
                                    `/purchases/${type.segment}/${document.number}/approve`,
                                );
                            }}
                        >
                            {type.issue_verb}
                        </Button>
                    )}

                    {can.pay && (
                        <Button
                            variant="primary"
                            size="md"
                            icon={<Wallet aria-hidden="true" />}
                            onClick={() =>
                                router.get(`/purchases/payments/new?bill=${document.number}`)
                            }
                        >
                            Pay
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
                                Convert to bill
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

                                router.delete(`/purchases/${type.segment}/${document.number}`);
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

            {/*
             * The state that has no equivalent on the sales side, and the one
             * worth saying out loud: this money is owed and the books do not
             * know about it yet.
             */}
            {document.is_editable && type.posts && (
                <Card className="border-line-brand mb-4">
                    <p className="text-content-secondary text-sm">
                        <span className="text-content font-medium">Not yet approved.</span> Nothing
                        has been posted, so this {type.label.toLowerCase()} is not on the balance
                        sheet — check it against what was ordered and received, then approve it.
                    </p>
                </Card>
            )}

            {(document.converted_from !== null || document.credits_document !== null) && (
                <Card className="border-line-brand mb-4">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        {document.converted_from !== null && (
                            <>
                                <span className="text-content-secondary">Ordered on</span>
                                <Link
                                    href={`/purchases/${document.converted_from.url}/${document.converted_from.number}`}
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
                                    href={`/purchases/${document.credits_document.url}/${document.credits_document.number}`}
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
                <Fact label="Vendor">
                    <Link
                        href={`/sales/customers/${contact.id}`}
                        className="text-brand-text hover:underline"
                    >
                        {contact.display_name}
                    </Link>
                </Fact>

                <Fact label={type.posts ? 'Their reference' : 'Reference'}>
                    <span className="tabular-nums">
                        {document.vendor_reference ?? document.reference ?? '—'}
                    </span>
                </Fact>

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
                                const discount = sumForDisplay([
                                    line.discount_amount,
                                    line.document_discount_amount,
                                ]);

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
                                                        {/*
                                                         * Said on the line, not
                                                         * only in the total:
                                                         * this tax is part of
                                                         * what the purchase
                                                         * cost.
                                                         */}
                                                        {!line.tax_is_claimable && (
                                                            <Badge tone="warning">
                                                                In the cost
                                                            </Badge>
                                                        )}
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
                                {document.approved_at === null
                                    ? ''
                                    : ` on approval, ${document.approved_at}`}
                                .
                            </p>
                        )}

                        {hasBlockedTax && (
                            <p className="text-content-muted text-xs">
                                {formatMoney(document.tax_capitalised, {
                                    currency: document.currency,
                                    showCurrency: false,
                                })}{' '}
                                of the tax on this {type.label.toLowerCase()} cannot be reclaimed,
                                so it is part of the cost rather than a receivable.
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

                        {/*
                         * Reclaimable tax is stated after the total, as a note
                         * rather than a line: it does not change what the
                         * vendor is owed, only what the purchase really costs.
                         */}
                        {!isZero(document.tax_total) && (
                            <Total
                                label="Reclaimable tax"
                                value={document.tax_claimable_total}
                                currency={document.currency}
                                muted
                            />
                        )}

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
                                    label="Balance owed"
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
                                    <th className="px-4 py-2 text-left font-medium">Payment</th>
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
    type: PurchaseTypeProps;
    number: string;
    posts: boolean;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/purchases/${type.segment}/${number}/void`, { onSuccess: onClose });
    };

    return (
        <Card flush className="border-line-danger mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Void {number}</h2>
                    <p className="text-content-muted text-xs">
                        {posts
                            ? 'A reversing entry is posted, and both stay in the ledger. The number is kept, so the sequence has no unexplained gap.'
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
                        placeholder="Duplicate, wrong vendor, disputed — the only explanation on the record."
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
    type: PurchaseTypeProps;
    number: string;
    onClose: () => void;
}) {
    const form = useForm({ to: type.convertible_to[0]?.value ?? 'bill' });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post(`/purchases/${type.segment}/${number}/convert`);
    };

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Convert {number}</h2>
                    <p className="text-content-muted text-xs">
                        A draft bill is created from this order, priced as ordered and pointing back
                        at it. Nothing is approved — comparing what the vendor charged against what
                        was ordered is the whole point of the step.
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
