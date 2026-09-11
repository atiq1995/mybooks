import { useMemo, useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Plus, Save, Scale, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import {
    absForDisplay,
    formatMoney,
    isZero,
    subtractForDisplay,
    sumForDisplay,
} from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Option {
    value: string;
    label: string;
}

interface AccountOption extends Option {
    type: string;
    normal_balance: string;
}

interface ContactOption extends Option {
    terms: number;
}

interface OpeningDocument {
    id: string;
    number: string;
    contact_name: string | null;
    vendor_reference?: string | null;
    issue_date: string;
    due_date: string | null;
    total: string;
    balance_due: string;
    is_overdue: boolean;
    days_overdue: number;
}

interface OpeningBalancesProps {
    equity: { balance: string; account: string | null; is_settled: boolean };
    accounts: AccountOption[];
    customers: ContactOption[];
    vendors: ContactOption[];
    invoices: OpeningDocument[];
    bills: OpeningDocument[];
    entered: { accounts: boolean; invoices: number; bills: number };
    openingDate: string;
    baseCurrency: string;
    can: { enter: boolean };
}

interface BalanceRow {
    account_id: string;
    debit: string;
    credit: string;
}

const EMPTY_ROW: BalanceRow = { account_id: '', debit: '', credit: '' };

/**
 * Bringing balances forward from a previous system.
 *
 * The banner is the screen. Opening balance equity is a plug on purpose — it
 * holds the difference while a migration is half done, so every intermediate
 * state balances — and when everything is across it is exactly what the
 * business was worth on the day it moved. So the page reports the figure,
 * says what it should be, and never pretends a partial migration is finished.
 *
 * Three sections in the order they have to happen. Receivables and payables
 * come across as DOCUMENTS rather than as a figure in the control account,
 * because a lump cannot be aged, chased, or reconciled to anybody — and the
 * first thing a migrated business wants is a chasing list that matches the
 * one they had last week.
 *
 * @see ACCOUNTING_RULES.md §4.13
 */
export default function OpeningBalances({
    equity,
    accounts,
    customers,
    vendors,
    invoices,
    bills,
    entered,
    openingDate,
    baseCurrency,
    can,
}: OpeningBalancesProps) {
    return (
        <AppLayout
            title="Opening balances"
            description={`Where the business stood when it moved into these books. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Accounting' }, { label: 'Opening balances' }]}
        >
            <Head title="Opening balances" />

            <EquityBanner equity={equity} entered={entered} currency={baseCurrency} />

            {!entered.accounts && can.enter && (
                <BalancesForm
                    accounts={accounts}
                    openingDate={openingDate}
                    currency={baseCurrency}
                />
            )}

            {entered.accounts && (
                <Card className="mb-4">
                    <p className="text-content-secondary text-sm">
                        <span className="text-content font-medium">Account balances are in.</span>{' '}
                        They were posted as one entry — the opening position is a single event,
                        either right or wrong as a whole. A correction is a journal like any other,
                        not a second opening entry.
                    </p>
                </Card>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <DocumentSection
                    kind="invoice"
                    title="Unpaid invoices"
                    blurb="What customers still owed you. Each becomes a real invoice that ages and can be chased — a single figure in the receivables account could do neither."
                    contacts={customers}
                    documents={invoices}
                    openingDate={openingDate}
                    currency={baseCurrency}
                    canEnter={can.enter}
                />

                <DocumentSection
                    kind="bill"
                    title="Unpaid bills"
                    blurb="What you still owed vendors. Each becomes a real bill, so the payables ageing is right on day one."
                    contacts={vendors}
                    documents={bills}
                    openingDate={openingDate}
                    currency={baseCurrency}
                    canEnter={can.enter}
                />
            </div>
        </AppLayout>
    );
}

/**
 * What is left in opening balance equity, and what that means.
 *
 * Three states rather than two: nothing entered yet, part way through, and
 * done. The middle one is where people spend their time, and the number there
 * is not an error — it is the position not yet fully described.
 */
function EquityBanner({
    equity,
    entered,
    currency,
}: {
    equity: OpeningBalancesProps['equity'];
    entered: OpeningBalancesProps['entered'];
    currency: string;
}) {
    const nothingYet = !entered.accounts && entered.invoices === 0 && entered.bills === 0;

    if (nothingYet) {
        return (
            <Card className="mb-4">
                <p className="text-content text-sm font-medium">Nothing brought across yet.</p>
                <p className="text-content-secondary text-sm">
                    Start with the account balances — the bank, the stock, the loans — then add the
                    unpaid invoices and bills. Each step balances on its own, so you can stop and
                    come back.
                </p>
            </Card>
        );
    }

    return (
        <div
            className={cn(
                'mb-4 flex flex-wrap items-center gap-3 rounded-md border px-4 py-3',
                equity.is_settled
                    ? 'border-status-success-line bg-status-success text-status-success-fg'
                    : 'border-line-brand bg-surface-sunken text-content',
            )}
        >
            {equity.is_settled ? (
                <CheckCircle2 className="size-5 shrink-0" aria-hidden="true" />
            ) : (
                <Scale className="size-5 shrink-0" aria-hidden="true" />
            )}

            <div className="min-w-0">
                <p className="text-sm font-semibold">
                    {equity.is_settled
                        ? 'Opening balance equity is zero.'
                        : `${formatMoney(absForDisplay(equity.balance), { currency })} sits in opening balance equity.`}
                </p>
                <p className="text-xs opacity-90">
                    {equity.is_settled
                        ? 'Everything brought across is accounted for — the assets, liabilities, invoices and bills describe one position that balances.'
                        : 'That figure is what the business was worth on the opening date, once everything is across. While the migration is part way through it holds the difference, which is exactly what the account is for — so it is not an error, but it should stop changing when you are finished.'}
                </p>
            </div>
        </div>
    );
}

/**
 * The account balances, entered once.
 *
 * The figure defaults into the column the account normally sits in, which is
 * right far more often than not — and the running difference below shows what
 * will land in equity before anything is posted, so a mistyped figure is
 * visible rather than discovered afterwards.
 */
function BalancesForm({
    accounts,
    openingDate,
    currency,
}: {
    accounts: AccountOption[];
    openingDate: string;
    currency: string;
}) {
    const form = useForm<{ as_of: string; balances: BalanceRow[] }>({
        as_of: openingDate,
        balances: [{ ...EMPTY_ROW }],
    });

    const totals = useMemo(() => {
        const debits = sumForDisplay(form.data.balances.map((row) => row.debit));
        const credits = sumForDisplay(form.data.balances.map((row) => row.credit));

        return { debits, credits, difference: subtractForDisplay(debits, credits) };
    }, [form.data.balances]);

    const setRow = (index: number, patch: Partial<BalanceRow>) => {
        form.setData(
            'balances',
            form.data.balances.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );
    };

    /**
     * Choosing an account puts the cursor in the column it belongs in.
     *
     * A bank balance is a debit and a loan is a credit, nearly always — so
     * defaulting saves a decision, and the other column stays available for
     * the overdrawn account or the prepaid supplier.
     */
    const chooseAccount = (index: number, accountId: string) => {
        setRow(index, { account_id: accountId });
    };

    const addRow = () => form.setData('balances', [...form.data.balances, { ...EMPTY_ROW }]);

    const removeRow = (index: number) => {
        if (form.data.balances.length <= 1) {
            form.setData('balances', [{ ...EMPTY_ROW }]);

            return;
        }

        form.setData(
            'balances',
            form.data.balances.filter((_, i) => i !== index),
        );
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/accounting/opening-balances/accounts');
    };

    const complete = form.data.balances.some(
        (row) => row.account_id !== '' && (row.debit !== '' || row.credit !== ''),
    );

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Account balances</h2>
                    <p className="text-content-muted text-xs">
                        The bank, the stock, the equipment, the loans. Not receivables or payables —
                        those come across as invoices and bills below, so they can be aged.
                    </p>
                </header>

                {typeof form.errors.balances === 'string' && (
                    <p
                        role="alert"
                        className="border-line-danger bg-status-danger text-status-danger-fg flex items-start gap-2 border-b px-4 py-2.5 text-sm"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        {form.errors.balances}
                    </p>
                )}

                <div className="grid gap-4 px-4 py-3 sm:grid-cols-[14rem_1fr]">
                    <Input
                        label="As at"
                        name="as_of"
                        type="date"
                        value={form.data.as_of}
                        onChange={(e) => form.setData('as_of', e.target.value)}
                        error={form.errors.as_of}
                        hint="The first day these books cover."
                        required
                    />
                </div>

                <div className="table-scroll">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                <th className="px-4 py-2 text-left font-medium">Account</th>
                                <th className="w-40 px-4 py-2 text-right font-medium">Debit</th>
                                <th className="w-40 px-4 py-2 text-right font-medium">Credit</th>
                                <th className="w-10 px-4 py-2" />
                            </tr>
                        </thead>

                        <tbody className="divide-line-subtle divide-y">
                            {form.data.balances.map((row, index) => {
                                const account = accounts.find((a) => a.value === row.account_id);

                                return (
                                    <tr key={index} className="align-top">
                                        <td className="px-4 py-2">
                                            <Select
                                                aria-label={`Account for row ${index + 1}`}
                                                name={`balances.${index}.account_id`}
                                                value={row.account_id}
                                                onChange={(e) =>
                                                    chooseAccount(index, e.target.value)
                                                }
                                                options={[
                                                    { value: '', label: 'Choose an account' },
                                                    ...accounts,
                                                ]}
                                            />
                                        </td>

                                        <td className="px-4 py-2">
                                            <Input
                                                aria-label={`Debit for row ${index + 1}`}
                                                name={`balances.${index}.debit`}
                                                inputMode="decimal"
                                                numeric
                                                value={row.debit}
                                                onChange={(e) =>
                                                    setRow(index, {
                                                        debit: e.target.value,
                                                        // A balance is one or
                                                        // the other, and the
                                                        // action refuses both.
                                                        credit:
                                                            e.target.value === '' ? row.credit : '',
                                                    })
                                                }
                                                placeholder={
                                                    account?.normal_balance === 'debit'
                                                        ? '0.00'
                                                        : ''
                                                }
                                            />
                                        </td>

                                        <td className="px-4 py-2">
                                            <Input
                                                aria-label={`Credit for row ${index + 1}`}
                                                name={`balances.${index}.credit`}
                                                inputMode="decimal"
                                                numeric
                                                value={row.credit}
                                                onChange={(e) =>
                                                    setRow(index, {
                                                        credit: e.target.value,
                                                        debit:
                                                            e.target.value === '' ? row.debit : '',
                                                    })
                                                }
                                                placeholder={
                                                    account?.normal_balance === 'credit'
                                                        ? '0.00'
                                                        : ''
                                                }
                                            />
                                        </td>

                                        <td className="px-4 py-2">
                                            <button
                                                type="button"
                                                onClick={() => removeRow(index)}
                                                aria-label={`Remove row ${index + 1}`}
                                                className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 mt-1 rounded p-1.5 transition-colors"
                                            >
                                                <Trash2 className="size-3.5" aria-hidden="true" />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>

                        <tfoot>
                            <tr className="border-line-subtle bg-surface-sunken text-content border-t-2 font-medium">
                                <td className="px-4 py-2.5">
                                    {/*
                                     * What will land in equity, before
                                     * anything is posted — so a mistyped
                                     * figure is visible now rather than
                                     * discovered in a trial balance later.
                                     */}
                                    {isZero(totals.difference)
                                        ? 'Nothing to equity'
                                        : `${formatMoney(absForDisplay(totals.difference), {
                                              currency,
                                              showCurrency: false,
                                          })} to opening balance equity`}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                    {formatMoney(totals.debits, {
                                        currency,
                                        showCurrency: false,
                                    })}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums">
                                    {formatMoney(totals.credits, {
                                        currency,
                                        showCurrency: false,
                                    })}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <footer className="border-line-subtle bg-surface-sunken flex items-center justify-between gap-2 border-t px-4 py-3">
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<Plus aria-hidden="true" />}
                        onClick={addRow}
                    >
                        Add a row
                    </Button>

                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={form.processing}
                        disabled={!complete}
                        icon={<Save aria-hidden="true" />}
                    >
                        Post the balances
                    </Button>
                </footer>
            </form>
        </Card>
    );
}

/**
 * Unpaid invoices, or unpaid bills.
 *
 * One component for both, because they are the same form with different words
 * — and the difference that matters is stated in the blurb rather than in the
 * shape of the fields.
 */
function DocumentSection({
    kind,
    title,
    blurb,
    contacts,
    documents,
    openingDate,
    currency,
    canEnter,
}: {
    kind: 'invoice' | 'bill';
    title: string;
    blurb: string;
    contacts: ContactOption[];
    documents: OpeningDocument[];
    openingDate: string;
    currency: string;
    canEnter: boolean;
}) {
    const [adding, setAdding] = useState(false);

    const total = useMemo(
        () => sumForDisplay(documents.map((document) => document.balance_due)),
        [documents],
    );

    return (
        <Card flush>
            <header className="border-line-subtle flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                <div className="min-w-0">
                    <h2 className="text-content text-md font-semibold">{title}</h2>
                    <p className="text-content-muted text-xs">{blurb}</p>
                </div>

                {documents.length > 0 && (
                    <p className="text-content text-sm font-semibold tabular-nums">
                        {formatMoney(total, { currency })}
                    </p>
                )}
            </header>

            {adding && canEnter && (
                <DocumentForm
                    kind={kind}
                    contacts={contacts}
                    openingDate={openingDate}
                    currency={currency}
                    onClose={() => setAdding(false)}
                />
            )}

            {documents.length === 0 ? (
                <EmptyState
                    icon={Scale}
                    title={`No opening ${kind === 'invoice' ? 'invoices' : 'bills'}`}
                    description={
                        kind === 'invoice'
                            ? 'If customers owed you anything when you moved, add each invoice here.'
                            : 'If you owed vendors anything when you moved, add each bill here.'
                    }
                    action={
                        canEnter && !adding ? (
                            <Button variant="primary" size="sm" onClick={() => setAdding(true)}>
                                Add one
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Number</th>
                                    <th className="px-4 py-2 text-left font-medium">
                                        {kind === 'invoice' ? 'Customer' : 'Vendor'}
                                    </th>
                                    <th className="px-4 py-2 text-left font-medium">Due</th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Outstanding
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {documents.map((document) => (
                                    <tr key={document.id} className="hover:bg-surface-hover">
                                        <td className="px-4 py-2.5">
                                            <Link
                                                href={
                                                    kind === 'invoice'
                                                        ? `/sales/invoices/${document.number}`
                                                        : `/purchases/bills/${document.number}`
                                                }
                                                className="text-brand-text font-medium tabular-nums hover:underline"
                                            >
                                                {document.number}
                                            </Link>
                                        </td>

                                        <td className="text-content-secondary px-4 py-2.5">
                                            {document.contact_name ?? '—'}
                                        </td>

                                        <td className="px-4 py-2.5 tabular-nums">
                                            <span
                                                className={
                                                    document.is_overdue
                                                        ? 'text-danger-600 dark:text-danger-400'
                                                        : 'text-content-secondary'
                                                }
                                            >
                                                {document.due_date ?? '—'}
                                            </span>
                                            {document.is_overdue && (
                                                <Badge tone="danger" className="ml-2">
                                                    {document.days_overdue}d
                                                </Badge>
                                            )}
                                        </td>

                                        <td className="text-content px-4 py-2.5 text-right font-medium tabular-nums">
                                            {formatMoney(document.balance_due, {
                                                currency,
                                                showCurrency: false,
                                            })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {canEnter && !adding && (
                        <footer className="border-line-subtle border-t px-4 py-3">
                            <Button
                                variant="secondary"
                                size="sm"
                                icon={<Plus aria-hidden="true" />}
                                onClick={() => setAdding(true)}
                            >
                                Add another
                            </Button>
                        </footer>
                    )}
                </>
            )}
        </Card>
    );
}

function DocumentForm({
    kind,
    contacts,
    openingDate,
    currency,
    onClose,
}: {
    kind: 'invoice' | 'bill';
    contacts: ContactOption[];
    openingDate: string;
    currency: string;
    onClose: () => void;
}) {
    const form = useForm({
        contact_id: '',
        amount: '',
        issue_date: '',
        due_date: '',
        reference: '',
        opening_date: openingDate,
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.post(
            kind === 'invoice'
                ? '/accounting/opening-balances/invoices'
                : '/accounting/opening-balances/bills',
            {
                onSuccess: () => {
                    form.reset('contact_id', 'amount', 'issue_date', 'due_date', 'reference');
                    onClose();
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="border-line-subtle border-b px-4 py-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <Select
                    label={kind === 'invoice' ? 'Customer' : 'Vendor'}
                    name="contact_id"
                    value={form.data.contact_id}
                    onChange={(e) => form.setData('contact_id', e.target.value)}
                    error={form.errors.contact_id}
                    options={[
                        {
                            value: '',
                            label: `Choose a ${kind === 'invoice' ? 'customer' : 'vendor'}`,
                        },
                        ...contacts,
                    ]}
                    required
                />

                <Input
                    label="Still outstanding"
                    name="amount"
                    inputMode="decimal"
                    numeric
                    value={form.data.amount}
                    onChange={(e) => form.setData('amount', e.target.value)}
                    error={form.errors.amount}
                    prefix={<span className="text-content-muted text-2xs">{currency}</span>}
                    hint="What is left to settle, not the original total."
                    required
                />

                <Input
                    label={kind === 'invoice' ? 'Invoice date' : 'Bill date'}
                    name="issue_date"
                    type="date"
                    value={form.data.issue_date}
                    onChange={(e) => form.setData('issue_date', e.target.value)}
                    error={form.errors.issue_date}
                    hint="Its real date, so the ageing is right."
                    required
                />

                <Input
                    label="Due"
                    name="due_date"
                    type="date"
                    value={form.data.due_date}
                    onChange={(e) => form.setData('due_date', e.target.value)}
                    error={form.errors.due_date}
                    hint="Left blank, their payment terms decide."
                    optional
                />

                <Input
                    label={kind === 'invoice' ? 'Their reference' : 'Their invoice number'}
                    name="reference"
                    value={form.data.reference}
                    onChange={(e) => form.setData('reference', e.target.value)}
                    error={form.errors.reference}
                    placeholder="The number it had in the old system"
                    optional
                />

                <Input
                    label="Recognised on"
                    name="opening_date"
                    type="date"
                    value={form.data.opening_date}
                    onChange={(e) => form.setData('opening_date', e.target.value)}
                    error={form.errors.opening_date}
                    hint="Where the entry lands — the first day these books cover."
                    required
                />
            </div>

            <div className="mt-3 flex items-center justify-end gap-2">
                <Button variant="ghost" size="sm" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    variant="primary"
                    size="sm"
                    loading={form.processing}
                    disabled={form.data.contact_id === '' || form.data.amount === ''}
                    icon={<Save aria-hidden="true" />}
                >
                    Bring it across
                </Button>
            </div>
        </form>
    );
}
