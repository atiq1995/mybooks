import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Camera, Plus, Receipt as ReceiptIcon, ShieldCheck, Users } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

export interface ExpenseSummary {
    id: string;
    number: string;
    contact_id: string | null;
    merchant: string | null;
    expense_date: string;
    payment_mode: string;
    payment_mode_label: string;
    paid_through_account_id: string | null;
    reimburse_user_id: string | null;
    reference: string | null;
    status: string;
    status_label: string;
    status_tone: string;
    currency: string;
    exchange_rate: string;
    prices_include_tax: boolean;
    subtotal: string;
    tax_total: string;
    tax_claimable_total: string;
    tax_capitalised: string;
    total: string;
    total_base: string;
    is_billable: boolean;
    billable_contact_id: string | null;
    billable_contact_name: string | null;
    is_billed: boolean;
    awaiting_rebill: boolean;
    has_receipt: boolean;
    is_editable: boolean;
    is_posted: boolean;
    is_void: boolean;
    is_foreign_currency: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface IndexProps {
    expenses: {
        data: ExpenseSummary[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: {
        awaiting_approval: string;
        awaiting_count: number;
        owed_to_people: string;
        to_rebill: string;
    };
    filters: { search: string; status: string; view: string };
    statuses: { value: string; label: string }[];
    baseCurrency: string;
    can: Record<string, boolean>;
}

/**
 * Expenses, and the two questions people arrive at this screen with.
 *
 * Those questions are what needs approving and what needs billing on, so both
 * are a figure at the top that is also a filter. The third is what we owe our
 * own people — a number a payroll run needs and nothing else in the product
 * shows.
 *
 * A missing receipt is called out per row rather than left to be discovered
 * at approval, because the person who can fix it is the person reading this
 * list.
 */
export default function ExpensesIndex({
    expenses,
    summary,
    filters,
    statuses,
    baseCurrency,
    can,
}: IndexProps) {
    const [draft, setDraft] = useState(filters);
    const [selected, setSelected] = useState<string[]>([]);

    const apply = (next: Partial<typeof filters>) => {
        const merged = { ...draft, ...next };
        setDraft(merged);
        setSelected([]);

        router.get('/expenses', pruneEmpty(merged), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const clear = () => {
        const empty = { search: '', status: '', view: '' };
        setDraft(empty);
        router.get('/expenses', {}, { preserveState: true, replace: true });
    };

    const isFiltered = Object.values(filters).some((value) => value !== '');
    const rebillable = expenses.data.filter((expense) => expense.awaiting_rebill);

    const toggle = (id: string) => {
        setSelected((current) =>
            current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
        );
    };

    return (
        <AppLayout
            title="Expenses"
            description={`Money spent, and what still needs approving. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Expenses' }]}
            actions={
                can.create ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => router.get('/expenses/new')}
                    >
                        Record an expense
                    </Button>
                ) : undefined
            }
        >
            <Head title="Expenses" />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <Figure
                    icon={ShieldCheck}
                    label="Awaiting approval"
                    value={summary.awaiting_approval}
                    currency={baseCurrency}
                    note={
                        summary.awaiting_count === 0
                            ? 'Nothing waiting.'
                            : `${summary.awaiting_count} ${
                                  summary.awaiting_count === 1 ? 'expense' : 'expenses'
                              }, not yet in the books.`
                    }
                    active={filters.view === 'approvals'}
                    onClick={() =>
                        apply({ view: filters.view === 'approvals' ? '' : 'approvals', status: '' })
                    }
                />

                <Figure
                    icon={Users}
                    label="Owed to people"
                    value={summary.owed_to_people}
                    currency={baseCurrency}
                    note="Approved, reimbursable, not yet paid back."
                />

                <Figure
                    icon={ReceiptIcon}
                    label="To rebill"
                    value={summary.to_rebill}
                    currency={baseCurrency}
                    note="Billable costs no invoice covers yet."
                    active={filters.view === 'to_bill'}
                    onClick={() =>
                        apply({ view: filters.view === 'to_bill' ? '' : 'to_bill', status: '' })
                    }
                />
            </div>

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        label="Search"
                        name="search"
                        value={draft.search}
                        onChange={(e) => setDraft({ ...draft, search: e.target.value })}
                        onBlur={() => apply({})}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({});
                        }}
                        placeholder="Number, merchant or reference"
                    />

                    <Select
                        label="Status"
                        name="status"
                        value={draft.status}
                        onChange={(e) => apply({ status: e.target.value, view: '' })}
                        options={[{ value: '', label: 'Any status' }, ...statuses]}
                    />
                </div>
            </Card>

            {/*
             * The rebill bar appears only when there is something to rebill
             * and the user may create the invoice — a control that would
             * always be refused teaches people the software is broken.
             */}
            {can.rebill && rebillable.length > 0 && (
                <Card className="border-line-brand mb-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-content-secondary text-sm">
                            {selected.length === 0
                                ? `${rebillable.length} of these can be rebilled to a customer.`
                                : `${selected.length} chosen.`}
                            <span className="text-content-muted block text-xs">
                                A draft invoice is created at cost, for one customer at a time.
                                Nothing is issued.
                            </span>
                        </p>

                        <div className="flex items-center gap-2">
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => setSelected(rebillable.map((e) => e.id))}
                            >
                                Choose all
                            </Button>
                            <Button
                                variant="primary"
                                size="sm"
                                disabled={selected.length === 0}
                                onClick={() =>
                                    router.post('/expenses/rebill', { expenses: selected })
                                }
                            >
                                Create draft invoice
                            </Button>
                        </div>
                    </div>
                </Card>
            )}

            <Card flush>
                {expenses.total === 0 && !isFiltered ? (
                    <EmptyState
                        icon={ReceiptIcon}
                        title="No expenses yet"
                        description="Record what was spent, attach the receipt, and submit it. Nothing reaches the ledger until somebody else approves it."
                        action={
                            can.create ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => router.get('/expenses/new')}
                                >
                                    Record an expense
                                </Button>
                            ) : undefined
                        }
                    />
                ) : expenses.data.length === 0 ? (
                    <NoResultsState
                        query={filters.search === '' ? undefined : filters.search}
                        onClear={clear}
                    />
                ) : (
                    <>
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        {can.rebill && rebillable.length > 0 && (
                                            <th className="w-10 px-4 py-2" />
                                        )}
                                        <th className="px-4 py-2 text-left font-medium">Number</th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Spent on
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">Paid</th>
                                        <th className="px-4 py-2 text-right font-medium">Total</th>
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {expenses.data.map((expense) => (
                                        <tr
                                            key={expense.id}
                                            className={cn(
                                                'hover:bg-surface-hover',
                                                expense.is_void && 'opacity-60',
                                            )}
                                        >
                                            {can.rebill && rebillable.length > 0 && (
                                                <td className="px-4 py-2.5">
                                                    {expense.awaiting_rebill && (
                                                        <input
                                                            type="checkbox"
                                                            aria-label={`Choose ${expense.number} to rebill`}
                                                            checked={selected.includes(expense.id)}
                                                            onChange={() => toggle(expense.id)}
                                                            className="border-line accent-brand size-4 rounded"
                                                        />
                                                    )}
                                                </td>
                                            )}

                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center gap-2">
                                                    <Link
                                                        href={`/expenses/${expense.number}`}
                                                        className="text-brand-text font-medium tabular-nums hover:underline"
                                                    >
                                                        {expense.number}
                                                    </Link>
                                                    {/*
                                                     * Called out here, where
                                                     * the person who can fix
                                                     * it is reading.
                                                     */}
                                                    {!expense.has_receipt && !expense.is_void && (
                                                        <span
                                                            className="text-content-muted flex items-center gap-1 text-xs"
                                                            title="No receipt attached"
                                                        >
                                                            <Camera
                                                                className="size-3"
                                                                aria-hidden="true"
                                                            />
                                                            no receipt
                                                        </span>
                                                    )}
                                                </div>
                                                {expense.reference !== null && (
                                                    <span className="text-content-muted text-xs">
                                                        {expense.reference}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5">
                                                {expense.merchant ?? '—'}
                                                {expense.is_billable && (
                                                    <span className="text-content-muted block text-xs">
                                                        {expense.is_billed
                                                            ? 'rebilled'
                                                            : `to rebill${
                                                                  expense.billable_contact_name ===
                                                                  null
                                                                      ? ''
                                                                      : `: ${expense.billable_contact_name}`
                                                              }`}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                                {expense.expense_date}
                                            </td>

                                            <td className="text-content-muted px-4 py-2.5 text-xs">
                                                {expense.payment_mode_label}
                                            </td>

                                            <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                                {formatMoney(expense.total, {
                                                    currency: expense.currency,
                                                    showCurrency: expense.is_foreign_currency,
                                                })}
                                                {!isZero(expense.tax_capitalised) && (
                                                    <span
                                                        className="text-content-muted block text-xs"
                                                        title="Tax on this expense that cannot be reclaimed, and is part of the cost"
                                                    >
                                                        incl.{' '}
                                                        {formatMoney(expense.tax_capitalised, {
                                                            currency: expense.currency,
                                                            showCurrency: false,
                                                        })}{' '}
                                                        unclaimable tax
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-4 py-2.5">
                                                <Badge
                                                    tone={
                                                        (expense.status_tone as BadgeTone) ??
                                                        'neutral'
                                                    }
                                                >
                                                    {expense.status_label}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <footer className="border-line-subtle text-content-muted flex items-center justify-between border-t px-4 py-2.5 text-xs">
                            <span>
                                {expenses.from}–{expenses.to} of {expenses.total}
                            </span>
                            <Pagination links={expenses.links} />
                        </footer>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

function Figure({
    icon: Icon,
    label,
    value,
    currency,
    note,
    active = false,
    onClick,
}: {
    icon: typeof ShieldCheck;
    label: string;
    value: string;
    currency: string;
    note: string;
    active?: boolean;
    onClick?: () => void;
}) {
    const body = (
        <>
            <p className="text-content-muted text-2xs flex items-center gap-1.5 uppercase">
                <Icon className="size-3" aria-hidden="true" />
                {label}
            </p>
            <p className="text-content mt-1 text-lg font-semibold tabular-nums">
                {formatMoney(value, { currency, showCurrency: false })}
            </p>
            <p className="text-content-muted text-xs">{note}</p>
        </>
    );

    if (onClick === undefined) {
        return <Card>{body}</Card>;
    }

    return (
        <Card className={cn('p-0', active && 'border-line-brand')}>
            <button
                type="button"
                onClick={onClick}
                aria-pressed={active}
                className="hover:bg-surface-hover focus-visible:outline-focus w-full rounded-md p-4 text-left transition-colors focus-visible:outline-2"
            >
                {body}
            </button>
        </Card>
    );
}

function pruneEmpty(filters: Record<string, string>): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
}
