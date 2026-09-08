import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Car, Plus, Save } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { formatMoney } from '@/Utils/money';

interface Rate {
    id: string;
    name: string;
    unit: string;
    rate: string;
    effective_from: string;
    effective_to: string | null;
    is_current: boolean;
    is_default: boolean;
}

interface MileageRatesProps {
    rates: Rate[];
    baseCurrency: string;
    today: string;
    can: { manage: boolean };
}

/**
 * Mileage rates, and their history.
 *
 * The history is the screen. A rate change is a new rate from a date, and the
 * old one is closed the day before — never edited — so a claim made in March
 * keeps reading as March's rate for ever. Showing the whole chain is what
 * makes that legible: somebody looking at an old claim can see which rate it
 * used and why.
 */
export default function MileageRates({ rates, baseCurrency, today, can }: MileageRatesProps) {
    const [adding, setAdding] = useState(false);

    const current = rates.filter((rate) => rate.is_current);
    const history = rates.filter((rate) => !rate.is_current);

    return (
        <AppLayout
            title="Mileage rates"
            description={`What a kilometre or a mile is worth, and from when. Amounts in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Settings' }, { label: 'Mileage' }]}
            actions={
                can.manage && !adding ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => setAdding(true)}
                    >
                        Set a rate
                    </Button>
                ) : undefined
            }
        >
            <Head title="Mileage rates" />

            {adding && can.manage && (
                <RateForm today={today} currency={baseCurrency} onClose={() => setAdding(false)} />
            )}

            <Card flush className="mb-4">
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">In force now</h2>
                    <p className="text-content-muted text-xs">
                        One rate per unit. A claim uses the rate in force on its own date, and the
                        figure is copied onto the claim — so changing a rate never restates anything
                        already claimed.
                    </p>
                </header>

                {current.length === 0 ? (
                    <EmptyState
                        icon={Car}
                        title="No mileage rate set"
                        description="Until one exists, a mileage claim is refused rather than guessed at."
                        action={
                            can.manage ? (
                                <Button variant="primary" size="sm" onClick={() => setAdding(true)}>
                                    Set a rate
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <ul className="divide-line-subtle divide-y">
                        {current.map((rate) => (
                            <li
                                key={rate.id}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                            >
                                <div>
                                    <p className="text-content text-sm font-medium">
                                        {rate.name}
                                        <span className="text-content-muted ml-2 text-xs">
                                            per {rate.unit}
                                        </span>
                                    </p>
                                    <p className="text-content-muted text-xs tabular-nums">
                                        from {rate.effective_from}
                                    </p>
                                </div>

                                <p className="text-content text-md font-semibold tabular-nums">
                                    {formatMoney(rate.rate, { currency: baseCurrency })}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {history.length > 0 && (
                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">Previously</h2>
                        <p className="text-content-muted text-xs">
                            Kept, not deleted. A claim from one of these periods still reads at the
                            rate it was made at.
                        </p>
                    </header>

                    <div className="table-scroll">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                    <th className="px-4 py-2 text-left font-medium">Name</th>
                                    <th className="px-4 py-2 text-left font-medium">Unit</th>
                                    <th className="px-4 py-2 text-left font-medium">From</th>
                                    <th className="px-4 py-2 text-left font-medium">Until</th>
                                    <th className="px-4 py-2 text-right font-medium">Rate</th>
                                </tr>
                            </thead>

                            <tbody className="divide-line-subtle divide-y">
                                {history.map((rate) => (
                                    <tr key={rate.id} className="hover:bg-surface-hover">
                                        <td className="text-content px-4 py-2.5">{rate.name}</td>
                                        <td className="text-content-secondary px-4 py-2.5">
                                            <Badge tone="neutral">{rate.unit}</Badge>
                                        </td>
                                        <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                            {rate.effective_from}
                                        </td>
                                        <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                            {rate.effective_to ?? '—'}
                                        </td>
                                        <td className="text-content px-4 py-2.5 text-right tabular-nums">
                                            {formatMoney(rate.rate, {
                                                currency: baseCurrency,
                                                showCurrency: false,
                                            })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            )}
        </AppLayout>
    );
}

function RateForm({
    today,
    currency,
    onClose,
}: {
    today: string;
    currency: string;
    onClose: () => void;
}) {
    const form = useForm({
        name: '',
        unit: 'km',
        rate: '',
        effective_from: today,
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/settings/mileage', { onSuccess: onClose });
    };

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Set a mileage rate</h2>
                    <p className="text-content-muted text-xs">
                        The rate in force for this unit is closed the day before this one starts.
                        Claims dated earlier keep the rate they were made at.
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        label="Name"
                        name="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                        placeholder="Standard, 2026–27"
                        required
                    />

                    <Select
                        label="Per"
                        name="unit"
                        value={form.data.unit}
                        onChange={(e) => form.setData('unit', e.target.value)}
                        error={form.errors.unit}
                        options={[
                            { value: 'km', label: 'Kilometre' },
                            { value: 'mi', label: 'Mile' },
                        ]}
                        hint="Part of the rate, not a display choice."
                        required
                    />

                    <Input
                        label="Rate"
                        name="rate"
                        inputMode="decimal"
                        numeric
                        value={form.data.rate}
                        onChange={(e) => form.setData('rate', e.target.value)}
                        error={form.errors.rate}
                        prefix={<span className="text-content-muted text-2xs">{currency}</span>}
                        placeholder="0.00"
                        required
                    />

                    <Input
                        label="From"
                        name="effective_from"
                        type="date"
                        value={form.data.effective_from}
                        onChange={(e) => form.setData('effective_from', e.target.value)}
                        error={form.errors.effective_from}
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
                        icon={<Save aria-hidden="true" />}
                    >
                        Set the rate
                    </Button>
                </footer>
            </form>
        </Card>
    );
}
