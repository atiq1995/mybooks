import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Scale, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
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

interface AdjustmentRow {
    id: string;
    number: string;
    date: string;
    warehouse: string | null;
    kind: string;
    kind_label: string;
    account: string | null;
    reason: string;
    status: string;
    status_label: string;
    total_value: string;
    is_editable: boolean;
    is_approved: boolean;
    is_voided: boolean;
}

interface AdjustmentsProps {
    adjustments: {
        data: AdjustmentRow[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { status: string };
    warehouses: Option[];
    accounts: Option[];
    items: ItemOption[];
    today: string;
    baseCurrency: string;
    can: { adjust: boolean; approve: boolean; void: boolean };
}

const STATUS_TONE: Record<string, BadgeTone> = {
    draft: 'neutral',
    approved: 'success',
    void: 'warning',
};

interface DraftLine {
    item_id: string;
    counted_quantity: string;
    quantity_change: string;
    unit_cost: string;
    memo: string;
}

const EMPTY_LINE: DraftLine = {
    item_id: '',
    counted_quantity: '',
    quantity_change: '',
    unit_cost: '',
    memo: '',
};

/**
 * Stock adjustments — a count that disagreed, a breakage, a write-off.
 *
 * The form asks for a COUNT before it asks for a difference, because that is
 * what somebody standing at a shelf actually has. The difference is worked
 * out on the server from what is on hand, so the two can never disagree.
 */
export default function Adjustments({
    adjustments,
    filters,
    warehouses,
    accounts,
    items,
    today,
    baseCurrency,
    can,
}: AdjustmentsProps) {
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout
            title="Stock adjustments"
            description="Counts, breakages and write-offs. Nothing changes until an adjustment is approved."
            breadcrumbs={[{ label: 'Inventory' }, { label: 'Adjustments' }]}
            actions={
                can.adjust && !adding ? (
                    <Button
                        variant="primary"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => setAdding(true)}
                    >
                        New adjustment
                    </Button>
                ) : undefined
            }
        >
            <Head title="Stock adjustments" />

            <div className="flex flex-col gap-4">
                {adding && can.adjust && (
                    <AdjustmentForm
                        warehouses={warehouses}
                        accounts={accounts}
                        items={items}
                        today={today}
                        onDone={() => setAdding(false)}
                    />
                )}

                <Select
                    label="Status"
                    containerClassName="w-full sm:w-56"
                    options={[
                        { value: '', label: `All (${adjustments.total})` },
                        { value: 'draft', label: 'Draft' },
                        { value: 'approved', label: 'Approved' },
                        { value: 'void', label: 'Voided' },
                    ]}
                    value={filters.status}
                    onChange={(event) =>
                        router.get('/inventory/adjustments', { status: event.target.value })
                    }
                />

                {adjustments.data.length === 0 ? (
                    <EmptyState
                        icon={Scale}
                        title="No adjustments yet"
                        description="An adjustment is the one way stock changes without a sale or a purchase behind it, which is why it needs a reason and an approval."
                        action={
                            can.adjust ? (
                                <Button variant="primary" onClick={() => setAdding(true)}>
                                    New adjustment
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
                                        <th className="px-4 py-2 text-left font-medium">
                                            Warehouse
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">Reason</th>
                                        <th className="px-4 py-2 text-right font-medium">Value</th>
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {adjustments.data.map((adjustment) => (
                                        <tr
                                            key={adjustment.id}
                                            className="border-line-subtle border-b last:border-0"
                                        >
                                            <td className="px-4 py-2 font-medium whitespace-nowrap">
                                                <a
                                                    href={`/inventory/adjustments/${adjustment.id}`}
                                                    onClick={(event) => {
                                                        event.preventDefault();
                                                        router.get(
                                                            `/inventory/adjustments/${adjustment.id}`,
                                                        );
                                                    }}
                                                    className="hover:text-brand focus-visible:outline-focus rounded-sm underline decoration-dotted underline-offset-2 focus-visible:outline-2"
                                                >
                                                    {adjustment.number}
                                                </a>
                                            </td>
                                            <td className="text-content-secondary px-4 py-2 whitespace-nowrap tabular-nums">
                                                {adjustment.date}
                                            </td>
                                            <td className="text-content-secondary px-4 py-2">
                                                {adjustment.warehouse ?? '—'}
                                            </td>
                                            <td className="text-content-secondary px-4 py-2">
                                                {adjustment.reason}
                                            </td>
                                            <td className="text-content px-4 py-2 text-right tabular-nums">
                                                {formatMoney(adjustment.total_value, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                            <td className="px-4 py-2">
                                                <Badge
                                                    tone={
                                                        STATUS_TONE[adjustment.status] ?? 'neutral'
                                                    }
                                                    dot
                                                >
                                                    {adjustment.status_label}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-line-subtle border-t px-4 py-3">
                            <Pagination links={adjustments.links} />
                        </div>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function AdjustmentForm({
    warehouses,
    accounts,
    items,
    today,
    onDone,
}: {
    warehouses: Option[];
    accounts: Option[];
    items: ItemOption[];
    today: string;
    onDone: () => void;
}) {
    const form = useForm({
        adjustment_date: today,
        warehouse_id: warehouses[0]?.value ?? '',
        kind: 'quantity',
        account_id: accounts[0]?.value ?? '',
        reason: '',
        notes: '',
        lines: [{ ...EMPTY_LINE }] as DraftLine[],
    });

    const setLine = (index: number, changes: Partial<DraftLine>) => {
        form.setData(
            'lines',
            form.data.lines.map((line, at) => (at === index ? { ...line, ...changes } : line)),
        );
    };

    return (
        <Card>
            <form
                className="flex flex-col gap-4"
                onSubmit={(event: SyntheticEvent) => {
                    event.preventDefault();
                    form.post('/inventory/adjustments', { preserveScroll: true });
                }}
            >
                <div>
                    <h2 className="text-content text-md font-semibold">New adjustment</h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        Enter what was counted and the difference works itself out. It posts when it
                        is approved, not now.
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        type="date"
                        label="Date"
                        value={form.data.adjustment_date}
                        onChange={(event) => form.setData('adjustment_date', event.target.value)}
                        error={form.errors.adjustment_date}
                    />
                    <Select
                        label="Warehouse"
                        options={warehouses}
                        value={form.data.warehouse_id}
                        onChange={(event) => form.setData('warehouse_id', event.target.value)}
                        error={form.errors.warehouse_id}
                    />
                    <Select
                        label="Kind"
                        options={[
                            { value: 'quantity', label: 'Quantity adjustment' },
                            { value: 'opening', label: 'Opening stock' },
                        ]}
                        value={form.data.kind}
                        onChange={(event) => form.setData('kind', event.target.value)}
                        error={form.errors.kind}
                    />
                    <Select
                        label="Other side"
                        options={accounts}
                        value={form.data.account_id}
                        onChange={(event) => form.setData('account_id', event.target.value)}
                        error={form.errors.account_id}
                        hint="A write-off goes to an expense; opening stock to equity."
                    />
                </div>

                <Input
                    label="Reason"
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    error={form.errors.reason}
                    placeholder="Stocktake, 30 June"
                    hint="Required — an adjustment with no reason is an unexplained change to the value of the business."
                />

                <div className="flex flex-col gap-2">
                    {form.data.lines.map((line, index) => {
                        const item = items.find((option) => option.value === line.item_id);
                        const onHand = item?.on_hand[form.data.warehouse_id] ?? '0';

                        return (
                            <div
                                key={index}
                                className="border-line-subtle grid gap-2 rounded-md border p-3 sm:grid-cols-2 lg:grid-cols-5"
                            >
                                <Select
                                    label="Item"
                                    options={items}
                                    placeholder="Choose an item"
                                    value={line.item_id}
                                    onChange={(event) =>
                                        setLine(index, { item_id: event.target.value })
                                    }
                                />
                                <Input
                                    numeric
                                    inputMode="decimal"
                                    label="Counted"
                                    value={line.counted_quantity}
                                    onChange={(event) =>
                                        setLine(index, { counted_quantity: event.target.value })
                                    }
                                    hint={`On hand: ${onHand}`}
                                />
                                <Input
                                    numeric
                                    inputMode="decimal"
                                    label="Or change by"
                                    value={line.quantity_change}
                                    onChange={(event) =>
                                        setLine(index, { quantity_change: event.target.value })
                                    }
                                    hint="Negative to write off"
                                />
                                <Input
                                    numeric
                                    inputMode="decimal"
                                    label="Unit cost"
                                    optional
                                    value={line.unit_cost}
                                    onChange={(event) =>
                                        setLine(index, { unit_cost: event.target.value })
                                    }
                                    hint="Needed only when nothing is on hand"
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
                        Save as draft
                    </Button>
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                </div>
            </form>
        </Card>
    );
}
