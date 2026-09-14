import { useMemo } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Plus, Save, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import type { TemplateSummary } from './Index';
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

interface CustomerOption extends Option {
    currency: string | null;
    terms: number;
}

interface ItemOption extends Option {
    description: string;
    unit: string | null;
    price: string | null;
    tax_id: string | null;
    revenue_account_id: string | null;
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
    revenue_account_id: string;
}

interface EditProps {
    template: (TemplateSummary & { lines: LineDraft[] }) | null;
    customers: CustomerOption[];
    items: ItemOption[];
    taxes: (Option & { code: string })[];
    frequencies: Option[];
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
    revenue_account_id: '',
};

/**
 * A recurring-invoice template.
 *
 * Two things are said plainly on this form because getting either wrong is
 * expensive and silent:
 *
 * A START DATE IN THE PAST bills the periods it covers. Somebody entering a
 * retainer that began in January means January onward, and the form says so
 * rather than letting them discover four invoices at once.
 *
 * AUTO-ISSUE IS THE AUTHORISATION to bill without a human present, given once
 * here by somebody who can see what will be sent. Turning it off produces
 * drafts instead.
 */
export default function RecurringEdit({
    template,
    customers,
    items,
    taxes,
    frequencies,
    baseCurrency,
    today,
}: EditProps) {
    const isEdit = template !== null;

    const form = useForm<{
        name: string;
        contact_id: string;
        frequency: string;
        interval: string;
        starts_on: string;
        ends_on: string;
        max_occurrences: string;
        auto_issue: boolean;
        payment_terms_days: string;
        prices_include_tax: boolean;
        reference: string;
        notes: string;
        lines: LineDraft[];
    }>({
        name: template?.name ?? '',
        contact_id: template?.contact_id ?? '',
        frequency: template?.frequency ?? 'monthly',
        interval: String(template?.interval ?? 1),
        starts_on: template?.starts_on ?? today,
        ends_on: template?.ends_on ?? '',
        max_occurrences:
            template?.max_occurrences === null ? '' : String(template?.max_occurrences ?? ''),
        auto_issue: template?.auto_issue ?? true,
        payment_terms_days: String(template?.payment_terms_days ?? 30),
        prices_include_tax: template?.prices_include_tax ?? false,
        reference: template?.reference ?? '',
        notes: '',
        lines:
            template !== null && template.lines.length > 0
                ? template.lines.map((line) => ({
                      item_id: line.item_id ?? '',
                      description: line.description ?? '',
                      unit: line.unit ?? '',
                      quantity: line.quantity ?? '1',
                      unit_price: line.unit_price ?? '',
                      discount_type: line.discount_type ?? '',
                      discount_value: line.discount_value ?? '',
                      tax_id: line.tax_id ?? '',
                      revenue_account_id: line.revenue_account_id ?? '',
                  }))
                : [{ ...EMPTY_LINE }],
    });

    const currency = useMemo(() => {
        const customer = customers.find((c) => c.value === form.data.contact_id);

        return customer?.currency ?? baseCurrency;
    }, [customers, form.data.contact_id, baseCurrency]);

    const subtotal = useMemo(() => sumForDisplay(form.data.lines.map(lineNet)), [form.data.lines]);

    const startsInThePast = form.data.starts_on !== '' && form.data.starts_on < today;

    const setLine = (index: number, patch: Partial<LineDraft>) => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    };

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
            revenue_account_id:
                line.revenue_account_id === ''
                    ? (item.revenue_account_id ?? '')
                    : line.revenue_account_id,
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

    const chooseCustomer = (contactId: string) => {
        const customer = customers.find((candidate) => candidate.value === contactId);

        form.setData((data) => ({
            ...data,
            contact_id: contactId,
            payment_terms_days:
                customer === undefined ? data.payment_terms_days : String(customer.terms),
        }));
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/sales/recurring-invoices/${template.id}`);

            return;
        }

        form.post('/sales/recurring-invoices');
    };

    const complete =
        form.data.contact_id !== '' &&
        form.data.starts_on !== '' &&
        form.data.lines.every(
            (line) => line.description.trim() !== '' && isPositiveAmount(line.quantity),
        );

    return (
        <AppLayout
            title={isEdit ? `Edit ${template.name}` : 'New recurring invoice'}
            description="A standing instruction. Each invoice it produces is an ordinary document with its own number and date."
            breadcrumbs={[
                { label: 'Sales' },
                { label: 'Recurring invoices', href: '/sales/recurring-invoices' },
                { label: isEdit ? template.name : 'New' },
            ]}
        >
            <Head title={isEdit ? `Edit ${template.name}` : 'New recurring invoice'} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Card>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Select
                            label="Customer"
                            name="contact_id"
                            value={form.data.contact_id}
                            onChange={(e) => chooseCustomer(e.target.value)}
                            error={form.errors.contact_id}
                            options={[{ value: '', label: 'Choose a customer' }, ...customers]}
                            required
                        />

                        <Input
                            label="Name"
                            name="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            error={form.errors.name}
                            placeholder="Monthly retainer"
                            hint="What you call it. Left blank, one is written for you."
                            optional
                        />

                        <Input
                            label="Reference"
                            name="reference"
                            value={form.data.reference}
                            onChange={(e) => form.setData('reference', e.target.value)}
                            error={form.errors.reference}
                            placeholder="Their purchase order"
                            hint="Copied onto every invoice this produces."
                            optional
                        />
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">The schedule</h2>
                        <p className="text-content-muted text-xs">
                            Every invoice is dated on its own occurrence, so the tax rates and the
                            due date are the ones that applied then.
                        </p>
                    </header>

                    <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Select
                            label="How often"
                            name="frequency"
                            value={form.data.frequency}
                            onChange={(e) => form.setData('frequency', e.target.value)}
                            error={form.errors.frequency}
                            options={frequencies}
                            required
                        />

                        <Input
                            label="Every"
                            name="interval"
                            inputMode="numeric"
                            value={form.data.interval}
                            onChange={(e) => form.setData('interval', e.target.value)}
                            error={form.errors.interval}
                            hint="1 is every time; 3 with monthly is quarterly."
                        />

                        <Input
                            label="First invoice"
                            name="starts_on"
                            type="date"
                            value={form.data.starts_on}
                            onChange={(e) => form.setData('starts_on', e.target.value)}
                            error={form.errors.starts_on}
                            required
                        />

                        <Input
                            label="Payment terms"
                            name="payment_terms_days"
                            inputMode="numeric"
                            value={form.data.payment_terms_days}
                            onChange={(e) => form.setData('payment_terms_days', e.target.value)}
                            error={form.errors.payment_terms_days}
                            hint="Days after each invoice's own date."
                        />

                        <Input
                            label="Stop after"
                            name="ends_on"
                            type="date"
                            value={form.data.ends_on}
                            onChange={(e) => form.setData('ends_on', e.target.value)}
                            error={form.errors.ends_on}
                            hint="Leave blank for an open-ended arrangement."
                            optional
                        />

                        <Input
                            label="Or after this many"
                            name="max_occurrences"
                            inputMode="numeric"
                            value={form.data.max_occurrences}
                            onChange={(e) => form.setData('max_occurrences', e.target.value)}
                            error={form.errors.max_occurrences}
                            hint="Whichever comes first."
                            optional
                        />
                    </div>

                    {/*
                     * Said before it happens, not discovered afterwards.
                     * Somebody entering a retainer that began in January
                     * means January onward — and four invoices appearing at
                     * once with no warning reads as a bug.
                     */}
                    {startsInThePast && (
                        <p className="border-line-subtle text-content-secondary flex items-start gap-2 border-t px-4 py-2.5 text-sm">
                            <AlertTriangle
                                className="text-warning-600 dark:text-warning-400 mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            That start date is in the past, so the first run will bill every period
                            since — one invoice each, dated correctly. If you only want to bill from
                            now on, move the date forward.
                        </p>
                    )}

                    <div className="border-line-subtle flex flex-col gap-3 border-t px-4 py-3">
                        <label className="text-content-secondary flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="auto_issue"
                                checked={form.data.auto_issue}
                                onChange={(e) => form.setData('auto_issue', e.target.checked)}
                                className="border-line accent-brand mt-0.5 size-4 rounded"
                            />
                            <span>
                                Issue each invoice automatically
                                <span className="text-content-muted block text-xs">
                                    Setting this up is the authorisation to bill without anybody
                                    present. Turn it off and each one waits as a draft for somebody
                                    to check.
                                </span>
                            </span>
                        </label>

                        <label className="text-content-secondary flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="prices_include_tax"
                                checked={form.data.prices_include_tax}
                                onChange={(e) =>
                                    form.setData('prices_include_tax', e.target.checked)
                                }
                                className="border-line accent-brand mt-0.5 size-4 rounded"
                            />
                            <span>Prices include tax</span>
                        </label>
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle flex items-center justify-between border-b px-4 py-3">
                        <div>
                            <h2 className="text-content text-md font-semibold">What to bill</h2>
                            <p className="text-content-muted text-xs">
                                No totals are stored here — every figure is computed on each
                                invoice, at that invoice's date, with the rates in force then.
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
                                    <th className="w-48 px-3 py-2 text-left font-medium">Item</th>
                                    <th className="px-3 py-2 text-left font-medium">Description</th>
                                    <th className="w-24 px-3 py-2 text-right font-medium">Qty</th>
                                    <th className="w-32 px-3 py-2 text-right font-medium">Price</th>
                                    <th className="w-40 px-3 py-2 text-left font-medium">Tax</th>
                                    <th className="w-32 px-3 py-2 text-right font-medium">
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
                                                required
                                            />
                                        </td>

                                        <td className="px-3 py-2">
                                            <Input
                                                aria-label={`Quantity for line ${index + 1}`}
                                                name={`lines.${index}.quantity`}
                                                inputMode="decimal"
                                                numeric
                                                value={line.quantity}
                                                onChange={(e) =>
                                                    setLine(index, { quantity: e.target.value })
                                                }
                                                error={errorFor(form.errors, index, 'quantity')}
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
                                                placeholder="0.00"
                                            />
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

                    <footer className="border-line-subtle flex items-center justify-end border-t px-4 py-3">
                        <div className="text-right">
                            <p className="text-content-muted text-2xs uppercase">
                                Before tax, per invoice
                            </p>
                            <p className="text-content text-lg font-semibold tabular-nums">
                                {formatMoney(subtotal, { currency })}
                            </p>
                        </div>
                    </footer>
                </Card>

                <div className="flex items-center justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="md"
                        onClick={() => router.get('/sales/recurring-invoices')}
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
                        {isEdit ? 'Save changes' : 'Create the schedule'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}

/**
 * One line's value after its own discount. Display only — the tax engine
 * recomputes it on each invoice, at that invoice's date.
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

function errorFor(
    errors: Record<string, string | undefined>,
    index: number,
    field: string,
): string | undefined {
    return errors[`lines.${index}.${field}`];
}
