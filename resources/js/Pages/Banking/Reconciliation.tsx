import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Scale, TriangleAlert } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { formatMoney, isNegative, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Figures {
    opening: string;
    cleared: string;
    closing: string;
    difference: string;
    matched_count: number;
    matched_total: string;
    unmatched_count: number;
    unmatched_total: string;
    excluded_count: number;
    ledger_balance: string;
    unpresented_count: number;
    unpresented_total: string;
}

interface ReconciliationRow {
    id: string;
    number: string;
    period_start: string;
    period_end: string;
    opening_balance: string;
    closing_balance: string;
    cleared_balance: string;
    difference: string;
    status: string;
    status_label: string;
    notes: string | null;
    completed_at: string | null;
}

interface ReconciliationProps {
    accounts: { value: string; label: string; currency: string }[];
    account: {
        id: string;
        name: string;
        label: string;
        currency: string;
        reconciled_through: string | null;
        suggested_opening: string;
    } | null;
    open: (ReconciliationRow & { figures: Figures }) | null;
    history: ReconciliationRow[];
    today: string;
    baseCurrency: string;
    can: { reconcile: boolean; import: boolean };
}

/**
 * Reconciling an account to its statement.
 *
 * The difference is the largest figure on the screen, because it is the only
 * one that matters until it is zero — and when it is not, the two lines below
 * it say where it is coming from: statement lines nobody has explained, and
 * entries of ours the bank has not seen.
 */
export default function Reconciliation({
    accounts,
    account,
    open,
    history,
    today,
    baseCurrency,
    can,
}: ReconciliationProps) {
    if (account === null) {
        return (
            <AppLayout
                title="Reconciliation"
                breadcrumbs={[{ label: 'Banking' }, { label: 'Reconciliation' }]}
            >
                <Head title="Reconciliation" />
                <EmptyState
                    icon={Scale}
                    title="No bank accounts yet"
                    description="Add an account and import a statement, and it can be reconciled here."
                    action={
                        <Button variant="primary" onClick={() => router.get('/banking/accounts')}>
                            Bank accounts
                        </Button>
                    }
                />
            </AppLayout>
        );
    }

    return (
        <AppLayout
            title="Reconciliation"
            description="Agreeing the books with the bank, one statement at a time."
            breadcrumbs={[{ label: 'Banking' }, { label: 'Reconciliation' }]}
        >
            <Head title="Reconciliation" />

            <div className="flex flex-col gap-4">
                <Select
                    label="Account"
                    containerClassName="w-full sm:w-80"
                    options={accounts.map((option) => ({
                        value: option.value,
                        label: option.label,
                    }))}
                    value={account.id}
                    onChange={(event) =>
                        router.get('/banking/reconciliation', { account: event.target.value })
                    }
                    hint={
                        account.reconciled_through === null
                            ? 'Never reconciled.'
                            : `Reconciled to ${account.reconciled_through}.`
                    }
                />

                {open === null ? (
                    <StartForm
                        account={account}
                        today={today}
                        baseCurrency={baseCurrency}
                        can={can}
                    />
                ) : (
                    <OpenReconciliation
                        reconciliation={open}
                        currency={account.currency}
                        accountId={account.id}
                        can={can}
                    />
                )}

                <History history={history} currency={account.currency} />
            </div>
        </AppLayout>
    );
}

function OpenReconciliation({
    reconciliation,
    currency,
    accountId,
    can,
}: {
    reconciliation: ReconciliationRow & { figures: Figures };
    currency: string;
    accountId: string;
    can: ReconciliationProps['can'];
}) {
    const figures = reconciliation.figures;
    const reconciled = isZero(figures.difference);

    const complete = useForm({});
    const abandon = useForm({});

    return (
        <Card className="flex flex-col gap-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-content text-md font-semibold">
                        {reconciliation.number}
                        <span className="text-content-muted ml-2 text-xs font-normal">
                            {reconciliation.period_start} to {reconciliation.period_end}
                        </span>
                    </h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        {reconciliation.status_label}
                    </p>
                </div>

                <Badge tone={reconciled ? 'success' : 'warning'} dot>
                    {reconciled ? 'Reconciles to zero' : 'Not reconciled yet'}
                </Badge>
            </div>

            <div
                className={cn(
                    'rounded-md border p-4',
                    reconciled
                        ? 'border-success bg-success-subtle'
                        : 'border-warning bg-warning-subtle',
                )}
            >
                <div className="flex items-start gap-3">
                    {reconciled ? (
                        <CheckCircle2 className="text-success mt-0.5 size-5" aria-hidden="true" />
                    ) : (
                        <TriangleAlert className="text-warning mt-0.5 size-5" aria-hidden="true" />
                    )}
                    <div>
                        <p className="text-content text-2xl font-semibold tabular-nums">
                            {formatMoney(figures.difference, { currency })}
                        </p>
                        <p className="text-content-secondary text-xs">
                            {reconciled
                                ? 'Everything on the statement is explained, and the books agree.'
                                : 'A difference is unfinished work, not a rounding to accept: something on the statement is not in the books, something in the books never reached the bank, or a match is wrong.'}
                        </p>
                    </div>
                </div>
            </div>

            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Figure label="Opening (bank)" value={figures.opening} currency={currency} />
                <Figure label="Cleared by us" value={figures.cleared} currency={currency} />
                <Figure label="Closing (bank)" value={figures.closing} currency={currency} />
                <Figure
                    label="Ledger balance at period end"
                    value={figures.ledger_balance}
                    currency={currency}
                />
            </dl>

            <div className="border-line-subtle grid gap-3 border-t pt-3 sm:grid-cols-2">
                <div>
                    <p className="text-content-secondary text-xs font-medium">
                        Statement lines still unexplained
                    </p>
                    <p className="text-content mt-0.5 text-sm tabular-nums">
                        {figures.unmatched_count === 0
                            ? 'None — every line is matched or excluded.'
                            : `${figures.unmatched_count} worth ${formatMoney(
                                  figures.unmatched_total,
                                  { currency },
                              )}`}
                    </p>
                    {figures.unmatched_count > 0 && (
                        <Button
                            className="mt-2"
                            size="sm"
                            variant="secondary"
                            onClick={() =>
                                router.get('/banking/transactions', {
                                    account: accountId,
                                    status: 'unmatched',
                                })
                            }
                        >
                            Match them
                        </Button>
                    )}
                </div>

                <div>
                    <p className="text-content-secondary text-xs font-medium">
                        Ours the bank has not seen
                    </p>
                    <p className="text-content mt-0.5 text-sm tabular-nums">
                        {figures.unpresented_count === 0
                            ? 'None outstanding.'
                            : `${figures.unpresented_count} worth ${formatMoney(
                                  figures.unpresented_total,
                                  { currency },
                              )}`}
                    </p>
                    <p className="text-content-muted mt-1 text-xs">
                        Unpresented cheques and payments in transit. These are the difference
                        between the ledger balance and what the bank shows, and they are expected
                        rather than a problem.
                    </p>
                </div>
            </div>

            {can.reconcile && (
                <div className="border-line-subtle flex flex-wrap items-center gap-2 border-t pt-3">
                    <Button
                        variant="primary"
                        disabled={!reconciled || complete.processing}
                        loading={complete.processing}
                        icon={<CheckCircle2 aria-hidden="true" />}
                        onClick={() =>
                            complete.post(`/banking/reconciliation/${reconciliation.id}/complete`, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Complete this reconciliation
                    </Button>

                    <Button
                        variant="ghost"
                        onClick={() => {
                            if (
                                window.confirm(
                                    `Abandon ${reconciliation.number}? The matches made during it are kept — only the reconciliation itself goes.`,
                                )
                            ) {
                                abandon.delete(`/banking/reconciliation/${reconciliation.id}`, {
                                    preserveScroll: true,
                                });
                            }
                        }}
                    >
                        Abandon
                    </Button>

                    <p className="text-content-muted text-xs">
                        Completing freezes every line in the period. Corrections after that are made
                        forward, in a later period.
                    </p>
                </div>
            )}
        </Card>
    );
}

function Figure({ label, value, currency }: { label: string; value: string; currency: string }) {
    return (
        <div>
            <dt className="text-content-muted text-xs">{label}</dt>
            <dd
                className={cn(
                    'text-content mt-0.5 text-sm font-medium tabular-nums',
                    isNegative(value) && 'text-danger',
                )}
            >
                {formatMoney(value, { currency })}
            </dd>
        </div>
    );
}

function StartForm({
    account,
    today,
    baseCurrency,
    can,
}: {
    account: NonNullable<ReconciliationProps['account']>;
    today: string;
    baseCurrency: string;
    can: ReconciliationProps['can'];
}) {
    const form = useForm({
        bank_account_id: account.id,
        period_start: '',
        period_end: today,
        closing_balance: '',
        opening_balance: account.reconciled_through === null ? '0' : account.suggested_opening,
        notes: '',
    });

    if (!can.reconcile) {
        return (
            <Card>
                <h2 className="text-content text-sm font-semibold">Nothing in progress</h2>
                <p className="text-content-muted mt-1 text-xs">
                    Reconciling needs the reconcile permission. Importing a statement does not —
                    that part changes nothing and anybody with import access can do it.
                </p>
            </Card>
        );
    }

    return (
        <Card>
            <form
                className="flex flex-col gap-4"
                onSubmit={(event: SyntheticEvent) => {
                    event.preventDefault();
                    form.post('/banking/reconciliation', { preserveScroll: true });
                }}
            >
                <div>
                    <h2 className="text-content text-md font-semibold">Start a reconciliation</h2>
                    <p className="text-content-muted mt-0.5 text-xs">
                        Take the period and the closing balance from the statement itself.
                        {account.reconciled_through !== null &&
                            ` The opening balance is carried from the last one, at ${account.reconciled_through}.`}
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        type="date"
                        label="Period from"
                        value={form.data.period_start}
                        onChange={(event) => form.setData('period_start', event.target.value)}
                        error={form.errors.period_start}
                    />
                    <Input
                        type="date"
                        label="Period to"
                        value={form.data.period_end}
                        onChange={(event) => form.setData('period_end', event.target.value)}
                        error={form.errors.period_end}
                    />
                    <Input
                        numeric
                        inputMode="decimal"
                        label="Opening balance"
                        prefix={account.currency}
                        value={form.data.opening_balance}
                        onChange={(event) => form.setData('opening_balance', event.target.value)}
                        error={form.errors.opening_balance}
                        disabled={account.reconciled_through !== null}
                        hint={
                            account.reconciled_through === null
                                ? 'What the statement says the balance was at the start.'
                                : 'Carried from the last reconciliation.'
                        }
                    />
                    <Input
                        numeric
                        inputMode="decimal"
                        label="Closing balance"
                        prefix={account.currency}
                        value={form.data.closing_balance}
                        onChange={(event) => form.setData('closing_balance', event.target.value)}
                        error={form.errors.closing_balance}
                        hint="Exactly as the statement shows it."
                    />
                </div>

                {account.currency !== baseCurrency && (
                    <p className="text-content-muted text-xs">
                        This account is held in {account.currency}, so both balances are in{' '}
                        {account.currency}.
                    </p>
                )}

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Start
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function History({ history, currency }: { history: ReconciliationRow[]; currency: string }) {
    if (history.length === 0) {
        return null;
    }

    return (
        <Card flush>
            <div className="border-line-subtle border-b px-4 py-3">
                <h2 className="text-content text-sm font-semibold">Completed</h2>
                <p className="text-content-muted text-xs">
                    Each of these is a record: its lines and matches can no longer be changed.
                </p>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[36rem] text-sm">
                    <thead className="border-line-subtle text-content-muted border-b text-xs">
                        <tr>
                            <th className="px-4 py-2 text-left font-medium">Number</th>
                            <th className="px-4 py-2 text-left font-medium">Period</th>
                            <th className="px-4 py-2 text-right font-medium">Closing</th>
                            <th className="px-4 py-2 text-right font-medium">Difference</th>
                            <th className="px-4 py-2 text-left font-medium">Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        {history.map((row) => (
                            <tr key={row.id} className="border-line-subtle border-b last:border-0">
                                <td className="text-content px-4 py-2 font-medium">{row.number}</td>
                                <td className="text-content-secondary px-4 py-2 whitespace-nowrap tabular-nums">
                                    {row.period_start} – {row.period_end}
                                </td>
                                <td className="text-content px-4 py-2 text-right tabular-nums">
                                    {formatMoney(row.closing_balance, { currency })}
                                </td>
                                <td className="text-content px-4 py-2 text-right tabular-nums">
                                    {formatMoney(row.difference, { currency })}
                                </td>
                                <td className="text-content-muted px-4 py-2 whitespace-nowrap">
                                    {row.completed_at ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}
