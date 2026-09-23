import { Head, router } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { formatMoney } from '@/Utils/money';

interface ValuationRow {
    item_id: string;
    warehouse_id: string;
    sku: string | null;
    item_name: string;
    unit: string | null;
    warehouse: string;
    quantity: string;
    value: string;
    average_cost: string;
}

interface Option {
    value: string;
    label: string;
}

interface ValuationProps {
    rows: ValuationRow[];
    total: string;
    quantity: string;
    warehouses: Option[];
    filters: { as_of: string; warehouse: string };
    baseCurrency: string;
    can: { manage: boolean; adjust: boolean };
}

/**
 * What stock was worth on a date.
 *
 * "On a date" rather than "now" is the whole point: it is the figure that has
 * to agree with the inventory control account on a balance sheet, and a
 * balance sheet is always as at something. The answer comes from the last
 * movement on or before the date rather than from replaying the ledger, which
 * is why the movements carry their running balance.
 */
export default function Valuation({
    rows,
    total,
    quantity,
    warehouses,
    filters,
    baseCurrency,
}: ValuationProps) {
    const reload = (changes: Record<string, string>) => {
        router.get(
            '/inventory/valuation',
            { as_of: filters.as_of, warehouse: filters.warehouse, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            title="Stock valuation"
            description={`What the shelf was worth, at weighted average cost. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Inventory' }, { label: 'Valuation' }]}
        >
            <Head title="Stock valuation" />

            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Input
                        type="date"
                        label="As at"
                        containerClassName="w-full sm:w-48"
                        value={filters.as_of}
                        onChange={(event) => reload({ as_of: event.target.value })}
                    />

                    <Select
                        label="Warehouse"
                        containerClassName="w-full sm:w-64"
                        options={[{ value: '', label: 'Every warehouse' }, ...warehouses]}
                        value={filters.warehouse}
                        onChange={(event) => reload({ warehouse: event.target.value })}
                    />
                </div>

                <Card className="flex flex-wrap items-baseline gap-x-8 gap-y-2">
                    <div>
                        <p className="text-content-muted text-xs">Value as at {filters.as_of}</p>
                        <p className="text-content text-2xl font-semibold tabular-nums">
                            {formatMoney(total, { currency: baseCurrency })}
                        </p>
                    </div>
                    <div>
                        <p className="text-content-muted text-xs">Units</p>
                        <p className="text-content-secondary text-sm tabular-nums">
                            {trimQuantity(quantity)}
                        </p>
                    </div>
                    <p className="text-content-muted max-w-md text-xs">
                        This is the figure the inventory control account carries on that date.
                        `verify-ledger` checks the two agree for every date on which either side
                        moved — not only for today.
                    </p>
                </Card>

                <Card flush>
                    {rows.length === 0 ? (
                        <div className="p-6">
                            <EmptyState
                                icon={Scale}
                                title="Nothing on the shelf on that date"
                                description="No tracked item had moved by then. Pick a later date, or check that the purchases bringing stock in have been approved."
                            />
                        </div>
                    ) : (
                        <div className="table-scroll">
                            <table className="w-full min-w-[44rem] text-sm">
                                <thead className="border-line-subtle text-content-muted border-b text-xs">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">Item</th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Warehouse
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Quantity
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Average cost
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr
                                            key={`${row.item_id}-${row.warehouse_id}`}
                                            className="border-line-subtle border-b last:border-0"
                                        >
                                            <td className="text-content px-4 py-2">
                                                {row.item_name}
                                                {row.sku !== null && (
                                                    <span className="text-content-muted ml-2 text-xs">
                                                        {row.sku}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-content-secondary px-4 py-2">
                                                {row.warehouse}
                                            </td>
                                            <td className="text-content px-4 py-2 text-right tabular-nums">
                                                {trimQuantity(row.quantity)}
                                                {row.unit !== null && (
                                                    <span className="text-content-muted ml-1 text-xs">
                                                        {row.unit}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-content-secondary px-4 py-2 text-right tabular-nums">
                                                {formatMoney(row.average_cost, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                            <td className="text-content px-4 py-2 text-right font-medium tabular-nums">
                                                {formatMoney(row.value, {
                                                    currency: baseCurrency,
                                                    showCurrency: false,
                                                })}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-line bg-surface-sunken border-t">
                                        <td
                                            className="text-content px-4 py-2 font-semibold"
                                            colSpan={4}
                                        >
                                            Total
                                        </td>
                                        <td className="text-content px-4 py-2 text-right font-semibold tabular-nums">
                                            {formatMoney(total, {
                                                currency: baseCurrency,
                                                showCurrency: false,
                                            })}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

function trimQuantity(quantity: string): string {
    if (!quantity.includes('.')) {
        return quantity;
    }

    const trimmed = quantity.replace(/0+$/, '').replace(/\.$/, '');

    return trimmed === '' || trimmed === '-' ? '0' : trimmed;
}
