import { Head, usePage } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, FileText, Plus, Receipt, Wallet } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card, CardHeader } from '@ui/Card';
import { EmptyState } from '@ui/States';
import { cn } from '@/Utils/cn';
import { formatMoney } from '@/Utils/money';
import type { SharedProps } from '@/Types/inertia';

interface Metric {
    key: string;
    label: string;
    /** Decimal string from the server. Null until the ledger exists. */
    value: string | null;
    /** Percentage change against the comparison period. */
    change: number | null;
    tone: 'neutral' | 'positive' | 'negative';
    hint: string;
}

interface DashboardProps {
    metrics: Metric[];
    hasLedgerData: boolean;
}

/**
 * The dashboard shell.
 *
 * Phase 0 has no ledger, so every figure is genuinely unknown — and the page
 * says so rather than showing a confident zero. In an accounting product,
 * "0.00" and "we have not computed this" are very different claims, and
 * blurring them is how someone concludes their receivables are clear when
 * nothing has been posted yet.
 */
export default function DashboardIndex({ metrics, hasLedgerData }: DashboardProps) {
    const { organization } = usePage<SharedProps>().props;
    const currency = organization?.base_currency ?? 'PKR';
    const locale = organization?.locale ?? 'en-PK';

    return (
        <AppLayout
            title="Dashboard"
            description={
                organization !== null
                    ? `An overview of ${organization.name}`
                    : 'An overview of your books'
            }
            actions={
                <>
                    <Button variant="secondary" size="md" icon={<Receipt aria-hidden="true" />}>
                        Record payment
                    </Button>
                    <Button variant="primary" size="md" icon={<Plus aria-hidden="true" />}>
                        New invoice
                    </Button>
                </>
            }
        >
            <Head title="Dashboard" />

            {/* Metrics */}
            <section aria-label="Key figures" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {metrics.map((metric) => (
                    <MetricTile
                        key={metric.key}
                        metric={metric}
                        currency={currency}
                        locale={locale}
                    />
                ))}
            </section>

            {/* Working area */}
            <div className="mt-4 grid gap-3 lg:grid-cols-3">
                <Card flush className="lg:col-span-2">
                    <CardHeader
                        title="Recent transactions"
                        description="Everything posted to the ledger, newest first"
                    />
                    {hasLedgerData ? null : (
                        <EmptyState
                            icon={FileText}
                            title="No transactions yet"
                            description="Once invoices, bills and expenses are posted, they appear here with a link straight through to their journal entries."
                            action={
                                <Button
                                    variant="primary"
                                    size="sm"
                                    icon={<Plus aria-hidden="true" />}
                                >
                                    Create your first invoice
                                </Button>
                            }
                        />
                    )}
                </Card>

                <Card flush>
                    <CardHeader
                        title="Cash position"
                        description="Across all bank and cash accounts"
                    />
                    {hasLedgerData ? null : (
                        <EmptyState
                            icon={Wallet}
                            title="No accounts connected"
                            description="Add a bank or cash account to track balances and reconcile statements."
                            action={
                                <Button variant="secondary" size="sm">
                                    Add an account
                                </Button>
                            }
                        />
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

function MetricTile({
    metric,
    currency,
    locale,
}: {
    metric: Metric;
    currency: string;
    locale: string;
}) {
    const hasValue = metric.value !== null;
    const change = metric.change;

    return (
        <Card className="flex flex-col gap-1.5">
            <p className="text-content-secondary text-xs font-medium">{metric.label}</p>

            <p
                className={cn(
                    'text-2xl font-semibold tabular-nums',
                    hasValue ? 'text-content' : 'text-content-disabled',
                )}
            >
                {hasValue ? formatMoney(metric.value, { currency, locale }) : '—'}
            </p>

            <div className="flex items-center gap-1.5">
                {change !== null && hasValue ? (
                    <>
                        <span
                            className={cn(
                                'inline-flex items-center gap-0.5 text-xs font-medium tabular-nums',
                                change >= 0 ? 'text-success-600' : 'text-danger-600',
                            )}
                        >
                            {change >= 0 ? (
                                <ArrowUpRight className="size-3" aria-hidden="true" />
                            ) : (
                                <ArrowDownRight className="size-3" aria-hidden="true" />
                            )}
                            {Math.abs(change).toFixed(1)}%
                        </span>
                        <span className="text-content-muted text-xs">vs last period</span>
                    </>
                ) : (
                    <span className="text-content-muted text-xs">{metric.hint}</span>
                )}
            </div>
        </Card>
    );
}
