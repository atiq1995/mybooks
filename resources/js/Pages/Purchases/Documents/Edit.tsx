import { useMemo } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Plus, Save, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import type { PurchaseTypeProps } from './Index';
import {
    divideForDisplay,
    formatMoney,
    isPositiveAmount,
    multiplyForDisplay,
    subtractForDisplay,
    sumForDisplay,
} from '@/Utils/money';

interface Option {
    value: string;
    label: string;
}

interface VendorOption extends Option {
    currency: string | null;
    terms: number;
}

interface ItemOption extends Option {
    description: string;
    unit: string | null;
    price: string | null;
    tax_id: string | null;
    debit_account_id: string | null;
}

interface LineDraft {
    item_id: string;
    description: string;
    unit: string;
    quantity: string;
    unit_price: string;
    discount_type: string;
    discount_value: string;
    tax_id: string;
    debit_account_id: string;
    tax_is_claimable: boolean;
}

interface EditProps {
    type: PurchaseTypeProps;
    document: (Record<string, unknown> & { number: string; lines: LineDraft[] }) | null;
    vendors: VendorOption[];
    items: ItemOption[];
    taxes: (Option & { code: string; inclusive_default: boolean })[];
    accounts: (Option & { type: string })[];
    creditableBills: Option[];
    baseCurrency: string;
    today: string;
}

const EMPTY_LINE: LineDraft = {
    item_id: '',
    description: '',
    unit: '',
    quantity: '1',
    unit_price: '',
    discount_type: '',
    discount_value: '',
    tax_id: '',
    debit_account_id: '',
    // Claimable by default, which is the common case. Defaulting the other
    // way would quietly capitalise recoverable tax into costs, and nobody
    // would notice until a return came up short.
    tax_is_claimable: true,
};

/**
 * The bill editor.
 *
 * The same keyboard-first line editor as the sales side, with two columns it
 * has no equivalent of, both of which exist because a purchase has choices a
 * sale does not:
 *
 * WHERE THE COST GOES. A sale credits the revenue account the item names, and
 * that is nearly always right. A purchase might be an expense, or stock, or
 * equipment — the account is a real decision per line, so it is a column
 * rather than something buried in the item.
 *
 * WHETHER THE TAX CAN BE RECLAIMED. §4.6: input tax that cannot be reclaimed
 * is capitalised into the cost rather than booked as a receivable. That is a
 * fact about what was bought, so it belongs on the line, and getting it wrong
 * either overstates assets or overstates costs.
 *
 * The running totals are display only. The server recomputes every figure
 * through the tax engine at full precision.
 *
 * @see ACCOUNTING_RULES.md §4.6, §5
 */
export default function PurchaseDocumentEdit({
    type,
    document,
    vendors,
    items,
    taxes,
    accounts,
    creditableBills,
    baseCurrency,
    today,
}: EditProps) {
    const isEdit = document !== null;

    const form = useForm<{
        contact_id: string;
        issue_date: string;
        due_date: string;
        expires_on: string;
        vendor_reference: string;
        reference: string;
        notes: string;
        terms: string;
        discount_type: string;
        discount_value: string;
        prices_include_tax: boolean;
        credits_document_id: string;
        lines: LineDraft[];
    }>({
        contact_id: asText(document?.contact_id),
        issue_date: asText(document?.issue_date) || today,
        due_date: asText(document?.due_date),
        expires_on: asText(document?.expires_on),
        vendor_reference: asText(document?.vendor_reference),
        reference: asText(document?.reference),
        notes: asText(document?.notes),
        terms: asText(document?.terms),
        discount_type: asText(document?.discount_type),
        discount_value: asText(document?.discount_value),
        prices_include_tax: document?.prices_include_tax === true,
        credits_document_id: asText(document?.credits_document_id),
        lines:
            document !== null && document.lines.length > 0
                ? document.lines.map((line) => ({
                      item_id: asText(line.item_id),
                      description: asText(line.description),
                      unit: asText(line.unit),
                      quantity: asText(line.quantity) || '1',
                      unit_price: asText(line.unit_price),
                      discount_type: asText(line.discount_type),
                      discount_value: asText(line.discount_value),
                      tax_id: asText(line.tax_id),
                      debit_account_id: asText(line.debit_account_id),
                      tax_is_claimable: line.tax_is_claimable,
                  }))
                : [{ ...EMPTY_LINE }],
    });

    const totals = useMemo(
        () => summarise(form.data.lines, form.data.discount_type, form.data.discount_value),
        [form.data.lines, form.data.discount_type, form.data.discount_value],
    );

    const setLine = (index: number, patch: Partial<LineDraft>) => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    };

    /**
     * Choosing an item fills the row from its defaults — only the fields the
     * user has not already typed into.
     */
    const chooseItem = (index: number, itemId: string) => {
        const item = items.find((candidate) => candidate.value === itemId);
        const line = form.data.lines[index];

        if (item === undefined || line === undefined) {
            setLine(index, { item_id: itemId });

            return;
        }

        setLine(index, {
            item_id: itemId,
            description: line.description === '' ? item.description : line.description,
            unit: line.unit === '' ? (item.unit ?? '') : line.unit,
            unit_price: line.unit_price === '' ? (item.price ?? '') : line.unit_price,
            tax_id: line.tax_id === '' ? (item.tax_id ?? '') : line.tax_id,
            debit_account_id:
                line.debit_account_id === ''
                    ? (item.debit_account_id ?? '')
                    : line.debit_account_id,
        });
    };

    const addLine = () => form.setData('lines', [...form.data.lines, { ...EMPTY_LINE }]);

    const removeLine = (index: number) => {
        if (form.data.lines.length <= 1) {
            form.setData('lines', [{ ...EMPTY_LINE }]);

            return;
        }

        form.setData(
            'lines',
            form.data.lines.filter((_, i) => i !== index),
        );
    };

    /** Enter on the last row adds another, which is the whole rhythm here. */
    const onLineKeyDown = (event: React.KeyboardEvent, index: number) => {
        if (event.key === 'Enter' && index === form.data.lines.length - 1) {
            event.preventDefault();
            addLine();
        }
    };

    const chooseVendor = (contactId: string) => {
        const vendor = vendors.find((candidate) => candidate.value === contactId);

        form.setData((data) => ({
            ...data,
            contact_id: contactId,
            // Their terms decide the due date, unless one has been typed.
            due_date:
                data.due_date === '' && vendor !== undefined && type.has_due_date
                    ? addDays(data.issue_date, vendor.terms)
                    : data.due_date,
        }));
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/purchases/${type.segment}/${document.number}`);

            return;
        }

        form.post(`/purchases/${type.segment}`);
    };

    const currency = useMemo(() => {
        const vendor = vendors.find((v) => v.value === form.data.contact_id);

        return vendor?.currency ?? baseCurrency;
    }, [vendors, form.data.contact_id, baseCurrency]);

    const complete =
        form.data.contact_id !== '' &&
        form.data.lines.every(
            (line) => line.description.trim() !== '' && isPositiveAmount(line.quantity),
        );

    return (
        <AppLayout
            title={isEdit ? `Edit ${document.number}` : `New ${type.label.toLowerCase()}`}
            description={
                type.posts
                    ? 'Saved as a draft. Nothing reaches the ledger until somebody approves it.'
                    : `A ${type.label.toLowerCase()} records a commitment and never posts.`
            }
            breadcrumbs={[
                { label: 'Purchases' },
                { label: type.plural, href: `/purchases/${type.segment}` },
                { label: isEdit ? document.number : 'New' },
            ]}
        >
            <Head title={isEdit ? `Edit ${document.number}` : `New ${type.label}`} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Card>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Select
                            label="Vendor"
                            name="contact_id"
                            value={form.data.contact_id}
                            onChange={(e) => chooseVendor(e.target.value)}
                            error={form.errors.contact_id}
                            options={[{ value: '', label: 'Choose a vendor' }, ...vendors]}
                            containerClassName="sm:col-span-2"
                            required
                        />

                        <Input
                            label={type.value === 'bill' ? 'Bill date' : 'Date'}
                            name="issue_date"
                            type="date"
                            value={form.data.issue_date}
                            onChange={(e) => form.setData('issue_date', e.target.value)}
                            error={form.errors.issue_date}
                            hint="Decides which tax rates apply."
                            required
                        />

                        {type.has_due_date ? (
                            <Input
                                label="Due date"
                                name="due_date"
                                type="date"
                                value={form.data.due_date}
                                onChange={(e) => form.setData('due_date', e.target.value)}
                                error={form.errors.due_date}
                                hint="From the vendor's terms."
                            />
                        ) : (
                            <Input
                                label="Expires"
                                name="expires_on"
                                type="date"
                                value={form.data.expires_on}
                                onChange={(e) => form.setData('expires_on', e.target.value)}
                                error={form.errors.expires_on}
                                optional
                            />
                        )}

                        {type.value === 'bill' && (
                            <Input
                                label="Their reference"
                                name="vendor_reference"
                                value={form.data.vendor_reference}
                                onChange={(e) => form.setData('vendor_reference', e.target.value)}
                                error={form.errors.vendor_reference}
                                placeholder="The vendor's own invoice number"
                                hint="Used to catch the same bill entered twice."
                                containerClassName="sm:col-span-2"
                            />
                        )}

                        <Input
                            label="Our reference"
                            name="reference"
                            value={form.data.reference}
                            onChange={(e) => form.setData('reference', e.target.value)}
                            error={form.errors.reference}
                            placeholder="A job, a contract, a cost centre"
                            containerClassName="sm:col-span-2"
                            optional
                        />

                        {type.value === 'vendor_credit' && (
                            <Select
                                label="Credits bill"
                                name="credits_document_id"
                                value={form.data.credits_document_id}
                                onChange={(e) =>
                                    form.setData('credits_document_id', e.target.value)
                                }
                                error={form.errors.credits_document_id}
                                options={[
                                    { value: '', label: 'Not against a specific bill' },
                                    ...creditableBills,
                                ]}
                                hint="Naming one reduces its balance when this is approved."
                                containerClassName="sm:col-span-2"
                            />
                        )}
                    </div>

                    <label className="text-content-secondary mt-4 flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="prices_include_tax"
                            checked={form.data.prices_include_tax}
                            onChange={(e) => form.setData('prices_include_tax', e.target.checked)}
                            className="border-line accent-brand mt-0.5 size-4 rounded"
                        />
                        <span>
                            Prices include tax
                            <span className="text-content-muted block text-xs">
                                Common on a receipt: the net is extracted from each price rather
                                than tax being added, so the total equals what the vendor charged.
                            </span>
                        </span>
                    </label>
                </Card>

                <Card flush>
                    <header className="border-line-subtle flex items-center justify-between border-b px-4 py-3">
                        <div>
                            <h2 className="text-content text-md font-semibold">Lines</h2>
                            <p className="text-content-muted text-xs">
                                Choose an item to fill a row, or type it. Enter on the last row adds
                                another.
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
                                    <th className="w-44 px-3 py-2 text-left font-medium">Item</th>
                                    <th className="px-3 py-2 text-left font-medium">Description</th>
                                    <th className="w-48 px-3 py-2 text-left font-medium">
                                        Charge to
                                    </th>
                                    <th className="w-20 px-3 py-2 text-right font-medium">Qty</th>
                                    <th className="w-28 px-3 py-2 text-right font-medium">Price</th>
                                    <th className="w-28 px-3 py-2 text-right font-medium">
                                        Discount
                                    </th>
                                    <th className="w-40 px-3 py-2 text-left font-medium">Tax</th>
                                    <th className="w-28 px-3 py-2 text-right font-medium">
                                        Amount
                                    </th>
                                    <th className="w-10 px-3 py-2" />
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {form.data.lines.map((line, index) => (
                                    <tr key={index} className="align-top">
                                        <td className="px-3 py-2">
                                            <Select
                                                aria-label={`Item for line ${index + 1}`}
                                                name={`lines.${index}.item_id`}
                                                value={line.item_id}
                                                onChange={(e) => chooseItem(index, e.target.value)}
                                                options={[
                                                    { value: '', label: '— none —' },
                                                    ...items,
                                                ]}
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Input
                                                aria-label={`Description for line ${index + 1}`}
                                                name={`lines.${index}.description`}
                                                value={line.description}
                                                onChange={(e) =>
                                                    setLine(index, { description: e.target.value })
                                                }
                                                error={errorFor(form.errors, index, 'description')}
                                                onKeyDown={(e) => onLineKeyDown(e, index)}
                                                required
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Select
                                                aria-label={`Account for line ${index + 1}`}
                                                name={`lines.${index}.debit_account_id`}
                                                value={line.debit_account_id}
                                                onChange={(e) =>
                                                    setLine(index, {
                                                        debit_account_id: e.target.value,
                                                    })
                                                }
                                                error={errorFor(
                                                    form.errors,
                                                    index,
                                                    'debit_account_id',
                                                )}
                                                options={[
                                                    { value: '', label: 'Default expense' },
                                                    ...accounts,
                                                ]}
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Input
                                                aria-label={`Quantity for line ${index + 1}`}
                                                name={`lines.${index}.quantity`}
                                                // Text, not number: a number input
                                                // hands back a float, and money
                                                // arithmetic never touches one.
                                                inputMode="decimal"
                                                numeric
                                                value={line.quantity}
                                                onChange={(e) =>
                                                    setLine(index, { quantity: e.target.value })
                                                }
                                                error={errorFor(form.errors, index, 'quantity')}
                                                onKeyDown={(e) => onLineKeyDown(e, index)}
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Input
                                                aria-label={`Price for line ${index + 1}`}
                                                name={`lines.${index}.unit_price`}
                                                inputMode="decimal"
                                                numeric
                                                value={line.unit_price}
                                                onChange={(e) =>
                                                    setLine(index, { unit_price: e.target.value })
                                                }
                                                error={errorFor(form.errors, index, 'unit_price')}
                                                onKeyDown={(e) => onLineKeyDown(e, index)}
                                                placeholder="0.00"
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <div className="flex gap-1">
                                                <Input
                                                    aria-label={`Discount for line ${index + 1}`}
                                                    name={`lines.${index}.discount_value`}
                                                    inputMode="decimal"
                                                    numeric
                                                    value={line.discount_value}
                                                    onChange={(e) =>
                                                        setLine(index, {
                                                            discount_value: e.target.value,
                                                            // A value with no type is
                                                            // half a decision, so the
                                                            // type follows it.
                                                            discount_type:
                                                                e.target.value === ''
                                                                    ? ''
                                                                    : line.discount_type ||
                                                                      'percentage',
                                                        })
                                                    }
                                                    containerClassName="min-w-0 flex-1"
                                                />
                                                <Select
                                                    aria-label={`Discount kind for line ${index + 1}`}
                                                    name={`lines.${index}.discount_type`}
                                                    value={line.discount_type}
                                                    onChange={(e) =>
                                                        setLine(index, {
                                                            discount_type: e.target.value,
                                                        })
                                                    }
                                                    options={[
                                                        { value: 'percentage', label: '%' },
                                                        { value: 'amount', label: currency },
                                                    ]}
                                                    containerClassName="w-16 shrink-0"
                                                />
                                            </div>
                                        </td>

                                        <td className="px-3 py-2">
                                            <Select
                                                aria-label={`Tax for line ${index + 1}`}
                                                name={`lines.${index}.tax_id`}
                                                value={line.tax_id}
                                                onChange={(e) =>
                                                    setLine(index, { tax_id: e.target.value })
                                                }
                                                options={[{ value: '', label: 'No tax' }, ...taxes]}
                                            />

                                            {/*
                                             * Only offered where there is tax
                                             * to reclaim. Shown on the line
                                             * because claimability is a fact
                                             * about what was bought, not
                                             * about the document.
                                             */}
                                            {line.tax_id !== '' && (
                                                <label className="text-content-muted mt-1.5 flex items-start gap-1.5 text-xs">
                                                    <input
                                                        type="checkbox"
                                                        name={`lines.${index}.tax_is_claimable`}
                                                        checked={line.tax_is_claimable}
                                                        onChange={(e) =>
                                                            setLine(index, {
                                                                tax_is_claimable: e.target.checked,
                                                            })
                                                        }
                                                        className="border-line accent-brand mt-0.5 size-3.5 rounded"
                                                    />
                                                    <span>
                                                        Reclaimable
                                                        {!line.tax_is_claimable && (
                                                            <span className="block">
                                                                Added to the cost instead.
                                                            </span>
                                                        )}
                                                    </span>
                                                </label>
                                            )}
                                        </td>

                                        <td className="text-content px-3 py-2 pt-4 text-right tabular-nums">
                                            {formatMoney(lineNet(line), {
                                                currency,
                                                showCurrency: false,
                                            })}
                                        </td>

                                        <td className="px-3 py-2">
                                            <button
                                                type="button"
                                                onClick={() => removeLine(index)}
                                                aria-label={`Remove line ${index + 1}`}
                                                className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 mt-1 rounded p-1.5 transition-colors"
                                            >
                                                <Trash2 className="size-3.5" aria-hidden="true" />
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <footer className="border-line-subtle grid gap-4 border-t px-4 py-3 lg:grid-cols-[1fr_20rem]">
                        <div className="flex flex-col gap-3">
                            <Input
                                label="Notes"
                                name="notes"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                error={form.errors.notes}
                                placeholder="For your own records"
                                optional
                            />

                            <div className="flex items-end gap-2">
                                <Input
                                    label="Discount on the whole document"
                                    name="discount_value"
                                    inputMode="decimal"
                                    numeric
                                    value={form.data.discount_value}
                                    onChange={(e) =>
                                        form.setData((data) => ({
                                            ...data,
                                            discount_value: e.target.value,
                                            discount_type:
                                                e.target.value === ''
                                                    ? ''
                                                    : data.discount_type || 'percentage',
                                        }))
                                    }
                                    error={form.errors.discount_value}
                                    hint="Spread across the lines, pro rata, and it reduces the cost."
                                    containerClassName="w-40"
                                />
                                <Select
                                    aria-label="Document discount kind"
                                    name="discount_type"
                                    value={form.data.discount_type}
                                    onChange={(e) => form.setData('discount_type', e.target.value)}
                                    options={[
                                        { value: 'percentage', label: '%' },
                                        { value: 'amount', label: currency },
                                    ]}
                                    containerClassName="w-20"
                                />
                            </div>
                        </div>

                        <dl className="flex flex-col gap-1.5 text-sm">
                            <Total label="Subtotal" value={totals.subtotal} currency={currency} />

                            {totals.discount !== '0.0000' && (
                                <Total
                                    label="Discount"
                                    value={`-${totals.discount}`}
                                    currency={currency}
                                />
                            )}

                            <Total
                                label={form.data.prices_include_tax ? 'Net' : 'Taxable'}
                                value={totals.taxable}
                                currency={currency}
                                muted
                            />

                            <div className="border-line-subtle text-content-muted border-t pt-2 text-xs">
                                Tax is computed by the server and shown once saved.
                            </div>
                        </dl>
                    </footer>
                </Card>

                <div className="flex items-center justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="md"
                        onClick={() => router.get(`/purchases/${type.segment}`)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={form.processing}
                        disabled={!complete}
                        icon={<Save aria-hidden="true" />}
                    >
                        Save draft
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}

function Total({
    label,
    value,
    currency,
    muted = false,
}: {
    label: string;
    value: string;
    currency: string;
    muted?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className={muted ? 'text-content-muted text-xs' : 'text-content-secondary'}>
                {label}
            </dt>
            <dd
                className={
                    muted
                        ? 'text-content-muted text-xs tabular-nums'
                        : 'text-content font-medium tabular-nums'
                }
            >
                {formatMoney(value, { currency, showCurrency: false })}
            </dd>
        </div>
    );
}

/**
 * One line's value after its own discount.
 *
 * Display only. The tax engine recomputes this — and the tax on it — server
 * side, at the precision §5 demands.
 */
function lineNet(line: LineDraft): string {
    if (!isPositiveAmount(line.quantity) || line.unit_price === '') {
        return '0.0000';
    }

    const gross = multiplyForDisplay(line.quantity, line.unit_price);

    if (line.discount_value === '') {
        return gross;
    }

    const discount =
        line.discount_type === 'amount'
            ? line.discount_value
            : multiplyForDisplay(gross, divideForDisplay(line.discount_value, '100'));

    return subtractForDisplay(gross, discount);
}

/**
 * Running totals, for the screen.
 *
 * Tax is deliberately absent: resolving which components apply on a given
 * date is a database question, and guessing it here would show a figure the
 * server then contradicts.
 */
function summarise(
    lines: LineDraft[],
    documentDiscountType: string,
    documentDiscountValue: string,
): { subtotal: string; discount: string; taxable: string } {
    const subtotal = sumForDisplay(
        lines.map((line) =>
            isPositiveAmount(line.quantity) && line.unit_price !== ''
                ? multiplyForDisplay(line.quantity, line.unit_price)
                : '0',
        ),
    );

    const lineNets = sumForDisplay(lines.map((line) => lineNet(line)));
    const lineDiscount = subtractForDisplay(subtotal, lineNets);

    const documentDiscount =
        documentDiscountValue === ''
            ? '0.0000'
            : documentDiscountType === 'amount'
              ? documentDiscountValue
              : multiplyForDisplay(lineNets, divideForDisplay(documentDiscountValue, '100'));

    return {
        subtotal,
        discount: sumForDisplay([lineDiscount, documentDiscount]),
        taxable: subtractForDisplay(lineNets, documentDiscount),
    };
}

function asText(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function addDays(from: string, days: number): string {
    const date = new Date(`${from}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

function errorFor(
    errors: Record<string, string | undefined>,
    index: number,
    field: string,
): string | undefined {
    return errors[`lines.${index}.${field}`];
}
