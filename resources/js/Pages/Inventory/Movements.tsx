import { Head, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { formatMoney } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Movement {
    id: string;
    date: string;
    kind: string;
    warehouse: string;
    quantity: string;
    unit_cost: string;
    value: string;
    quantity_after: string;
    value_after: string;
    average_after: string;
    source_type: string;
    source_id: string | null;
    entry_no: string | null;
    memo: string | null;
}

interface MovementsProps {
    item: {
        id: string;
        sku: string | null;
        name: string;
        unit: string | null;
        is_tracked: boolean;
    };
    movements: Movement[];
    warehouses: { value: string; label: string }[];
    filters: { warehouse: string };
    baseCurrency: string;
    can: { manage: boolean; adjust: boolean };
}

const KIND_LABEL: Record<string, string> = {
    receipt: 'Received',
    shipment: 'Shipped',
    return_in: 'Returned in',
    return_out: 'Returned out',
    adjustment: 'Adjusted',
    revaluation: 'Revalued',
    transfer_in: 'Transferred in',
    transfer_out: 'Transferred out',
    opening: 'Opening',
};

/**
 * Everything that ever happened to one item.
 *
 * The running columns on the right are why this screen exists: a weighted
 * average is only defensible if the arithmetic behind it can be read, and
 * each row shows the position its own movement produced.
 */
export default function Movements({
    item,
    movements,
    warehouses,
    filters,
    baseCurrency,
}: MovementsProps) {
    return (
        <AppLayout
            title={item.name}
            description={`Stock history${item.sku === null ? '' : ` · ${item.sku}`}. Amounts in ${baseCurrency}.`}
            breadcrumbs={[
                { label: 'Inventory' },
                { label: 'Stock on hand', href: '/inventory/items' },
                { label: item.name },
            ]}
        >
            <Head title={`${item.name} — stock history`} />

            <div className="flex flex-col gap-4">
                <Select
                    label="Warehouse"
                    containerClassName="w-full sm:w-64"
                    options={[{ value: '', label: 'Every warehouse' }, ...warehouses]}
                    value={filters.warehouse}
                    onChange={(event) =>
                        router.get(`/inventory/items/${item.id}/movements`, {
                            warehouse: event.target.value,
                        })
                    }
                />

                {!item.is_tracked && (
                    <div className="border-line-subtle bg-surface-sunken rounded-md border p-3">
                        <p className="text-content-secondary text-xs">
                            This item is not stock-tracked, so it has no quantity to move. Anything
                            below is history from when it was.
                        </p>
                    </div>
                )}

                <Card flush>
                    {movements.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={History}
                                title="Nothing has moved yet"
                                description="Stock appears here as soon as a bill bringing it in is approved, or an adjustment is."
                            />
                        </div>
                    ) : (
                        <div className="table-scroll">
                            <table className="w-full min-w-[54rem] text-sm">
                                <thead className="border-line-subtle text-content-muted border-b text-xs">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">What</th>
                                        <th className="px-4 py-2 text-left font-medium">Where</th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Quantity
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">Value</th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            On hand after
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Average after
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">Entry</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {movements.map((movement) => (
                                        <tr
                                            key={movement.id}
                                            className="border-line-subtle border-b last:border-0"
                                        >
                                            <td className="text-content-secondary px-4 py-2 whitespace-nowrap tabular-nums">
                                                {movement.date}
                                            </td>
                                            <td className="px-4 py-2">
                                                <Badge
                                                    tone={
                                                        movement.quantity.startsWith('-')
                                                            ? 'warning'
                                                            : 'success'
                                                    }
                                                >
                                                    {KIND_LABEL[movement.kind] ?? movement.kind}
                                                </Badge>
                                                {movement.memo !== null && (
                                                    <span className="text-content-muted ml-2 text-xs">
                                                        {movement.memo}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-content-secondary px-4 py-2">
                                                {movement.warehouse}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-2 text-right tabular-nums',
                                                    movement.quantity.startsWith('-')
                                                        ? 'text-danger'
                                                        : 'text-content',
                                                )}
                                            >
                                                {movement.quantity}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-2 text-right tabular-nums',
                                                    movement.value.startsWith('-')
                                                        ? 'text-danger'
                                                        : 'text-content',
                                                )}
                                            >
                                                {formatMoney(movement.value, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                            <td className="text-content px-4 py-2 text-right tabular-nums">
                                                {movement.quantity_after}
                                            </td>
                                            <td className="text-content-secondary px-4 py-2 text-right tabular-nums">
                                                {formatMoney(movement.average_after, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                            <td className="text-content-muted px-4 py-2 whitespace-nowrap">
                                                {movement.entry_no ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <div>
                    <Button variant="ghost" onClick={() => router.get('/inventory/items')}>
                        Back to stock
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
