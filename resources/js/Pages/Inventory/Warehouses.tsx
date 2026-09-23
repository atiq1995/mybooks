import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Archive, Plus, Save, Warehouse as WarehouseIcon } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { EmptyState } from '@ui/States';
import { formatMoney } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface WarehouseRow {
    id: string;
    code: string;
    name: string;
    label: string;
    address: string | null;
    is_default: boolean;
    is_active: boolean;
    is_archived: boolean;
    value: string;
    lines: number;
}

interface WarehousesProps {
    warehouses: WarehouseRow[];
    baseCurrency: string;
    can: { manage: boolean; adjust: boolean };
}

/**
 * Where stock sits.
 *
 * The value on each card is summed from the stock ledger rather than kept on
 * the warehouse — there is no cached total here for the same reason there is
 * none on a bank account.
 */
export default function Warehouses({ warehouses, baseCurrency, can }: WarehousesProps) {
    const [editing, setEditing] = useState<WarehouseRow | null>(null);
    const [adding, setAdding] = useState(false);

    const open = adding || editing !== null;

    return (
        <AppLayout
            title="Warehouses"
            description={`Where stock sits. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Inventory' }, { label: 'Warehouses' }]}
            actions={
                can.manage && !open ? (
                    <Button
                        variant="primary"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => {
                            setEditing(null);
                            setAdding(true);
                        }}
                    >
                        Add a warehouse
                    </Button>
                ) : undefined
            }
        >
            <Head title="Warehouses" />

            <div className="flex flex-col gap-4">
                {open && (
                    <WarehouseForm
                        warehouse={editing}
                        onDone={() => {
                            setAdding(false);
                            setEditing(null);
                        }}
                    />
                )}

                {warehouses.length === 0 ? (
                    <EmptyState
                        icon={WarehouseIcon}
                        title="No warehouses yet"
                        description="One is created automatically the first time stock moves, so this is only worth filling in when there is more than one place to keep things."
                        action={
                            can.manage ? (
                                <Button variant="primary" onClick={() => setAdding(true)}>
                                    Add a warehouse
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {warehouses.map((warehouse) => (
                            <Card
                                key={warehouse.id}
                                className={cn(
                                    'flex flex-col gap-3',
                                    warehouse.is_archived && 'opacity-60',
                                )}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-content truncate text-sm font-semibold">
                                            {warehouse.name}
                                        </p>
                                        <p className="text-content-muted truncate text-xs">
                                            {warehouse.code}
                                            {warehouse.address !== null &&
                                                ` · ${warehouse.address}`}
                                        </p>
                                    </div>

                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        {warehouse.is_default && (
                                            <Badge tone="brand">Default</Badge>
                                        )}
                                        {warehouse.is_archived && (
                                            <Badge tone="neutral">Archived</Badge>
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <p className="text-content text-xl font-semibold tabular-nums">
                                        {formatMoney(warehouse.value, { currency: baseCurrency })}
                                    </p>
                                    <p className="text-content-muted text-xs">
                                        {warehouse.lines === 0
                                            ? 'Nothing on the shelf'
                                            : `${warehouse.lines} ${
                                                  warehouse.lines === 1 ? 'item' : 'items'
                                              } held`}
                                    </p>
                                </div>

                                {can.manage && !warehouse.is_archived && (
                                    <div className="border-line-subtle flex flex-wrap items-center gap-2 border-t pt-3">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setAdding(false);
                                                setEditing(warehouse);
                                            }}
                                        >
                                            Edit
                                        </Button>

                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            icon={<Archive aria-hidden="true" />}
                                            onClick={() => {
                                                /*
                                                 * Confirmed, and refused by the
                                                 * server while anything is still
                                                 * on the shelf — archiving a
                                                 * warehouse that holds stock
                                                 * would leave value in the
                                                 * inventory account with nowhere
                                                 * to be.
                                                 */
                                                if (
                                                    window.confirm(
                                                        `Archive ${warehouse.name}? Its movement history is kept, but it stops appearing on forms.`,
                                                    )
                                                ) {
                                                    router.delete(
                                                        `/inventory/warehouses/${warehouse.id}`,
                                                        { preserveScroll: true },
                                                    );
                                                }
                                            }}
                                        >
                                            Archive
                                        </Button>
                                    </div>
                                )}
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function WarehouseForm({
    warehouse,
    onDone,
}: {
    warehouse: WarehouseRow | null;
    onDone: () => void;
}) {
    const form = useForm({
        code: warehouse?.code ?? '',
        name: warehouse?.name ?? '',
        address: warehouse?.address ?? '',
        is_default: warehouse?.is_default ?? false,
        is_active: warehouse?.is_active ?? true,
    });

    return (
        <Card>
            <form
                className="flex flex-col gap-4"
                onSubmit={(event: SyntheticEvent) => {
                    event.preventDefault();

                    const options = { preserveScroll: true, onSuccess: onDone };

                    if (warehouse === null) {
                        form.post('/inventory/warehouses', options);
                    } else {
                        form.patch(`/inventory/warehouses/${warehouse.id}`, options);
                    }
                }}
            >
                <div>
                    <h2 className="text-content text-md font-semibold">
                        {warehouse === null ? 'Add a warehouse' : `Edit ${warehouse.name}`}
                    </h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        Stock is valued per warehouse, so a transfer moves value with the goods
                        rather than leaving it behind.
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        label="Code"
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                        error={form.errors.code}
                        placeholder="MAIN"
                    />
                    <Input
                        label="Name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={form.errors.name}
                        placeholder="Main warehouse"
                    />
                    <Input
                        label="Address"
                        optional
                        containerClassName="sm:col-span-2"
                        value={form.data.address}
                        onChange={(event) => form.setData('address', event.target.value)}
                        error={form.errors.address}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-4">
                    <label className="text-content-secondary flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            className="accent-brand size-4"
                            checked={form.data.is_default}
                            onChange={(event) => form.setData('is_default', event.target.checked)}
                        />
                        Where stock goes when a document does not say
                    </label>

                    <label className="text-content-secondary flex items-center gap-2 text-xs">
                        <input
                            type="checkbox"
                            className="accent-brand size-4"
                            checked={form.data.is_active}
                            onChange={(event) => form.setData('is_active', event.target.checked)}
                        />
                        In use
                    </label>
                </div>

                <div className="flex items-center gap-2">
                    <Button
                        type="submit"
                        variant="primary"
                        icon={<Save aria-hidden="true" />}
                        loading={form.processing}
                    >
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
