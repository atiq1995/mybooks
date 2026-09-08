import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowRightLeft, Plus, Scale } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { formatMoney, isNegative, sumForDisplay } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Rate {
    id: string;
    from_currency: string;
    to_currency: string;
    rate: string;
    effective_on: string;
    source: string;
}

interface Adjustment {
    account_id: string;
    code: string;
    name: string;
    currency: string;
    rate: string;
    foreign_balance: string;
    carrying_value: string;
    revalued_to: string;
    difference: string;
}

interface CurrenciesProps {
    baseCurrency: string;
    rates: Rate[];
    foreignCurrencies: string[];
    revaluation: { as_of: string; adjustments: Adjustment[]; error: string | null };
    filters: { as_of: string };
    can: { manage_rates: boolean; revalue: boolean };
}

/**
 * Exchange rates, and the period-end revaluation that uses them.
 *
 * The revaluation is shown before it posts — account by account, with the rate
 * applied and the difference it makes. An adjustment that appears with no
 * explanation is an adjustment nobody can check, and this one lands on the
 * balance sheet.
 */
export default function Currencies({
    baseCurrency,
    rates,
    foreignCurrencies,
    revaluation,
    filters,
    can,
}: CurrenciesProps) {
    const [adding, setAdding] = useState(false);

    const setAsOf = (as_of: string) => {
        router.get(
            '/accounting/currencies',
            { as_of },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const revalue = () => {
        if (
            !window.confirm(
                `Revalue foreign balances at ${filters.as_of}?\n\n` +
                    'Two entries are posted: the adjustment on that date, and its reversal on ' +
                    'the following day. The gain or loss is unrealised — nothing has been ' +
                    'settled — so it belongs only to the period being reported.',
            )
        ) {
            return;
        }

        router.post(
            '/accounting/currencies/revalue',
            { as_of: filters.as_of },
            { preserveScroll: true },
        );
    };

    // Display only — the authoritative figure is the FX line the server
    // posts, which this page never sees before it exists.
    const netDifference = sumForDisplay(revaluation.adjustments.map((a) => a.difference));
    const netIsLoss = isNegative(netDifference);

    return (
        <AppLayout
            title="Currencies"
            description={`Rates against ${baseCurrency}, and the period-end revaluation of balances held in another currency.`}
            breadcrumbs={[{ label: 'Accounting' }, { label: 'Currencies' }]}
            actions={
                can.manage_rates && !adding ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => setAdding(true)}
                    >
                        Record a rate
                    </Button>
                ) : undefined
            }
        >
            <Head title="Currencies" />

            {adding && can.manage_rates && (
                <RateForm
                    baseCurrency={baseCurrency}
                    foreignCurrencies={foreignCurrencies}
                    onClose={() => setAdding(false)}
                />
            )}

            <Card flush className="mb-4">
                <header className="border-line-subtle flex flex-wrap items-end justify-between gap-3 border-b px-4 py-3">
                    <div>
                        <h2 className="text-content text-md font-semibold">
                            Period-end revaluation
                        </h2>
                        <p className="text-content-muted text-xs">
                            What each foreign balance is worth in {baseCurrency} on this date,
                            against what it is currently carried at.
                        </p>
                    </div>

                    <div className="flex items-end gap-2">
                        <Input
                            label="As at"
                            type="date"
                            value={filters.as_of}
                            onChange={(e) => setAsOf(e.target.value)}
                            containerClassName="w-40"
                        />

                        {can.revalue && revaluation.adjustments.length > 0 && (
                            <Button
                                variant="secondary"
                                size="md"
                                icon={<Scale aria-hidden="true" />}
                                onClick={revalue}
                            >
                                Post the revaluation
                            </Button>
                        )}
                    </div>
                </header>

                {revaluation.error !== null ? (
                    <p
                        role="alert"
                        className="border-line-danger bg-status-danger text-status-danger-fg flex items-start gap-2 px-4 py-3 text-sm"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        {revaluation.error}
                    </p>
                ) : revaluation.adjustments.length === 0 ? (
                    <EmptyState
                        icon={ArrowRightLeft}
                        title="Nothing to revalue"
                        description={
                            foreignCurrencies.length === 0
                                ? `Every account is held in ${baseCurrency}, so rates never change what the balance sheet says.`
                                : `Each foreign balance is already carried at the rate for ${filters.as_of}.`
                        }
                    />
                ) : (
                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Account</th>
                                    <th className="px-4 py-2 text-right font-medium">Balance</th>
                                    <th className="px-4 py-2 text-right font-medium">Rate</th>
                                    <th className="px-4 py-2 text-right font-medium">Carried at</th>
                                    <th className="px-4 py-2 text-right font-medium">Worth</th>
                                    <th className="px-4 py-2 text-right font-medium">Adjustment</th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {revaluation.adjustments.map((a) => (
                                    <tr key={a.account_id} className="hover:bg-surface-hover">
                                        <td className="px-4 py-2.5">
                                            <span className="text-content font-medium tabular-nums">
                                                {a.code}
                                            </span>
                                            <span className="text-content-secondary ml-2">
                                                {a.name}
                                            </span>
                                        </td>

                                        <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                            {formatMoney(a.foreign_balance, {
                                                currency: a.currency,
                                            })}
                                        </td>

                                        <td className="text-content-muted px-4 py-2.5 text-right tabular-nums">
                                            {a.rate}
                                        </td>

                                        <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                            {formatMoney(a.carrying_value, {
                                                currency: baseCurrency,
                                                showCurrency: false,
                                            })}
                                        </td>

                                        <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                            {formatMoney(a.revalued_to, {
                                                currency: baseCurrency,
                                                showCurrency: false,
                                            })}
                                        </td>

                                        <td
                                            className={cn(
                                                'px-4 py-2.5 text-right font-medium tabular-nums',
                                                isNegative(a.difference)
                                                    ? 'text-danger-600 dark:text-danger-400'
                                                    : 'text-success-700 dark:text-success-400',
                                            )}
                                        >
                                            {formatMoney(a.difference, {
                                                currency: baseCurrency,
                                                showCurrency: false,
                                                signDisplay: 'always',
                                            })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>

                            <tfoot>
                                <tr className="border-line-subtle bg-surface-sunken text-content border-t-2 font-medium">
                                    <td className="px-4 py-2.5" colSpan={5}>
                                        Unrealised {netIsLoss ? 'loss' : 'gain'}
                                        <span className="text-content-muted ml-2 text-xs font-normal">
                                            posted to FX gain/loss, reversed the next day
                                        </span>
                                    </td>
                                    <td
                                        className={cn(
                                            'px-4 py-2.5 text-right tabular-nums',
                                            netIsLoss
                                                ? 'text-danger-600 dark:text-danger-400'
                                                : 'text-success-700 dark:text-success-400',
                                        )}
                                    >
                                        {formatMoney(netDifference, {
                                            currency: baseCurrency,
                                            showCurrency: false,
                                            signDisplay: 'always',
                                        })}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </Card>

            <Card flush>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Recorded rates</h2>
                    <p className="text-content-muted text-xs">
                        Conversion uses the latest rate on or before the document&rsquo;s date —
                        never the newest rate available. Correcting a rate does not restate anything
                        already posted.
                    </p>
                </header>

                {rates.length === 0 ? (
                    <EmptyState
                        icon={ArrowRightLeft}
                        title="No rates recorded"
                        description="Record a rate before posting anything in another currency. A rate is never guessed."
                        action={
                            can.manage_rates ? (
                                <Button variant="primary" size="sm" onClick={() => setAdding(true)}>
                                    Record a rate
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Pair</th>
                                    <th className="px-4 py-2 text-right font-medium">Rate</th>
                                    <th className="px-4 py-2 text-left font-medium">From</th>
                                    <th className="px-4 py-2 text-left font-medium">Source</th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {rates.map((rate) => (
                                    <tr key={rate.id} className="hover:bg-surface-hover">
                                        <td className="text-content px-4 py-2 font-medium">
                                            {rate.from_currency} → {rate.to_currency}
                                        </td>
                                        <td className="text-content px-4 py-2 text-right tabular-nums">
                                            {rate.rate}
                                        </td>
                                        <td className="text-content-secondary px-4 py-2 tabular-nums">
                                            {rate.effective_on}
                                        </td>
                                        <td className="px-4 py-2">
                                            <Badge
                                                tone={rate.source === 'manual' ? 'neutral' : 'info'}
                                            >
                                                {rate.source}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Card>
        </AppLayout>
    );
}

function RateForm({
    baseCurrency,
    foreignCurrencies,
    onClose,
}: {
    baseCurrency: string;
    foreignCurrencies: string[];
    onClose: () => void;
}) {
    const form = useForm({
        from_currency: foreignCurrencies[0] ?? '',
        to_currency: baseCurrency,
        rate: '',
        effective_on: new Date().toISOString().slice(0, 10),
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/accounting/currencies/rates', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('rate');
                onClose();
            },
        });
    };

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Record a rate</h2>
                    <p className="text-content-muted text-xs">
                        Read it as &ldquo;1 of the first currency buys this many of the
                        second&rdquo;. Recording the same pair and date again corrects it rather
                        than adding a second answer.
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-4">
                    {foreignCurrencies.length > 0 ? (
                        <Select
                            label="From"
                            value={form.data.from_currency}
                            onChange={(e) => form.setData('from_currency', e.target.value)}
                            error={form.errors.from_currency}
                            options={foreignCurrencies.map((c) => ({ value: c, label: c }))}
                            required
                        />
                    ) : (
                        <Input
                            label="From"
                            value={form.data.from_currency}
                            onChange={(e) => form.setData('from_currency', e.target.value)}
                            error={form.errors.from_currency}
                            placeholder="USD"
                            required
                        />
                    )}

                    <Input
                        label="To"
                        value={form.data.to_currency}
                        onChange={(e) => form.setData('to_currency', e.target.value)}
                        error={form.errors.to_currency}
                        hint={`Usually ${baseCurrency}`}
                        required
                    />

                    <Input
                        label="Rate"
                        // Text, not number: a number input hands back a float,
                        // and a rate carries ten decimal places.
                        inputMode="decimal"
                        numeric
                        value={form.data.rate}
                        onChange={(e) => form.setData('rate', e.target.value)}
                        error={form.errors.rate}
                        placeholder="278.5000000000"
                        required
                    />

                    <Input
                        label="Effective from"
                        type="date"
                        value={form.data.effective_on}
                        onChange={(e) => form.setData('effective_on', e.target.value)}
                        error={form.errors.effective_on}
                        hint="Applies until a later rate exists."
                        required
                    />
                </div>

                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="ghost" size="md" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="md"
                        loading={form.processing}
                        icon={<ArrowRightLeft aria-hidden="true" />}
                    >
                        Record the rate
                    </Button>
                </footer>
            </form>
        </Card>
    );
}
