import { useMemo, useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Info, Wallet } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import {
    formatMoney,
    isPositiveAmount,
    isZero,
    moneySign,
    multiplyForDisplay,
    subtractForDisplay,
    sumForDisplay,
} from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Option {
    value: string;
    label: string;
}

interface OutstandingBill {
    id: string;
    number: string;
    vendor_reference: string | null;
    issue_date: string;
    due_date: string | null;
    currency: string;
    total: string;
    balance_due: string;
    is_overdue: boolean;
    days_overdue: number;
}

interface CreateProps {
    vendors: (Option & { currency: string | null })[];
    bankAccounts: (Option & { currency: string | null })[];
    withholdingTaxes: (Option & { rate: string | null })[];
    preselected: { contact_id: string | null; bill_id: string | null; amount: string | null };
    outstanding: OutstandingBill[];
    baseCurrency: string;
    today: string;
}

/**
 * Record money paid to a vendor.
 *
 * Built around allocation, not around one bill: one transfer settling four
 * bills is the normal case, and so is a payment on account.
 *
 * The withholding section is the same shape as the sales side and means the
 * opposite thing — we deduct the tax and owe it onward, rather than having it
 * deducted from us. The wording says so, because getting the direction wrong
 * is the kind of mistake that surfaces only when a return is filed.
 *
 * @see ACCOUNTING_RULES.md §4.7
 */
export default function RecordVendorPayment({
    vendors,
    bankAccounts,
    withholdingTaxes,
    preselected,
    outstanding,
    baseCurrency,
    today,
}: CreateProps) {
    const [bills, setBills] = useState<OutstandingBill[]>(outstanding);
    const [loadingBills, setLoadingBills] = useState(false);

    const form = useForm<{
        contact_id: string;
        bank_account_id: string;
        payment_date: string;
        amount: string;
        withholding_amount: string;
        withholding_tax_id: string;
        method: string;
        reference: string;
        notes: string;
        allocations: { document_id: string; amount: string }[];
    }>({
        contact_id: preselected.contact_id ?? '',
        bank_account_id: bankAccounts[0]?.value ?? '',
        payment_date: today,
        amount: preselected.amount ?? '',
        withholding_amount: '',
        withholding_tax_id: '',
        method: 'bank_transfer',
        reference: '',
        notes: '',
        allocations:
            preselected.bill_id === null
                ? []
                : [
                      {
                          document_id: preselected.bill_id,
                          amount: preselected.amount ?? '',
                      },
                  ],
    });

    /**
     * Choosing a vendor reloads what we owe them.
     *
     * Fetched rather than reloading the page, so what has already been typed
     * survives — the amount and reference usually come from a bank statement
     * the user is reading alongside.
     */
    const chooseVendor = async (contactId: string) => {
        form.setData((data) => ({ ...data, contact_id: contactId, allocations: [] }));

        if (contactId === '') {
            setBills([]);

            return;
        }

        setLoadingBills(true);

        try {
            const response = await fetch(`/purchases/payments/outstanding/${contactId}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                setBills([]);

                return;
            }

            // `json()` resolves to `any`, so the shape is checked rather than
            // asserted. A malformed response leaves the table empty, which is
            // a valid state — a payment on account allocates to nothing.
            const body: unknown = await response.json();

            setBills(
                typeof body === 'object' &&
                    body !== null &&
                    'outstanding' in body &&
                    Array.isArray(body.outstanding)
                    ? (body.outstanding as OutstandingBill[])
                    : [],
            );
        } catch {
            setBills([]);
        } finally {
            setLoadingBills(false);
        }
    };

    const allocationFor = (billId: string): string =>
        form.data.allocations.find((a) => a.document_id === billId)?.amount ?? '';

    const setAllocation = (billId: string, amount: string) => {
        const others = form.data.allocations.filter((a) => a.document_id !== billId);

        form.setData(
            'allocations',
            amount === '' ? others : [...others, { document_id: billId, amount }],
        );
    };

    /**
     * Fill every row from the one due soonest, until the payment runs out.
     *
     * Due-soonest rather than oldest, which is the opposite of the receipts
     * form. The bills arrive from the server ordered by due date, and paying
     * in that order is what avoids a late-payment charge — whereas on the
     * sales side the oldest debt is the one least likely to be collected.
     */
    const applyDueSoonestFirst = () => {
        let remaining = form.data.amount === '' ? '0' : form.data.amount;
        const allocations: { document_id: string; amount: string }[] = [];

        for (const bill of bills) {
            if (moneySign(remaining) <= 0) {
                break;
            }

            const take =
                moneySign(subtractForDisplay(remaining, bill.balance_due)) >= 0
                    ? bill.balance_due
                    : remaining;

            allocations.push({ document_id: bill.id, amount: take });
            remaining = subtractForDisplay(remaining, take);
        }

        form.setData('allocations', allocations);
    };

    const allocated = useMemo(
        () => sumForDisplay(form.data.allocations.map((a) => a.amount)),
        [form.data.allocations],
    );

    const unallocated = useMemo(
        () => subtractForDisplay(form.data.amount === '' ? '0' : form.data.amount, allocated),
        [form.data.amount, allocated],
    );

    const leavesBank = useMemo(
        () =>
            subtractForDisplay(
                form.data.amount === '' ? '0' : form.data.amount,
                form.data.withholding_amount === '' ? '0' : form.data.withholding_amount,
            ),
        [form.data.amount, form.data.withholding_amount],
    );

    /** Applying a withholding tax computes the deduction from the amount. */
    const chooseWithholdingTax = (taxId: string) => {
        const tax = withholdingTaxes.find((candidate) => candidate.value === taxId);

        form.setData((data) => ({
            ...data,
            withholding_tax_id: taxId,
            withholding_amount:
                tax?.rate == null || !isPositiveAmount(data.amount)
                    ? data.withholding_amount
                    : multiplyForDisplay(data.amount, tax.rate),
        }));
    };

    const currency = useMemo(() => {
        const vendor = vendors.find((v) => v.value === form.data.contact_id);

        return vendor?.currency ?? baseCurrency;
    }, [vendors, form.data.contact_id, baseCurrency]);

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/purchases/payments');
    };

    const overAllocated = moneySign(unallocated) < 0;

    return (
        <AppLayout
            title="Record a payment"
            description="Money paid to a vendor, and what it settles."
            breadcrumbs={[
                { label: 'Purchases' },
                { label: 'Payments', href: '/purchases/payments' },
                { label: 'New' },
            ]}
        >
            <Head title="Record a payment" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Card>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Select
                            label="Vendor"
                            name="contact_id"
                            value={form.data.contact_id}
                            onChange={(e) => void chooseVendor(e.target.value)}
                            error={form.errors.contact_id}
                            options={[{ value: '', label: 'Choose a vendor' }, ...vendors]}
                            required
                        />

                        <Input
                            label="Date paid"
                            name="payment_date"
                            type="date"
                            value={form.data.payment_date}
                            onChange={(e) => form.setData('payment_date', e.target.value)}
                            error={form.errors.payment_date}
                            required
                        />

                        <Select
                            label="From"
                            name="bank_account_id"
                            value={form.data.bank_account_id}
                            onChange={(e) => form.setData('bank_account_id', e.target.value)}
                            error={form.errors.bank_account_id}
                            options={bankAccounts}
                            hint="Bank and cash accounts only."
                            required
                        />

                        <Input
                            label="Amount settled"
                            name="amount"
                            inputMode="decimal"
                            numeric
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                            error={form.errors.amount}
                            prefix={<span className="text-content-muted text-2xs">{currency}</span>}
                            hint="What the vendor's balance is reduced by, before any withholding."
                            required
                        />

                        <Select
                            label="Method"
                            name="method"
                            value={form.data.method}
                            onChange={(e) => form.setData('method', e.target.value)}
                            error={form.errors.method}
                            options={[
                                { value: 'bank_transfer', label: 'Bank transfer' },
                                { value: 'cheque', label: 'Cheque' },
                                { value: 'cash', label: 'Cash' },
                                { value: 'card', label: 'Card' },
                                { value: 'online', label: 'Online' },
                                { value: 'other', label: 'Other' },
                            ]}
                        />

                        <Input
                            label="Reference"
                            name="reference"
                            value={form.data.reference}
                            onChange={(e) => form.setData('reference', e.target.value)}
                            error={form.errors.reference}
                            placeholder="Cheque number, transfer id"
                            optional
                        />
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">Withholding</h2>
                        <p className="text-content-muted text-xs">
                            Tax you deduct from this payment and pay to the authority instead of the
                            vendor. Their bill is still settled in full — the withheld amount
                            becomes a liability you owe the authority, not a saving.
                        </p>
                    </header>

                    <div className="grid gap-4 p-4 sm:grid-cols-3">
                        <Select
                            label="Withholding tax"
                            name="withholding_tax_id"
                            value={form.data.withholding_tax_id}
                            onChange={(e) => chooseWithholdingTax(e.target.value)}
                            error={form.errors.withholding_tax_id}
                            options={[{ value: '', label: 'None' }, ...withholdingTaxes]}
                        />

                        <Input
                            label="Amount withheld"
                            name="withholding_amount"
                            inputMode="decimal"
                            numeric
                            value={form.data.withholding_amount}
                            onChange={(e) => form.setData('withholding_amount', e.target.value)}
                            error={form.errors.withholding_amount}
                            optional
                        />

                        <div className="flex flex-col justify-end">
                            <p className="text-content-muted text-2xs uppercase">
                                Leaving the bank
                            </p>
                            <p className="text-content text-md font-medium tabular-nums">
                                {formatMoney(leavesBank, { currency })}
                            </p>
                        </div>
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                        <div>
                            <h2 className="text-content text-md font-semibold">Apply to</h2>
                            <p className="text-content-muted text-xs">
                                Anything left unapplied is held as an advance with the vendor — an
                                asset, until it is allocated.
                            </p>
                        </div>

                        {bills.length > 0 && (
                            <Button variant="secondary" size="sm" onClick={applyDueSoonestFirst}>
                                Due soonest first
                            </Button>
                        )}
                    </header>

                    {typeof form.errors.allocations === 'string' && (
                        <p
                            role="alert"
                            className="border-line-danger bg-status-danger text-status-danger-fg flex items-start gap-2 border-b px-4 py-2.5 text-sm"
                        >
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            {form.errors.allocations}
                        </p>
                    )}

                    {loadingBills ? (
                        <p className="text-content-muted px-4 py-6 text-center text-sm">
                            Looking up what you owe them…
                        </p>
                    ) : bills.length === 0 ? (
                        <EmptyState
                            icon={Info}
                            title={form.data.contact_id === '' ? 'Choose a vendor' : 'Nothing owed'}
                            description={
                                form.data.contact_id === ''
                                    ? 'Their unpaid bills appear here once you pick one.'
                                    : 'You owe them nothing, so this payment will be held as an advance against future bills.'
                            }
                        />
                    ) : (
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">Bill</th>
                                        <th className="px-4 py-2 text-left font-medium">Due</th>
                                        <th className="px-4 py-2 text-right font-medium">Owed</th>
                                        <th className="w-40 px-4 py-2 text-right font-medium">
                                            Apply
                                        </th>
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {bills.map((bill) => (
                                        <tr key={bill.id} className="align-top">
                                            <td className="px-4 py-2">
                                                <span className="text-content font-medium tabular-nums">
                                                    {bill.number}
                                                </span>
                                                {bill.vendor_reference !== null && (
                                                    <span className="text-content-muted block text-xs">
                                                        their {bill.vendor_reference}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-4 py-2 tabular-nums">
                                                <span
                                                    className={
                                                        bill.is_overdue
                                                            ? 'text-danger-600 dark:text-danger-400'
                                                            : 'text-content-secondary'
                                                    }
                                                >
                                                    {bill.due_date ?? '—'}
                                                </span>
                                                {bill.is_overdue && (
                                                    <Badge tone="danger" className="ml-2">
                                                        {bill.days_overdue}d
                                                    </Badge>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2 text-right tabular-nums">
                                                {formatMoney(bill.balance_due, {
                                                    currency: bill.currency,
                                                    showCurrency: false,
                                                })}
                                            </td>

                                            <td className="px-4 py-2">
                                                <Input
                                                    aria-label={`Apply to ${bill.number}`}
                                                    name={`allocation-${bill.id}`}
                                                    inputMode="decimal"
                                                    numeric
                                                    value={allocationFor(bill.id)}
                                                    onChange={(e) =>
                                                        setAllocation(bill.id, e.target.value)
                                                    }
                                                    placeholder="0.00"
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>

                                <tfoot>
                                    <tr
                                        className={cn(
                                            'border-line-subtle border-t-2 font-medium',
                                            overAllocated
                                                ? 'bg-status-danger'
                                                : 'bg-surface-sunken',
                                        )}
                                    >
                                        <td className="px-4 py-2.5" colSpan={2}>
                                            <span
                                                className={
                                                    overAllocated
                                                        ? 'text-status-danger-fg'
                                                        : 'text-content'
                                                }
                                            >
                                                {overAllocated
                                                    ? 'More applied than paid'
                                                    : isZero(unallocated)
                                                      ? 'Fully applied'
                                                      : 'Held on account'}
                                            </span>
                                        </td>

                                        <td className="text-content-muted px-4 py-2.5 text-right text-xs">
                                            Applied
                                        </td>

                                        <td className="px-4 py-2.5 text-right tabular-nums">
                                            {formatMoney(allocated, {
                                                currency,
                                                showCurrency: false,
                                            })}
                                            {!isZero(unallocated) && (
                                                <span
                                                    className={cn(
                                                        'block text-xs',
                                                        overAllocated
                                                            ? 'text-danger-600 dark:text-danger-400'
                                                            : 'text-content-muted',
                                                    )}
                                                >
                                                    {formatMoney(unallocated, {
                                                        currency,
                                                        showCurrency: false,
                                                        signDisplay: 'always',
                                                    })}
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    )}
                </Card>

                <div className="flex items-center justify-end gap-2">
                    <Button
                        variant="ghost"
                        size="md"
                        onClick={() => router.get('/purchases/payments')}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={form.processing}
                        disabled={
                            form.data.contact_id === '' ||
                            !isPositiveAmount(form.data.amount) ||
                            overAllocated
                        }
                        icon={<Wallet aria-hidden="true" />}
                    >
                        Record the payment
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
