import { useMemo } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Car, Plus, Save, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { formatMoney, isPositiveAmount, multiplyForDisplay, sumForDisplay } from '@/Utils/money';

interface Option {
    value: string;
    label: string;
}

interface MileageRateOption extends Option {
    unit: string;
    rate: string;
}

interface LineDraft {
    kind: string;
    description: string;
    quantity: string;
    distance: string;
    unit_price: string;
    unit: string;
    tax_id: string;
    debit_account_id: string;
    tax_is_claimable: boolean;
}

interface EditProps {
    expense: (Record<string, unknown> & { number: string; lines: LineDraft[] }) | null;
    contacts: Option[];
    customers: Option[];
    people: Option[];
    accounts: (Option & { type: string })[];
    bankAccounts: Option[];
    taxes: (Option & { code: string })[];
    mileageRates: MileageRateOption[];
    baseCurrency: string;
    today: string;
}

const EMPTY_LINE: LineDraft = {
    kind: 'amount',
    description: '',
    quantity: '1',
    distance: '',
    unit_price: '',
    unit: 'km',
    tax_id: '',
    debit_account_id: '',
    // Claimable by default, which is the common case. The other way round
    // would quietly capitalise recoverable tax into costs.
    tax_is_claimable: true,
};

/**
 * The expense form.
 *
 * Two things drive its shape, and neither has a counterpart on the invoice or
 * bill screens:
 *
 * WHOSE MONEY. Company or reimbursable, chosen first, because it decides
 * whether the next field is a bank account or a person — and getting it wrong
 * credits the wrong thing.
 *
 * WHAT KIND OF LINE. A sum spent, or a distance travelled. Mileage swaps the
 * quantity and price boxes for a distance and a rate, which is the same
 * multiplication underneath — so the totals fall out unchanged and the rate
 * shown is the one in force on the expense's own date.
 *
 * @see ACCOUNTING_RULES.md §4.8, §5
 */
export default function ExpenseEdit({
    expense,
    contacts,
    customers,
    people,
    accounts,
    bankAccounts,
    taxes,
    mileageRates,
    baseCurrency,
    today,
}: EditProps) {
    const isEdit = expense !== null;

    const form = useForm<{
        contact_id: string;
        merchant: string;
        expense_date: string;
        payment_mode: string;
        paid_through_account_id: string;
        reimburse_user_id: string;
        reference: string;
        notes: string;
        prices_include_tax: boolean;
        is_billable: boolean;
        billable_contact_id: string;
        lines: LineDraft[];
    }>({
        contact_id: asText(expense?.contact_id),
        merchant: asText(expense?.merchant),
        expense_date: asText(expense?.expense_date) || today,
        payment_mode: asText(expense?.payment_mode) || 'company',
        paid_through_account_id:
            asText(expense?.paid_through_account_id) || (bankAccounts[0]?.value ?? ''),
        reimburse_user_id: asText(expense?.reimburse_user_id),
        reference: asText(expense?.reference),
        notes: asText(expense?.notes),
        prices_include_tax: expense?.prices_include_tax === true,
        is_billable: expense?.is_billable === true,
        billable_contact_id: asText(expense?.billable_contact_id),
        lines:
            expense !== null && expense.lines.length > 0
                ? expense.lines.map((line) => ({
                      kind: asText(line.kind) || 'amount',
                      description: asText(line.description),
                      quantity: asText(line.quantity) || '1',
                      // On a stored mileage line the distance lives in
                      // `quantity`; the form shows it in its own box.
                      distance: line.kind === 'mileage' ? asText(line.quantity) : '',
                      unit_price: asText(line.unit_price),
                      unit: asText(line.unit) || 'km',
                      tax_id: asText(line.tax_id),
                      debit_account_id: asText(line.debit_account_id),
                      tax_is_claimable: line.tax_is_claimable,
                  }))
                : [{ ...EMPTY_LINE }],
    });

    const reimbursable = form.data.payment_mode === 'reimbursable';

    const total = useMemo(() => sumForDisplay(form.data.lines.map(lineNet)), [form.data.lines]);

    const setLine = (index: number, patch: Partial<LineDraft>) => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
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

    /**
     * Turning a line into a mileage claim fills the rate from the current
     * one, so the figure is visible before saving. The server resolves it
     * again as at the expense's date, which is what actually gets stored.
     */
    const setKind = (index: number, kind: string) => {
        if (kind !== 'mileage') {
            setLine(index, { kind, unit_price: '', distance: '' });

            return;
        }

        const rate = mileageRates.find((candidate) => candidate.unit === 'km') ?? mileageRates[0];

        setLine(index, {
            kind,
            unit: rate?.unit ?? 'km',
            unit_price: rate?.rate ?? '',
        });
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/expenses/${expense.number}`);

            return;
        }

        form.post('/expenses');
    };

    const complete =
        form.data.lines.every(
            (line) => line.description.trim() !== '' && isPositiveAmount(lineNet(line)),
        ) &&
        (reimbursable
            ? form.data.reimburse_user_id !== ''
            : form.data.paid_through_account_id !== '');

    return (
        <AppLayout
            title={isEdit ? `Edit ${expense.number}` : 'Record an expense'}
            description="Saved as a draft. Nothing reaches the ledger until somebody else approves it."
            breadcrumbs={[
                { label: 'Expenses', href: '/expenses' },
                { label: isEdit ? expense.number : 'New' },
            ]}
        >
            <Head title={isEdit ? `Edit ${expense.number}` : 'Record an expense'} />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Card>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {/*
                         * First, because it decides what the next field is.
                         */}
                        <Select
                            label="Whose money"
                            name="payment_mode"
                            value={form.data.payment_mode}
                            onChange={(e) => form.setData('payment_mode', e.target.value)}
                            error={form.errors.payment_mode}
                            options={[
                                { value: 'company', label: 'Paid by the company' },
                                { value: 'reimbursable', label: 'To be reimbursed' },
                            ]}
                            required
                        />

                        {reimbursable ? (
                            <Select
                                label="Reimburse"
                                name="reimburse_user_id"
                                value={form.data.reimburse_user_id}
                                onChange={(e) => form.setData('reimburse_user_id', e.target.value)}
                                error={form.errors.reimburse_user_id}
                                options={[{ value: '', label: 'Choose a person' }, ...people]}
                                hint="They are owed this until it is paid back."
                                required
                            />
                        ) : (
                            <Select
                                label="Paid from"
                                name="paid_through_account_id"
                                value={form.data.paid_through_account_id}
                                onChange={(e) =>
                                    form.setData('paid_through_account_id', e.target.value)
                                }
                                error={form.errors.paid_through_account_id}
                                options={bankAccounts}
                                hint="Bank and cash accounts only."
                                required
                            />
                        )}

                        <Input
                            label="Date"
                            name="expense_date"
                            type="date"
                            value={form.data.expense_date}
                            onChange={(e) => form.setData('expense_date', e.target.value)}
                            error={form.errors.expense_date}
                            hint="Decides which tax and mileage rates apply."
                            required
                        />

                        <Input
                            label="Merchant"
                            name="merchant"
                            value={form.data.merchant}
                            onChange={(e) => form.setData('merchant', e.target.value)}
                            error={form.errors.merchant}
                            placeholder="Careem, Metro, the hotel"
                            hint="Goes in the ledger memo — often the only record of who was paid."
                        />

                        <Select
                            label="Contact"
                            name="contact_id"
                            value={form.data.contact_id}
                            onChange={(e) => form.setData('contact_id', e.target.value)}
                            error={form.errors.contact_id}
                            options={[
                                { value: '', label: 'Not a contact we keep records for' },
                                ...contacts,
                            ]}
                        />

                        <Input
                            label="Reference"
                            name="reference"
                            value={form.data.reference}
                            onChange={(e) => form.setData('reference', e.target.value)}
                            error={form.errors.reference}
                            placeholder="A job, a trip, a cost centre"
                            optional
                        />
                    </div>

                    <div className="mt-4 flex flex-col gap-3">
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
                            <span>
                                Amounts include tax
                                <span className="text-content-muted block text-xs">
                                    Usually true of a receipt: the net is extracted rather than tax
                                    added, so the total equals what is on the paper.
                                </span>
                            </span>
                        </label>

                        <label className="text-content-secondary flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_billable"
                                checked={form.data.is_billable}
                                onChange={(e) => form.setData('is_billable', e.target.checked)}
                                className="border-line accent-brand mt-0.5 size-4 rounded"
                            />
                            <span>
                                Rebill this to a customer
                                <span className="text-content-muted block text-xs">
                                    It appears on the to-rebill list once approved, and goes onto a
                                    draft invoice at cost.
                                </span>
                            </span>
                        </label>

                        {form.data.is_billable && (
                            <Select
                                label="Rebill to"
                                name="billable_contact_id"
                                value={form.data.billable_contact_id}
                                onChange={(e) =>
                                    form.setData('billable_contact_id', e.target.value)
                                }
                                error={form.errors.billable_contact_id}
                                options={[{ value: '', label: 'Choose a customer' }, ...customers]}
                                containerClassName="max-w-sm"
                                required
                            />
                        )}
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle flex items-center justify-between border-b px-4 py-3">
                        <div>
                            <h2 className="text-content text-md font-semibold">What was spent</h2>
                            <p className="text-content-muted text-xs">
                                Split a receipt across categories by adding lines — a hotel bill is
                                usually two.
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

                    <div className="flex flex-col">
                        {form.data.lines.map((line, index) => (
                            <div
                                key={index}
                                className="border-line-subtle grid gap-3 border-b p-4 last:border-b-0 sm:grid-cols-2 lg:grid-cols-[10rem_1fr_14rem_10rem_12rem_2rem]"
                            >
                                <Select
                                    aria-label={`Kind of line ${index + 1}`}
                                    name={`lines.${index}.kind`}
                                    value={line.kind}
                                    onChange={(e) => setKind(index, e.target.value)}
                                    options={[
                                        { value: 'amount', label: 'An amount' },
                                        { value: 'mileage', label: 'Mileage' },
                                    ]}
                                />

                                <Input
                                    aria-label={`Description for line ${index + 1}`}
                                    name={`lines.${index}.description`}
                                    value={line.description}
                                    onChange={(e) =>
                                        setLine(index, { description: e.target.value })
                                    }
                                    error={errorFor(form.errors, index, 'description')}
                                    placeholder={
                                        line.kind === 'mileage'
                                            ? 'Where to and back'
                                            : 'What it was for'
                                    }
                                    required
                                />

                                <Select
                                    aria-label={`Charge line ${index + 1} to`}
                                    name={`lines.${index}.debit_account_id`}
                                    value={line.debit_account_id}
                                    onChange={(e) =>
                                        setLine(index, { debit_account_id: e.target.value })
                                    }
                                    error={errorFor(form.errors, index, 'debit_account_id')}
                                    options={[{ value: '', label: 'Default expense' }, ...accounts]}
                                />

                                {line.kind === 'mileage' ? (
                                    <div className="flex items-end gap-1">
                                        <Input
                                            aria-label={`Distance for line ${index + 1}`}
                                            name={`lines.${index}.distance`}
                                            inputMode="decimal"
                                            numeric
                                            value={line.distance}
                                            onChange={(e) =>
                                                setLine(index, { distance: e.target.value })
                                            }
                                            error={errorFor(form.errors, index, 'distance')}
                                            prefix={
                                                <Car
                                                    className="text-content-muted size-3.5"
                                                    aria-hidden="true"
                                                />
                                            }
                                            containerClassName="min-w-0 flex-1"
                                        />
                                        <Select
                                            aria-label={`Distance unit for line ${index + 1}`}
                                            name={`lines.${index}.unit`}
                                            value={line.unit}
                                            onChange={(e) =>
                                                setLine(index, { unit: e.target.value })
                                            }
                                            options={[
                                                { value: 'km', label: 'km' },
                                                { value: 'mi', label: 'mi' },
                                            ]}
                                            containerClassName="w-20 shrink-0"
                                        />
                                    </div>
                                ) : (
                                    <Input
                                        aria-label={`Amount for line ${index + 1}`}
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
                                )}

                                <div className="flex flex-col gap-1.5">
                                    <Select
                                        aria-label={`Tax for line ${index + 1}`}
                                        name={`lines.${index}.tax_id`}
                                        value={line.tax_id}
                                        onChange={(e) => setLine(index, { tax_id: e.target.value })}
                                        options={[{ value: '', label: 'No tax' }, ...taxes]}
                                    />

                                    {/*
                                     * Only where there is tax to reclaim. On
                                     * the line, because claimability is a
                                     * fact about what was bought — and on
                                     * expenses the blocked case is common.
                                     */}
                                    {line.tax_id !== '' && (
                                        <label className="text-content-muted flex items-start gap-1.5 text-xs">
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
                                </div>

                                <div className="flex items-start justify-end pt-1">
                                    <button
                                        type="button"
                                        onClick={() => removeLine(index)}
                                        aria-label={`Remove line ${index + 1}`}
                                        className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 rounded p-1.5 transition-colors"
                                    >
                                        <Trash2 className="size-3.5" aria-hidden="true" />
                                    </button>
                                </div>

                                {line.kind === 'mileage' && (
                                    <p className="text-content-muted text-xs lg:col-span-6">
                                        {line.unit_price === ''
                                            ? 'No mileage rate is set. Add one under settings before claiming mileage.'
                                            : `${line.distance === '' ? '0' : line.distance} ${line.unit} at ${line.unit_price} per ${line.unit}. The rate in force on the expense's date is the one that gets stored.`}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>

                    <footer className="border-line-subtle grid gap-4 border-t px-4 py-3 lg:grid-cols-[1fr_16rem]">
                        <Input
                            label="Notes"
                            name="notes"
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            error={form.errors.notes}
                            placeholder="Anything the approver needs to know"
                            optional
                        />

                        <div className="flex flex-col items-end justify-end">
                            <p className="text-content-muted text-2xs uppercase">
                                {form.data.prices_include_tax ? 'Total' : 'Before tax'}
                            </p>
                            <p className="text-content text-lg font-semibold tabular-nums">
                                {formatMoney(total, { currency: baseCurrency })}
                            </p>
                            <p className="text-content-muted text-xs">
                                Tax is computed by the server and shown once saved.
                            </p>
                        </div>
                    </footer>
                </Card>

                <div className="flex items-center justify-end gap-2">
                    <Button variant="ghost" size="md" onClick={() => router.get('/expenses')}>
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

/**
 * One line's value.
 *
 * Display only, and the same multiplication either way: a distance at a rate
 * per kilometre is a quantity at a price. The server recomputes it — and the
 * tax on it — at the precision §5 demands.
 */
function lineNet(line: LineDraft): string {
    const quantity = line.kind === 'mileage' ? line.distance : line.quantity;

    if (!isPositiveAmount(quantity === '' ? '0' : quantity) || line.unit_price === '') {
        return '0.0000';
    }

    return multiplyForDisplay(quantity, line.unit_price);
}

function asText(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function errorFor(
    errors: Record<string, string | undefined>,
    index: number,
    field: string,
): string | undefined {
    return errors[`lines.${index}.${field}`];
}
