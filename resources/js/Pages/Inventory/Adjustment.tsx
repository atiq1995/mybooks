import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Undo2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { formatMoney } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface AdjustmentLine {
    id: string;
    item_id: string;
    sku: string | null;
    item_name: string;
    unit: string | null;
    counted_quantity: string | null;
    quantity_change: string;
    value_change: string;
    unit_cost: string | null;
    memo: string | null;
}

interface AdjustmentProps {
    adjustment: {
        id: string;
        number: string;
        date: string;
        warehouse: string | null;
        kind_label: string;
        account: string | null;
        reason: string;
        notes: string | null;
        status: string;
        status_label: string;
        total_value: string;
        entry_no: string | null;
        is_editable: boolean;
        is_approved: boolean;
        is_voided: boolean;
        lines: AdjustmentLine[];
    };
    baseCurrency: string;
    can: { adjust: boolean; approve: boolean; void: boolean };
}

const STATUS_TONE: Record<string, BadgeTone> = {
    draft: 'neutral',
    approved: 'success',
    void: 'warning',
};

/**
 * One adjustment, and what it did.
 *
 * A draft says plainly that nothing has changed yet. An approved one shows
 * the entry it posted, because a stock figure somebody cannot trace to the
 * ledger is a stock figure nobody trusts.
 */
export default function Adjustment({ adjustment, baseCurrency, can }: AdjustmentProps) {
    const approve = useForm({});
    const voidForm = useForm({ reason: '' });

    return (
        <AppLayout
            title={`Adjustment ${adjustment.number}`}
            description={adjustment.reason}
            breadcrumbs={[
                { label: 'Inventory' },
                { label: 'Adjustments', href: '/inventory/adjustments' },
                { label: adjustment.number },
            ]}
            actions={
                <div className="flex items-center gap-2">
                    {adjustment.is_editable && can.approve && (
                        <Button
                            variant="primary"
                            icon={<CheckCircle2 aria-hidden="true" />}
                            loading={approve.processing}
                            onClick={() =>
                                approve.post(`/inventory/adjustments/${adjustment.id}/approve`, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Approve
                        </Button>
                    )}

                    {adjustment.is_approved && can.void && (
                        <Button
                            variant="ghost"
                            icon={<Undo2 aria-hidden="true" />}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        `Void ${adjustment.number}? A reversing entry is posted and the stock goes back — at today's average cost, which may differ from the original.`,
                                    )
                                ) {
                                    voidForm.post(`/inventory/adjustments/${adjustment.id}/void`, {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            Void
                        </Button>
                    )}
                </div>
            }
        >
            <Head title={`Adjustment ${adjustment.number}`} />

            <div className="flex flex-col gap-4">
                <Card className="flex flex-wrap items-start justify-between gap-4">
                    <dl className="grid flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt className="text-content-muted text-xs">Date</dt>
                            <dd className="text-content text-sm tabular-nums">{adjustment.date}</dd>
                        </div>
                        <div>
                            <dt className="text-content-muted text-xs">Warehouse</dt>
                            <dd className="text-content text-sm">{adjustment.warehouse ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-content-muted text-xs">Other side</dt>
                            <dd className="text-content text-sm">{adjustment.account ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-content-muted text-xs">Value moved</dt>
                            <dd className="text-content text-sm font-medium tabular-nums">
                                {formatMoney(adjustment.total_value, { currency: baseCurrency })}
                            </dd>
                        </div>
                    </dl>

                    <div className="flex flex-col items-end gap-2">
                        <Badge tone={STATUS_TONE[adjustment.status] ?? 'neutral'} dot>
                            {adjustment.status_label}
                        </Badge>
                        {adjustment.entry_no !== null && (
                            <span className="text-content-muted text-xs">
                                Entry {adjustment.entry_no}
                            </span>
                        )}
                    </div>
                </Card>

                {adjustment.is_editable && (
                    <div className="border-line-subtle bg-surface-sunken rounded-md border p-3">
                        <p className="text-content-secondary text-xs">
                            This is a draft. Nothing has moved and nothing has posted — approving it
                            is what changes the stock and writes the entry.
                        </p>
                    </div>
                )}

                <Card flush>
                    <div className="table-scroll">
                        <table className="w-full min-w-[42rem] text-sm">
                            <thead className="border-line-subtle text-content-muted border-b text-xs">
                                <tr>
                                    <th className="px-4 py-2 text-left font-medium">Item</th>
                                    <th className="px-4 py-2 text-right font-medium">Counted</th>
                                    <th className="px-4 py-2 text-right font-medium">Change</th>
                                    <th className="px-4 py-2 text-right font-medium">Unit cost</th>
                                    <th className="px-4 py-2 text-left font-medium">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {adjustment.lines.map((line) => (
                                    <tr
                                        key={line.id}
                                        className="border-line-subtle border-b last:border-0"
                                    >
                                        <td className="text-content px-4 py-2">
                                            {line.item_name}
                                            {line.sku !== null && (
                                                <span className="text-content-muted ml-2 text-xs">
                                                    {line.sku}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-content-secondary px-4 py-2 text-right tabular-nums">
                                            {line.counted_quantity ?? '—'}
                                        </td>
                                        <td
                                            className={cn(
                                                'px-4 py-2 text-right tabular-nums',
                                                line.quantity_change.startsWith('-')
                                                    ? 'text-danger'
                                                    : 'text-content',
                                            )}
                                        >
                                            {line.quantity_change}
                                            {line.unit !== null && (
                                                <span className="text-content-muted ml-1 text-xs">
                                                    {line.unit}
                                                </span>
                                            )}
                                        </td>
                                        <td className="text-content-secondary px-4 py-2 text-right tabular-nums">
                                            {line.unit_cost ?? '—'}
                                        </td>
                                        <td className="text-content-muted px-4 py-2">
                                            {line.memo ?? ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {adjustment.notes !== null && (
                    <Card>
                        <p className="text-content-muted text-xs">{adjustment.notes}</p>
                    </Card>
                )}

                <div>
                    <Button variant="ghost" onClick={() => router.get('/inventory/adjustments')}>
                        Back to adjustments
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
