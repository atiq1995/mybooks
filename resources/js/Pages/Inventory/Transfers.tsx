import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { ArrowLeftRight, Plus, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney } from '@/Utils/money';

interface Option {
    value: string;
    label: string;
}

interface ItemOption extends Option {
    unit: string | null;
    on_hand: Record<string, string>;
}

interface TransferRow {
    id: string;
    number: string;
    date: string;
    from: string | null;
    to: string | null;
    status: string;
    lines: number;
    total_value: string;
    is_completed: boolean;
}

interface TransfersProps {
    transfers: {
        data: TransferRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    warehouses: Option[];
    items: ItemOption[];
    today: string;
    baseCurrency: string;
    can: { transfer: boolean };
}

interface DraftLine {
    item_id: string;
    quantity: string;
}

const EMPTY_LINE: DraftLine = { item_id: '', quantity: '' };

/**
 * Stock moving between warehouses.
 *
 * It posts nothing, and the screen says so: the business owns exactly what it
 * owned before, in a different place. Value travels with the goods at the
 * source warehouse's average, so neither side is restated.
 */
export default function Transfers({
    transfers,
    warehouses,
    items,
    today,
    baseCurrency,
    can,
}: TransfersProps) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout
            title="Stock transfers"
            description="Between your own warehouses. Nothing posts — the business owns what it owned before."
            breadcrumbs={[{ label: 'Inventory' }, { label: 'Transfers' }]}
            actions={
                can.transfer && !adding ? (
                    <Button
                        variant="primary"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => setAdding(true)}
                    >
                        New transfer
                    </Button>
                ) : undefined
            }
        >
            <Head title="Stock transfers" />

            <div className="flex flex-col gap-4">
                {adding && can.transfer && (
                    <TransferForm
                        warehouses={warehouses}
                        items={items}
                        today={today}
                        onDone={() => setAdding(false)}
                    />
                )}

                {transfers.data.length === 0 ? (
                    <EmptyState
                        icon={ArrowLeftRight}
                        title="No transfers yet"
                        description="Moving stock from one warehouse to another, at the cost the source warehouse carries."
                        action={
                            can.transfer ? (
                                <Button variant="primary" onClick={() => setAdding(true)}>
                                    New transfer
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Card flush>
                        <div className="table-scroll">
                            <table className="w-full min-w-[46rem] text-sm">
                                <thead className="border-line-subtle text-content-muted border-b text-xs">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">Number</th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">From</th>
                                        <th className="px-4 py-2 text-left font-medium">To</th>
                                        <th className="px-4 py-2 text-right font-medium">Value</th>
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
                                            baseCurrency={baseCurrency}
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

function TransferRowView({
    transfer,
    baseCurrency,
    can,
}: {
    transfer: TransferRow;
    baseCurrency: string;
    can: TransfersProps['can'];
}) {
    const complete = useForm({});

    return (
        <tr className="border-line-subtle border-b last:border-0">
            <td className="text-content px-4 py-2 font-medium whitespace-nowrap">
                {transfer.number}
                {transfer.is_completed && (
                    <Badge tone="success" className="ml-2">
                        Completed
                    </Badge>
                )}
            </td>
            <td className="text-content-secondary px-4 py-2 whitespace-nowrap tabular-nums">
                {transfer.date}
            </td>
            <td className="text-content-secondary px-4 py-2">{transfer.from ?? '—'}</td>
            <td className="text-content-secondary px-4 py-2">{transfer.to ?? '—'}</td>
            <td className="text-content px-4 py-2 text-right tabular-nums">
                {transfer.is_completed
                    ? formatMoney(transfer.total_value, {
                          currency: baseCurrency,
                          showCurrency: false,
                      })
                    : '—'}
            </td>
            <td className="px-4 py-2 text-right whitespace-nowrap">
                {can.transfer && !transfer.is_completed && (
                    <Button
                        size="sm"
                        variant="secondary"
                        loading={complete.processing}
                        onClick={() =>
                            complete.post(`/inventory/transfers/${transfer.id}/complete`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Complete
                    </Button>
                )}
            </td>
        </tr>
    );
}

function TransferForm({
    warehouses,
    items,
    today,
    onDone,
}: {
    warehouses: Option[];
    items: ItemOption[];
    today: string;
    onDone: () => void;
}) {
    const form = useForm({
        transfer_date: today,
        from_warehouse_id: warehouses[0]?.value ?? '',
        to_warehouse_id: warehouses[1]?.value ?? '',
        notes: '',
        lines: [{ ...EMPTY_LINE }] as DraftLine[],
    });

    return (
        <Card>
            <form
                className="flex flex-col gap-4"
                onSubmit={(event: SyntheticEvent) => {
                    event.preventDefault();
                    form.post('/inventory/transfers', {
                        preserveScroll: true,
                        onSuccess: onDone,
                    });
                }}
            >
                <div>
                    <h2 className="text-content text-md font-semibold">New transfer</h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        The goods move when the transfer is completed, at the source warehouse's
                        weighted average on that day.
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        type="date"
                        label="Date"
                        value={form.data.transfer_date}
                        onChange={(event) => form.setData('transfer_date', event.target.value)}
                        error={form.errors.transfer_date}
                    />
                    <Select
                        label="From"
                        options={warehouses}
                        value={form.data.from_warehouse_id}
                        onChange={(event) => form.setData('from_warehouse_id', event.target.value)}
                        error={form.errors.from_warehouse_id}
                    />
                    <Select
                        label="To"
                        options={warehouses.filter(
                            (warehouse) => warehouse.value !== form.data.from_warehouse_id,
                        )}
                        value={form.data.to_warehouse_id}
                        onChange={(event) => form.setData('to_warehouse_id', event.target.value)}
                        error={form.errors.to_warehouse_id}
                    />
                </div>

                <div className="flex flex-col gap-2">
                    {form.data.lines.map((line, index) => {
                        const item = items.find((option) => option.value === line.item_id);
                        const onHand = item?.on_hand[form.data.from_warehouse_id] ?? '0';

                        return (
                            <div
                                key={index}
                                className="border-line-subtle grid gap-2 rounded-md border p-3 sm:grid-cols-3"
                            >
                                <Select
                                    label="Item"
                                    options={items}
                                    placeholder="Choose an item"
                                    value={line.item_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'lines',
                                            form.data.lines.map((existing, at) =>
                                                at === index
                                                    ? { ...existing, item_id: event.target.value }
                                                    : existing,
                                            ),
                                        )
                                    }
                                />
                                <Input
                                    numeric
                                    inputMode="decimal"
                                    label="Quantity"
                                    value={line.quantity}
                                    onChange={(event) =>
                                        form.setData(
                                            'lines',
                                            form.data.lines.map((existing, at) =>
                                                at === index
                                                    ? { ...existing, quantity: event.target.value }
                                                    : existing,
                                            ),
                                        )
                                    }
                                    hint={`At the source: ${onHand}`}
                                />
                                <div className="flex items-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        icon={<Trash2 aria-hidden="true" />}
                                        onClick={() =>
                                            form.setData(
                                                'lines',
                                                form.data.lines.filter((_, at) => at !== index),
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>
                        );
                    })}

                    <div>
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            icon={<Plus aria-hidden="true" />}
                            onClick={() =>
                                form.setData('lines', [...form.data.lines, { ...EMPTY_LINE }])
                            }
                        >
                            Add a line
                        </Button>
                    </div>

                    {form.errors.lines !== undefined && (
                        <p className="text-danger text-xs">{form.errors.lines}</p>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save
                    </Button>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    );
}
