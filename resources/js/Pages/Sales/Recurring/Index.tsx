import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Pause, Play, Plus, Repeat } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { cn } from '@/Utils/cn';

export interface TemplateSummary {
    id: string;
    name: string;
    contact_id: string;
    contact_name: string | null;
    frequency: string;
    interval: number;
    schedule: string;
    starts_on: string;
    ends_on: string | null;
    max_occurrences: number | null;
    next_run_on: string | null;
    last_run_on: string | null;
    occurrences_generated: number;
    status: string;
    auto_issue: boolean;
    payment_terms_days: number;
    currency: string;
    prices_include_tax: boolean;
    discount_type: string | null;
    discount_value: string | null;
    reference: string | null;
    is_overdue_to_run: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface IndexProps {
    templates: {
        data: TemplateSummary[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { status: string };
    baseCurrency: string;
    today: string;
    can: Record<string, boolean>;
}

/**
 * Recurring invoices: standing instructions, not documents.
 *
 * The list shows no totals and no balances, because a template has neither —
 * the invoices it has produced live on the invoice list with everything else.
 * What it shows instead is the thing somebody scanning it wants: when each
 * one next runs, and which are behind.
 */
export default function RecurringIndex({ templates, filters, today, can }: IndexProps) {
    const apply = (status: string) => {
        router.get('/sales/recurring-invoices', status === '' ? {} : { status }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <AppLayout
            title="Recurring invoices"
            description="Standing instructions that produce invoices on a schedule. Each one they generate is an ordinary invoice."
            breadcrumbs={[{ label: 'Sales' }, { label: 'Recurring invoices' }]}
            actions={
                can.create ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => router.get('/sales/recurring-invoices/new')}
                    >
                        New recurring invoice
                    </Button>
                ) : undefined
            }
        >
            <Head title="Recurring invoices" />

            <Card className="mb-4">
                <Select
                    label="Status"
                    name="status"
                    value={filters.status}
                    onChange={(e) => apply(e.target.value)}
                    options={[
                        { value: '', label: 'All' },
                        { value: 'active', label: 'Active' },
                        { value: 'paused', label: 'Paused' },
                        { value: 'ended', label: 'Ended' },
                    ]}
                    containerClassName="max-w-xs"
                />
            </Card>

            <Card flush>
                {templates.total === 0 ? (
                    <EmptyState
                        icon={Repeat}
                        title="No recurring invoices"
                        description="Set one up for a retainer or a subscription, and it bills itself on the schedule you choose. Nothing is sent until the first occurrence falls due."
                        action={
                            can.create ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => router.get('/sales/recurring-invoices/new')}
                                >
                                    New recurring invoice
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">Name</th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Customer
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Schedule
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">Next</th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Generated
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">Status</th>
                                        <th className="w-24 px-4 py-2" />
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {templates.data.map((template) => (
                                        <tr
                                            key={template.id}
                                            className={cn(
                                                'hover:bg-surface-hover',
                                                template.status === 'ended' && 'opacity-60',
                                            )}
                                        >
                                            <td className="px-4 py-2.5">
                                                <Link
                                                    href={`/sales/recurring-invoices/${template.id}`}
                                                    className="text-brand-text font-medium hover:underline"
                                                >
                                                    {template.name}
                                                </Link>
                                                {!template.auto_issue && (
                                                    <span className="text-content-muted block text-xs">
                                                        saved as drafts
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5">
                                                {template.contact_name ?? '—'}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5">
                                                {template.schedule}
                                            </td>

                                            <td className="px-4 py-2.5 tabular-nums">
                                                {template.next_run_on === null ? (
                                                    <span className="text-content-disabled">—</span>
                                                ) : (
                                                    <>
                                                        <span
                                                            className={
                                                                template.is_overdue_to_run
                                                                    ? 'text-warning-600 dark:text-warning-400 font-medium'
                                                                    : 'text-content-secondary'
                                                            }
                                                        >
                                                            {template.next_run_on}
                                                        </span>
                                                        {/*
                                                         * Behind, not late:
                                                         * nothing is wrong
                                                         * with the invoice,
                                                         * the run has not
                                                         * happened yet — and
                                                         * when it does it
                                                         * bills every period
                                                         * it owes.
                                                         */}
                                                        {template.is_overdue_to_run && (
                                                            <span
                                                                className="text-warning-600 dark:text-warning-400 flex items-center gap-1 text-xs"
                                                                title="This schedule has periods it has not billed yet. The next run will catch up."
                                                            >
                                                                <AlertTriangle
                                                                    className="size-3"
                                                                    aria-hidden="true"
                                                                />
                                                                behind
                                                            </span>
                                                        )}
                                                    </>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                                {template.occurrences_generated}
                                                {template.max_occurrences !== null && (
                                                    <span className="text-content-muted">
                                                        {' '}
                                                        / {template.max_occurrences}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-4 py-2.5">
                                                <Badge tone={toneOf(template.status)}>
                                                    {label(template.status)}
                                                </Badge>
                                            </td>

                                            <td className="px-4 py-2.5 text-right">
                                                {can.update && template.status !== 'ended' && (
                                                    <button
                                                        type="button"
                                                        aria-label={
                                                            template.status === 'active'
                                                                ? `Pause ${template.name}`
                                                                : `Resume ${template.name}`
                                                        }
                                                        onClick={() =>
                                                            router.post(
                                                                `/sales/recurring-invoices/${template.id}/status`,
                                                                {
                                                                    status:
                                                                        template.status === 'active'
                                                                            ? 'paused'
                                                                            : 'active',
                                                                },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        className="text-content-muted hover:bg-surface-hover hover:text-content rounded p-1.5 transition-colors"
                                                    >
                                                        {template.status === 'active' ? (
                                                            <Pause
                                                                className="size-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        ) : (
                                                            <Play
                                                                className="size-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <footer className="border-line-subtle text-content-muted flex items-center justify-between border-t px-4 py-2.5 text-xs">
                            <span>
                                {templates.from}–{templates.to} of {templates.total} · today is{' '}
                                {today}
                            </span>
                            <Pagination links={templates.links} />
                        </footer>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

function toneOf(status: string): BadgeTone {
    return status === 'active' ? 'success' : status === 'paused' ? 'warning' : 'neutral';
}

function label(status: string): string {
    return status === 'active' ? 'Active' : status === 'paused' ? 'Paused' : 'Ended';
}
