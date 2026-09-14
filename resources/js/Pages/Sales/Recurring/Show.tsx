import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Pause,
    Pencil,
    Play,
    Repeat,
    Trash2,
} from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import type { BadgeTone } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { EmptyState } from '@ui/States';
import type { TemplateSummary } from './Index';
import { formatMoney } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface TemplateLine {
    id: string;
    description: string;
    quantity: string;
    unit_price: string;
    tax_name: string | null;
}

interface Run {
    id: string;
    scheduled_for: string;
    ran_at: string;
    outcome: string;
    failure_reason: string | null;
    number: string | null;
    total: string | null;
    status: string | null;
}

interface ShowProps {
    template: TemplateSummary & {
        notes: string | null;
        terms: string | null;
        lines: TemplateLine[];
        runs: Run[];
    };
    baseCurrency: string;
    can: Record<string, boolean>;
}

/**
 * One recurring invoice, and everything it has produced.
 *
 * The history is the point of this page, and it includes the failures. A run
 * that could not produce an invoice is exactly what somebody needs to see —
 * hiding it would leave a customer unbilled with no sign of why, which is the
 * worst outcome this module has available.
 */
export default function RecurringShow({ template, baseCurrency, can }: ShowProps) {
    const status = useForm({ status: '' });
    const generate = useForm({});

    const setStatus = (next: string) => {
        status.transform(() => ({ status: next }));
        status.post(`/sales/recurring-invoices/${template.id}/status`, { preserveScroll: true });
    };

    return (
        <AppLayout
            title={template.name}
            description={`${template.schedule} for ${template.contact_name ?? 'a customer'}`}
            breadcrumbs={[
                { label: 'Sales' },
                { label: 'Recurring invoices', href: '/sales/recurring-invoices' },
                { label: template.name },
            ]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {can.update && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<Pencil aria-hidden="true" />}
                            onClick={() =>
                                router.get(`/sales/recurring-invoices/${template.id}/edit`)
                            }
                        >
                            Edit
                        </Button>
                    )}

                    {can.generate && template.status === 'active' && (
                        <Button
                            variant="primary"
                            size="md"
                            loading={generate.processing}
                            icon={<Repeat aria-hidden="true" />}
                            onClick={() =>
                                generate.post(`/sales/recurring-invoices/${template.id}/generate`, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Run now
                        </Button>
                    )}

                    {can.update && template.status === 'active' && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<Pause aria-hidden="true" />}
                            onClick={() => setStatus('paused')}
                        >
                            Pause
                        </Button>
                    )}

                    {can.update && template.status === 'paused' && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<Play aria-hidden="true" />}
                            onClick={() => setStatus('active')}
                        >
                            Resume
                        </Button>
                    )}

                    {can.update && template.status !== 'ended' && (
                        <Button
                            variant="secondary"
                            size="md"
                            icon={<Ban aria-hidden="true" />}
                            onClick={() => {
                                if (
                                    !window.confirm(
                                        `End "${template.name}"?\n\n` +
                                            'It stops generating. The invoices it already ' +
                                            'produced are unaffected.',
                                    )
                                ) {
                                    return;
                                }

                                setStatus('ended');
                            }}
                        >
                            End it
                        </Button>
                    )}

                    {can.delete && (
                        <Button
                            variant="ghost"
                            size="md"
                            icon={<Trash2 aria-hidden="true" />}
                            onClick={() => {
                                if (
                                    !window.confirm(
                                        `Delete "${template.name}"?\n\n` +
                                            'The invoices it already generated stay exactly ' +
                                            'as they are — they are ordinary documents with ' +
                                            'their own numbers.',
                                    )
                                ) {
                                    return;
                                }

                                router.delete(`/sales/recurring-invoices/${template.id}`);
                            }}
                        >
                            Delete
                        </Button>
                    )}
                </div>
            }
        >
            <Head title={template.name} />

            {template.is_overdue_to_run && (
                <Card className="border-line-brand mb-4">
                    <p className="text-content text-sm font-medium">
                        This schedule has periods it has not billed.
                    </p>
                    <p className="text-content-secondary text-sm">
                        The next run bills every one of them, each dated on its own occurrence —
                        nothing is skipped. It runs automatically each morning, or you can run it
                        now.
                    </p>
                </Card>
            )}

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Fact label="Schedule">{template.schedule}</Fact>

                <Fact label="Next invoice">
                    <span
                        className={
                            template.is_overdue_to_run
                                ? 'text-warning-600 dark:text-warning-400'
                                : undefined
                        }
                    >
                        {template.next_run_on ?? '—'}
                    </span>
                </Fact>

                <Fact label="Generated">
                    {template.occurrences_generated}
                    {template.max_occurrences !== null && ` of ${template.max_occurrences}`}
                </Fact>

                <Fact label="Status">
                    <Badge tone={toneOf(template.status)}>{label(template.status)}</Badge>
                    {!template.auto_issue && (
                        <span className="text-content-muted block text-xs">
                            saved as drafts for review
                        </span>
                    )}
                </Fact>
            </div>

            <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">History</h2>
                        <p className="text-content-muted text-xs">
                            Every occurrence, including the ones that could not be produced.
                        </p>
                    </header>

                    {template.runs.length === 0 ? (
                        <EmptyState
                            icon={Repeat}
                            title="Nothing generated yet"
                            description={
                                template.next_run_on === null
                                    ? 'This schedule has run to its end.'
                                    : `The first invoice is dated ${template.next_run_on}.`
                            }
                        />
                    ) : (
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">For</th>
                                        <th className="px-4 py-2 text-left font-medium">Invoice</th>
                                        <th className="px-4 py-2 text-right font-medium">Total</th>
                                        <th className="px-4 py-2 text-left font-medium">Outcome</th>
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {template.runs.map((run) => (
                                        <tr
                                            key={run.id}
                                            className={cn(
                                                'hover:bg-surface-hover',
                                                run.outcome !== 'generated' && 'bg-status-danger',
                                            )}
                                        >
                                            <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                                {run.scheduled_for}
                                            </td>

                                            <td className="px-4 py-2.5">
                                                {run.number === null ? (
                                                    <span className="text-content-disabled">—</span>
                                                ) : (
                                                    <Link
                                                        href={`/sales/invoices/${run.number}`}
                                                        className="text-brand-text font-medium tabular-nums hover:underline"
                                                    >
                                                        {run.number}
                                                    </Link>
                                                )}
                                            </td>

                                            <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                                {run.total === null
                                                    ? '—'
                                                    : formatMoney(run.total, {
                                                          currency: template.currency,
                                                          showCurrency: false,
                                                      })}
                                            </td>

                                            <td className="px-4 py-2.5">
                                                {run.outcome === 'generated' ? (
                                                    <span className="text-content-secondary flex items-center gap-1.5 text-xs">
                                                        <CheckCircle2
                                                            className="text-success-700 dark:text-success-400 size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        {run.ran_at}
                                                    </span>
                                                ) : (
                                                    <span className="text-status-danger-fg flex items-start gap-1.5 text-xs">
                                                        <AlertTriangle
                                                            className="mt-0.5 size-3.5 shrink-0"
                                                            aria-hidden="true"
                                                        />
                                                        {run.failure_reason ?? 'Failed'}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>

                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">What it bills</h2>
                        <p className="text-content-muted text-xs">
                            Amounts in {template.currency}. Tax is computed on each invoice.
                        </p>
                    </header>

                    <ul className="divide-line-subtle divide-y">
                        {template.lines.map((line) => (
                            <li key={line.id} className="px-4 py-2.5">
                                <p className="text-content text-sm">{line.description}</p>
                                <p className="text-content-muted text-xs tabular-nums">
                                    {trimZeros(line.quantity)} ×{' '}
                                    {formatMoney(line.unit_price, {
                                        currency: template.currency,
                                        showCurrency: false,
                                    })}
                                    {line.tax_name !== null && ` · ${line.tax_name}`}
                                </p>
                            </li>
                        ))}
                    </ul>

                    <footer className="border-line-subtle text-content-muted border-t px-4 py-3 text-xs">
                        <p>
                            Starts {template.starts_on}
                            {template.ends_on !== null && `, ends ${template.ends_on}`}. Terms{' '}
                            {template.payment_terms_days} days. Amounts in {baseCurrency} unless the
                            customer's own currency says otherwise.
                        </p>
                    </footer>
                </Card>
            </div>
        </AppLayout>
    );
}

function toneOf(status: string): BadgeTone {
    return status === 'active' ? 'success' : status === 'paused' ? 'warning' : 'neutral';
}

function label(status: string): string {
    return status === 'active' ? 'Active' : status === 'paused' ? 'Paused' : 'Ended';
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <Card>
            <p className="text-content-muted text-2xs uppercase">{label}</p>
            <p className="text-content mt-1 text-sm font-medium">{children}</p>
        </Card>
    );
}

/** 1.000000 reads as 1; 2.500000 reads as 2.5. */
function trimZeros(value: string): string {
    return value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value;
}
