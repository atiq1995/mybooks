import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { Boxes, Scale } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { formatMoney } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface StockRow {
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

interface ItemsProps {
    rows: StockRow[];
    total: string;
    warehouses: Option[];
    filters: { warehouse: string; search: string };
    untracked: number;
    tracked: number;
    baseCurrency: string;
    can: { manage: boolean; adjust: boolean };
}

/**
 * What is on the shelf.
 *
 * Distinct from the item catalogue under Sales, which is where somebody goes
 * to change a price. This screen answers a different question — what do we
 * have, and what is it worth — and every figure on it is the one the
 * inventory control account carries.
 */
export default function Items({
    rows,
    total,
    warehouses,
    filters,
    untracked,
    tracked,
    baseCurrency,
    can,
}: ItemsProps) {
    const [search, setSearch] = useState(filters.search);

    const reload = (changes: Record<string, string>) => {
        router.get(
            '/inventory/items',
            { warehouse: filters.warehouse, search, ...changes },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const isFiltered = filters.search !== '' || filters.warehouse !== '';

    return (
        <AppLayout
            title="Stock on hand"
            description={`What is on the shelf, valued at weighted average cost. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Inventory' }, { label: 'Stock on hand' }]}
            actions={
                can.adjust ? (
                    <Button
                        variant="primary"
                        icon={<Scale aria-hidden="true" />}
                        onClick={() => router.get('/inventory/adjustments')}
                    >
                        Adjust stock
                    </Button>
                ) : undefined
            }
        >
            <Head title="Stock on hand" />

            <div className="flex flex-col gap-4">
                <div className="flex flex-wrap items-end gap-3">
                    <Select
                        label="Warehouse"
                        containerClassName="w-full sm:w-64"
                        options={[{ value: '', label: 'Every warehouse' }, ...warehouses]}
                        value={filters.warehouse}
                        onChange={(event) => reload({ warehouse: event.target.value })}
                    />

                    <form
                        className="flex-1"
                        onSubmit={(event: SyntheticEvent) => {
                            event.preventDefault();
                            reload({});
                        }}
                    >
                        <Input
                            label="Search"
                            placeholder="Name or SKU"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </form>
                </div>

                <Card className="flex flex-wrap items-baseline gap-x-8 gap-y-2">
                    <div>
                        <p className="text-content-muted text-xs">Total stock value</p>
                        <p className="text-content text-xl font-semibold tabular-nums">
                            {formatMoney(total, { currency: baseCurrency })}
                        </p>
                    </div>
                    <p className="text-content-muted text-xs">
                        {tracked} tracked {tracked === 1 ? 'item' : 'items'}
                        {untracked > 0 && `, ${untracked} not tracked`}. This total is what the
                        inventory control account carries — `verify-ledger` checks the two agree at
                        every date.
                    </p>
                </Card>

                <Card flush>
                    {rows.length === 0 ? (
                        <div className="p-6">
                            {isFiltered ? (
                                <NoResultsState
                                    query={filters.search}
                                    onClear={() => {
                                        setSearch('');
                                        router.get('/inventory/items');
                                    }}
                                />
                            ) : (
                                <EmptyState
                                    icon={Boxes}
                                    title="Nothing is tracked yet"
                                    description="Turn on stock tracking on an item — it needs an inventory account — and its quantity will appear here as soon as something is bought or adjusted in."
                                    action={
                                        can.manage ? (
                                            <Button
                                                variant="primary"
                                                onClick={() => router.get('/sales/items')}
                                            >
                                                Go to items
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            )}
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
                                            On hand
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
                                            <td className="px-4 py-2">
                                                <a
                                                    href={`/inventory/items/${row.item_id}/movements`}
                                                    onClick={(event) => {
                                                        event.preventDefault();
                                                        router.get(
                                                            `/inventory/items/${row.item_id}/movements`,
                                                        );
                                                    }}
                                                    className="hover:text-brand focus-visible:outline-focus rounded-sm underline decoration-dotted underline-offset-2 focus-visible:outline-2"
                                                >
                                                    {row.item_name}
                                                </a>
                                                {row.sku !== null && (
                                                    <span className="text-content-muted ml-2 text-xs">
                                                        {row.sku}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-content-secondary px-4 py-2">
                                                {row.warehouse}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-2 text-right tabular-nums',
                                                    row.quantity.startsWith('-') && 'text-danger',
                                                )}
                                            >
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
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

/**
 * Quantities are stored to six places because a unit can be a fraction of a
 * litre. Showing six zeros on a shelf of 12 widgets helps nobody.
 */
function trimQuantity(quantity: string): string {
    if (!quantity.includes('.')) {
        return quantity;
    }

    const trimmed = quantity.replace(/0+$/, '').replace(/\.$/, '');

    return trimmed === '' || trimmed === '-' ? '0' : trimmed;
}
