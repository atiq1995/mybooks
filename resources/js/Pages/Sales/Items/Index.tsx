import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Archive, Package, Pencil, Plus, RotateCcw } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Option {
    value: string;
    label: string;
}

interface Item {
    id: string;
    kind: string;
    kind_label: string;
    sku: string | null;
    name: string;
    description: string | null;
    unit: string | null;
    sale_price: string | null;
    purchase_price: string | null;
    currency: string;
    sales_account_id: string | null;
    purchase_account_id: string | null;
    sales_tax_id: string | null;
    is_tracked: boolean;
    is_sold: boolean;
    is_purchased: boolean;
    is_archived: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface ItemsProps {
    items: {
        data: Item[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string; kind: string; archived: boolean };
    baseCurrency: string;
    options: {
        kinds: Option[];
        revenueAccounts: Option[];
        expenseAccounts: Option[];
        taxes: Option[];
    };
    can: { manage: boolean };
}

/**
 * Things you sell and buy.
 *
 * The prices here are DEFAULTS for a new document line, and the screen says
 * so — a line copies them when it is added, which is why repricing an item
 * never restates a past invoice.
 */
export default function ItemsIndex({ items, filters, baseCurrency, options, can }: ItemsProps) {
    const [search, setSearch] = useState(filters.search);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Item | null>(null);

    const apply = (next: Partial<typeof filters>) => {
        const merged = { ...filters, search, ...next };

        router.get(
            '/sales/items',
            {
                ...(merged.search === '' ? {} : { search: merged.search }),
                ...(merged.kind === '' ? {} : { kind: merged.kind }),
                ...(merged.archived ? { archived: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const isFiltered = filters.search !== '' || filters.kind !== '';

    return (
        <AppLayout
            title="Items and services"
            description={`What you put on a document. Prices are defaults, in ${baseCurrency} unless an item says otherwise.`}
            breadcrumbs={[{ label: 'Sales' }, { label: 'Items' }]}
            actions={
                <div className="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="md"
                        onClick={() => apply({ archived: !filters.archived })}
                    >
                        {filters.archived ? 'Hide archived' : 'Show archived'}
                    </Button>
                    {can.manage && !creating && (
                        <Button
                            variant="primary"
                            size="md"
                            icon={<Plus aria-hidden="true" />}
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                        >
                            New item
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Items and services" />

            {creating && can.manage && (
                <ItemForm options={options} onClose={() => setCreating(false)} />
            )}

            {editing !== null && can.manage && (
                <ItemForm item={editing} options={options} onClose={() => setEditing(null)} />
            )}

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        label="Search"
                        name="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onBlur={() => apply({})}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({});
                        }}
                        placeholder="Name, SKU or description"
                    />

                    <Select
                        label="Kind"
                        name="kind"
                        value={filters.kind}
                        onChange={(e) => apply({ kind: e.target.value })}
                        options={[{ value: '', label: 'Everything' }, ...options.kinds]}
                    />
                </div>
            </Card>

            <Card flush>
                {items.total === 0 && !isFiltered ? (
                    <EmptyState
                        icon={Package}
                        title="Nothing in the catalogue yet"
                        description="Add what you sell, so an invoice line is one selection rather than four fields. You can always type a line by hand instead."
                        action={
                            can.manage ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => setCreating(true)}
                                >
                                    New item
                                </Button>
                            ) : undefined
                        }
                    />
                ) : items.data.length === 0 ? (
                    <NoResultsState
                        query={filters.search === '' ? undefined : filters.search}
                        onClear={() => {
                            setSearch('');
                            router.get('/sales/items', {}, { preserveState: true });
                        }}
                    />
                ) : (
                    <>
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">Item</th>
                                        <th className="px-4 py-2 text-left font-medium">Kind</th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Sale price
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Purchase price
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Used for
                                        </th>
                                        <th className="w-20 px-4 py-2" />
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {items.data.map((item) => (
                                        <tr
                                            key={item.id}
                                            className={cn(
                                                'hover:bg-surface-hover',
                                                item.is_archived && 'opacity-60',
                                            )}
                                        >
                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center gap-2">
                                                    {item.sku !== null && (
                                                        <span className="text-content-muted text-xs tabular-nums">
                                                            {item.sku}
                                                        </span>
                                                    )}
                                                    <span className="text-content font-medium">
                                                        {item.name}
                                                    </span>
                                                    {item.is_tracked && (
                                                        <Badge tone="info">Tracked</Badge>
                                                    )}
                                                    {item.is_archived && (
                                                        <Badge tone="neutral">Archived</Badge>
                                                    )}
                                                </div>
                                                {item.description !== null && (
                                                    <p className="text-content-muted mt-0.5 max-w-lg truncate text-xs">
                                                        {item.description}
                                                    </p>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 text-xs">
                                                {item.kind_label}
                                                {item.unit !== null && (
                                                    <span className="text-content-muted">
                                                        {' '}
                                                        / {item.unit}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                                {item.sale_price === null
                                                    ? '—'
                                                    : formatMoney(item.sale_price, {
                                                          currency: item.currency,
                                                          showCurrency: false,
                                                      })}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                                {item.purchase_price === null
                                                    ? '—'
                                                    : formatMoney(item.purchase_price, {
                                                          currency: item.currency,
                                                          showCurrency: false,
                                                      })}
                                            </td>

                                            <td className="px-4 py-2.5">
                                                <div className="flex gap-1">
                                                    {item.is_sold && (
                                                        <Badge tone="neutral">Sales</Badge>
                                                    )}
                                                    {item.is_purchased && (
                                                        <Badge tone="neutral">Purchases</Badge>
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-4 py-2.5 text-right">
                                                {can.manage && (
                                                    <div className="flex justify-end gap-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setCreating(false);
                                                                setEditing(item);
                                                            }}
                                                            aria-label={`Edit ${item.name}`}
                                                            className="text-content-muted hover:bg-surface-active hover:text-content rounded p-1.5 transition-colors"
                                                        >
                                                            <Pencil
                                                                className="size-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        </button>

                                                        {item.is_archived ? (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/sales/items/${item.id}/restore`,
                                                                        {},
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                                aria-label={`Restore ${item.name}`}
                                                                className="text-content-muted hover:bg-surface-active hover:text-content rounded p-1.5 transition-colors"
                                                            >
                                                                <RotateCcw
                                                                    className="size-3.5"
                                                                    aria-hidden="true"
                                                                />
                                                            </button>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    if (
                                                                        !window.confirm(
                                                                            `Archive ${item.name}?\n\nPast document lines keep their own copy of its description and price, so nothing changes on them.`,
                                                                        )
                                                                    ) {
                                                                        return;
                                                                    }

                                                                    router.delete(
                                                                        `/sales/items/${item.id}`,
                                                                        { preserveScroll: true },
                                                                    );
                                                                }}
                                                                aria-label={`Archive ${item.name}`}
                                                                className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 rounded p-1.5 transition-colors"
                                                            >
                                                                <Archive
                                                                    className="size-3.5"
                                                                    aria-hidden="true"
                                                                />
                                                            </button>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <footer className="border-line-subtle text-content-muted flex items-center justify-between border-t px-4 py-2.5 text-xs">
                            <span>
                                {items.from}–{items.to} of {items.total}
                            </span>
                            <Pagination links={items.links} />
                        </footer>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

function ItemForm({
    item,
    options,
    onClose,
}: {
    item?: Item;
    options: ItemsProps['options'];
    onClose: () => void;
}) {
    const isEdit = item !== undefined;

    const form = useForm({
        kind: item?.kind ?? 'service',
        sku: item?.sku ?? '',
        name: item?.name ?? '',
        description: item?.description ?? '',
        unit: item?.unit ?? '',
        sale_price: item?.sale_price ?? '',
        purchase_price: item?.purchase_price ?? '',
        sales_account_id: item?.sales_account_id ?? '',
        purchase_account_id: item?.purchase_account_id ?? '',
        inventory_account_id: '',
        sales_tax_id: item?.sales_tax_id ?? '',
        is_tracked: item?.is_tracked ?? false,
        is_sold: item?.is_sold ?? true,
        is_purchased: item?.is_purchased ?? false,
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/sales/items/${item.id}`, {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.post('/sales/items', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    const isGoods = form.data.kind === 'goods';

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">
                        {isEdit ? `Edit ${item.name}` : 'New item'}
                    </h2>
                    <p className="text-content-muted text-xs">
                        Everything here is a default for a new line. A line copies it when added, so
                        changing this never alters a document already raised.
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Select
                        label="Kind"
                        name="kind"
                        value={form.data.kind}
                        onChange={(e) =>
                            form.setData((data) => ({
                                ...data,
                                kind: e.target.value,
                                // A service has no stock, so tracking one is a
                                // contradiction rather than a preference.
                                is_tracked: e.target.value === 'goods' ? data.is_tracked : false,
                            }))
                        }
                        error={form.errors.kind}
                        options={options.kinds}
                        required
                    />

                    <Input
                        label="Name"
                        name="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                        required
                    />

                    <Input
                        label="SKU"
                        name="sku"
                        value={form.data.sku}
                        onChange={(e) => form.setData('sku', e.target.value)}
                        error={form.errors.sku}
                        hint="Unique within this organisation."
                        optional
                    />

                    <Input
                        label="Sale price"
                        name="sale_price"
                        inputMode="decimal"
                        numeric
                        value={form.data.sale_price}
                        onChange={(e) => form.setData('sale_price', e.target.value)}
                        error={form.errors.sale_price}
                        optional
                    />

                    <Input
                        label="Purchase price"
                        name="purchase_price"
                        inputMode="decimal"
                        numeric
                        value={form.data.purchase_price}
                        onChange={(e) => form.setData('purchase_price', e.target.value)}
                        error={form.errors.purchase_price}
                        optional
                    />

                    <Input
                        label="Unit"
                        name="unit"
                        value={form.data.unit}
                        onChange={(e) => form.setData('unit', e.target.value)}
                        error={form.errors.unit}
                        placeholder="hour, kg, each"
                        optional
                    />

                    <Select
                        label="Revenue account"
                        name="sales_account_id"
                        value={form.data.sales_account_id}
                        onChange={(e) => form.setData('sales_account_id', e.target.value)}
                        error={form.errors.sales_account_id}
                        options={[
                            { value: '', label: 'Default revenue account' },
                            ...options.revenueAccounts,
                        ]}
                    />

                    <Select
                        label="Sales tax"
                        name="sales_tax_id"
                        value={form.data.sales_tax_id}
                        onChange={(e) => form.setData('sales_tax_id', e.target.value)}
                        error={form.errors.sales_tax_id}
                        options={[{ value: '', label: 'No default' }, ...options.taxes]}
                    />

                    <Select
                        label="Purchase account"
                        name="purchase_account_id"
                        value={form.data.purchase_account_id}
                        onChange={(e) => form.setData('purchase_account_id', e.target.value)}
                        error={form.errors.purchase_account_id}
                        options={[{ value: '', label: 'No default' }, ...options.expenseAccounts]}
                    />

                    <Input
                        label="Description"
                        name="description"
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        error={form.errors.description}
                        containerClassName="sm:col-span-2 lg:col-span-3"
                        placeholder="What appears on the document line by default."
                        optional
                    />

                    <div className="flex flex-col gap-2 sm:col-span-2 lg:col-span-3">
                        <label className="text-content-secondary flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_sold"
                                checked={form.data.is_sold}
                                onChange={(e) => form.setData('is_sold', e.target.checked)}
                                className="border-line accent-brand size-4 rounded"
                            />
                            <span>I sell this</span>
                        </label>

                        <label className="text-content-secondary flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_purchased"
                                checked={form.data.is_purchased}
                                onChange={(e) => form.setData('is_purchased', e.target.checked)}
                                className="border-line accent-brand size-4 rounded"
                            />
                            <span>I buy this</span>
                        </label>

                        {typeof form.errors.is_sold === 'string' && (
                            <p role="alert" className="text-danger-600 text-xs">
                                {form.errors.is_sold}
                            </p>
                        )}

                        {isGoods && (
                            <label className="text-content-secondary flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="is_tracked"
                                    checked={form.data.is_tracked}
                                    onChange={(e) => form.setData('is_tracked', e.target.checked)}
                                    className="border-line accent-brand mt-0.5 size-4 rounded"
                                />
                                <span>
                                    Track stock
                                    <span className="text-content-muted block text-xs">
                                        Tracked goods post a cost of sale on shipment, so they need
                                        an inventory account. Inventory itself arrives in a later
                                        phase — leave this off for now.
                                    </span>
                                </span>
                            </label>
                        )}
                    </div>
                </div>

                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="ghost" size="md" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" size="md" loading={form.processing}>
                        {isEdit ? 'Save changes' : 'Create item'}
                    </Button>
                </footer>
            </form>
        </Card>
    );
}
