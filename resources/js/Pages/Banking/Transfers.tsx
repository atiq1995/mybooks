import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { ArrowLeftRight, Plus } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney } from '@/Utils/money';

interface TransferRow {
    id: string;
    number: string;
    date: string;
    from: string | null;
    to: string | null;
    currency: string;
    amount: string;
    destination_currency: string;
    amount_received: string;
    is_cross_currency: boolean;
    reference: string | null;
    entry_no: string | null;
    is_voided: boolean;
}

interface TransfersProps {
    transfers: {
        data: TransferRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    accounts: { value: string; label: string; currency: string }[];
    today: string;
    baseCurrency: string;
    can: { create: boolean; void: boolean };
}

/**
 * Money moved between the organisation's own accounts.
 *
 * The one banking screen that posts, and it says so: a transfer is a journal
 * entry — debit where it landed, credit where it left — and never income or
 * expense on either side. The entry number is shown on every row, because a
 * transfer whose entry nobody can find is indistinguishable from one that
 * never posted.
 */
export default function Transfers({
    transfers,
    accounts,
    today,
    baseCurrency,
    can,
}: TransfersProps) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout
            title="Transfers"
            description="Between your own accounts. Never income, never expense."
            breadcrumbs={[{ label: 'Banking' }, { label: 'Transfers' }]}
            actions={
                can.create && !adding ? (
                    <Button
                        variant="primary"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => setAdding(true)}
                    >
                        Record a transfer
                    </Button>
                ) : undefined
            }
        >
            <Head title="Transfers" />

            <div className="flex flex-col gap-4">
                {adding && can.create && (
                    <TransferForm
                        accounts={accounts}
                        today={today}
                        baseCurrency={baseCurrency}
                        onDone={() => setAdding(false)}
                    />
                )}

                {transfers.data.length === 0 ? (
                    <EmptyState
                        icon={ArrowLeftRight}
                        title="No transfers yet"
                        description="Moving money between two of your own accounts — a bank to petty cash, or one bank to another."
                        action={
                            can.create ? (
                                <Button variant="primary" onClick={() => setAdding(true)}>
                                    Record a transfer
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Card flush>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[46rem] text-sm">
                                <thead className="border-line-subtle text-content-muted border-b text-xs">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">Number</th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">From</th>
                                        <th className="px-4 py-2 text-left font-medium">To</th>
                                        <th className="px-4 py-2 text-right font-medium">Amount</th>
                                        <th className="px-4 py-2 text-left font-medium">Entry</th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transfers.data.map((transfer) => (
                                        <TransferRowView
                                            key={transfer.id}
                                            transfer={transfer}
                                            can={can}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-line-subtle border-t px-4 py-3">
                            <Pagination links={transfers.links} />
                        </div>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function TransferRowView({ transfer, can }: { transfer: TransferRow; can: TransfersProps['can'] }) {
    const voidForm = useForm({ reason: '' });

    return (
        <tr className="border-line-subtle border-b last:border-0">
            <td className="text-content px-4 py-2 font-medium whitespace-nowrap">
                {transfer.number}
                {transfer.is_voided && (
                    <Badge tone="neutral" className="ml-2">
                        Voided
                    </Badge>
                )}
            </td>
            <td className="text-content-secondary px-4 py-2 whitespace-nowrap tabular-nums">
                {transfer.date}
            </td>
            <td className="text-content-secondary px-4 py-2">{transfer.from ?? '—'}</td>
            <td className="text-content-secondary px-4 py-2">{transfer.to ?? '—'}</td>
            <td className="text-content px-4 py-2 text-right whitespace-nowrap tabular-nums">
                {formatMoney(transfer.amount, { currency: transfer.currency })}
                {transfer.is_cross_currency && (
                    <span className="text-content-muted block text-xs">
                        arrived as{' '}
                        {formatMoney(transfer.amount_received, {
                            currency: transfer.destination_currency,
                        })}
                    </span>
                )}
            </td>
            <td className="text-content-muted px-4 py-2 whitespace-nowrap">
                {transfer.entry_no ?? '—'}
            </td>
            <td className="px-4 py-2 text-right whitespace-nowrap">
                {can.void && !transfer.is_voided && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            /*
                             * Confirmed, because it posts a reversing entry.
                             * Nothing is deleted — the original stays, and the
                             * reversal sits beside it.
                             */
                            if (
                                window.confirm(
                                    `Void ${transfer.number}? A reversing entry is posted; nothing is deleted.`,
                                )
                            ) {
                                voidForm.post(`/banking/transfers/${transfer.id}/void`, {
                                    preserveScroll: true,
                                });
                            }
                        }}
                    >
                        Void
                    </Button>
                )}
            </td>
        </tr>
    );
}

function TransferForm({
    accounts,
    today,
    baseCurrency,
    onDone,
}: {
    accounts: TransfersProps['accounts'];
    today: string;
    baseCurrency: string;
    onDone: () => void;
}) {
    const form = useForm({
        from_account_id: accounts[0]?.value ?? '',
        to_account_id: accounts[1]?.value ?? '',
        transfer_date: today,
        amount: '',
        amount_received: '',
        exchange_rate: '1',
        destination_exchange_rate: '1',
        reference: '',
        notes: '',
    });

    const from = accounts.find((account) => account.value === form.data.from_account_id);
    const to = accounts.find((account) => account.value === form.data.to_account_id);

    /*
     * Two currencies means two amounts, and the second cannot be derived: the
     * rate the bank actually used is a fact, not an arithmetic step.
     */
    const crossCurrency = from !== undefined && to !== undefined && from.currency !== to.currency;

    return (
        <Card>
            <form
                className="flex flex-col gap-4"
                onSubmit={(event: SyntheticEvent) => {
                    event.preventDefault();
                    form.post('/banking/transfers', {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            onDone();
                        },
                    });
                }}
            >
                <div>
                    <h2 className="text-content text-md font-semibold">Record a transfer</h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        This posts: the destination account is debited and the source credited, on
                        the date below.
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Select
                        label="From"
                        options={accounts}
                        value={form.data.from_account_id}
                        onChange={(event) => form.setData('from_account_id', event.target.value)}
                        error={form.errors.from_account_id}
                    />
                    <Select
                        label="To"
                        options={accounts.filter(
                            (account) => account.value !== form.data.from_account_id,
                        )}
                        value={form.data.to_account_id}
                        onChange={(event) => form.setData('to_account_id', event.target.value)}
                        error={form.errors.to_account_id}
                    />
                    <Input
                        type="date"
                        label="Date"
                        value={form.data.transfer_date}
                        onChange={(event) => form.setData('transfer_date', event.target.value)}
                        error={form.errors.transfer_date}
                    />
                    <Input
                        numeric
                        inputMode="decimal"
                        label="Amount sent"
                        prefix={from?.currency ?? baseCurrency}
                        value={form.data.amount}
                        onChange={(event) => form.setData('amount', event.target.value)}
                        error={form.errors.amount}
                    />
                </div>

                {crossCurrency && (
                    <div className="border-line-subtle grid gap-3 rounded-md border border-dashed p-3 sm:grid-cols-3">
                        <Input
                            numeric
                            inputMode="decimal"
                            label="Amount received"
                            prefix={to?.currency ?? baseCurrency}
                            value={form.data.amount_received}
                            onChange={(event) =>
                                form.setData('amount_received', event.target.value)
                            }
                            error={form.errors.amount_received}
                            hint="What actually arrived, from the statement."
                        />
                        <Input
                            numeric
                            inputMode="decimal"
                            label={`Rate for ${from?.currency ?? ''}`}
                            value={form.data.exchange_rate}
                            onChange={(event) => form.setData('exchange_rate', event.target.value)}
                            error={form.errors.exchange_rate}
                            hint={`To ${baseCurrency}.`}
                        />
                        <Input
                            numeric
                            inputMode="decimal"
                            label={`Rate for ${to?.currency ?? ''}`}
                            value={form.data.destination_exchange_rate}
                            onChange={(event) =>
                                form.setData('destination_exchange_rate', event.target.value)
                            }
                            error={form.errors.destination_exchange_rate}
                            hint={`To ${baseCurrency}. Whatever the two sides do not agree on is booked as an FX gain or loss.`}
                        />
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <Input
                        label="Reference"
                        optional
                        value={form.data.reference}
                        onChange={(event) => form.setData('reference', event.target.value)}
                        error={form.errors.reference}
                    />
                    <Input
                        label="Notes"
                        optional
                        value={form.data.notes}
                        onChange={(event) => form.setData('notes', event.target.value)}
                        error={form.errors.notes}
                    />
                </div>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Record and post
                    </Button>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    );
}
