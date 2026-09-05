import { useMemo } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Building2, Info } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { Card } from '@ui/Card';

interface CountryOption {
    value: string;
    label: string;
    currency: string;
    fiscalMonth: number;
}

interface Option {
    value: string;
    label: string;
}

interface MonthOption {
    value: number;
    label: string;
}

interface CreateOrganizationProps {
    options: {
        countries: CountryOption[];
        currencies: Option[];
        timezones: Option[];
        months: MonthOption[];
        defaults: {
            country_code: string;
            base_currency: string;
            timezone: string;
            fiscal_year_start_month: number;
        };
    };
}

/**
 * Create a new set of books.
 *
 * Collects only the decisions that are hard to undo — base currency and the
 * financial year — and says so plainly. Everything else is gathered by the
 * setup wizard afterwards, where it can be changed freely.
 */
export default function CreateOrganization({ options }: CreateOrganizationProps) {
    const { defaults } = options;

    const form = useForm({
        name: '',
        legal_name: '',
        country_code: defaults.country_code,
        base_currency: defaults.base_currency,
        fiscal_year_start_month: defaults.fiscal_year_start_month,
        timezone: defaults.timezone,
    });

    const countryOptions = useMemo(
        () => options.countries.map(({ value, label }) => ({ value, label })),
        [options.countries],
    );

    /**
     * Choosing a country moves the currency and financial year with it — the
     * common case is that all three agree, and someone setting up in Pakistan
     * should not have to know their tax year starts in July.
     */
    const onCountryChange = (code: string) => {
        const country = options.countries.find((c) => c.value === code);

        form.setData((current) => ({
            ...current,
            country_code: code,
            base_currency: country?.currency ?? current.base_currency,
            fiscal_year_start_month: country?.fiscalMonth ?? current.fiscal_year_start_month,
        }));
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/organizations');
    };

    const fiscalMonthLabel =
        options.months.find((m) => m.value === form.data.fiscal_year_start_month)?.label ?? '';

    return (
        <AppLayout
            title="New organisation"
            description="A separate set of books, with its own ledger, contacts and documents."
        >
            <Head title="New organisation" />

            <form onSubmit={submit} className="max-w-2xl">
                <Card flush>
                    <div className="border-line-subtle flex items-center gap-2.5 border-b px-4 py-3">
                        <span className="bg-brand-subtle flex size-7 items-center justify-center rounded-md">
                            <Building2 className="text-brand-text size-4" aria-hidden="true" />
                        </span>
                        <div>
                            <h2 className="text-content text-md font-semibold">
                                Business information
                            </h2>
                            <p className="text-content-muted text-xs">
                                You can change all of this later, except where noted.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-col gap-4 p-4">
                        <Input
                            label="Business name"
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            error={form.errors.name}
                            hint="How this business appears throughout the application."
                            required
                        />

                        <Input
                            label="Legal name"
                            name="legal_name"
                            value={form.data.legal_name}
                            onChange={(event) => form.setData('legal_name', event.target.value)}
                            error={form.errors.legal_name}
                            hint="The registered name, if it differs. Used on invoices and statements."
                            optional
                        />

                        <Select
                            label="Country"
                            name="country_code"
                            value={form.data.country_code}
                            onChange={(event) => onCountryChange(event.target.value)}
                            error={form.errors.country_code}
                            options={countryOptions}
                            hint="Sets the tax rules, address format and sensible defaults below."
                            required
                        />

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Select
                                label="Base currency"
                                name="base_currency"
                                value={form.data.base_currency}
                                onChange={(event) =>
                                    form.setData('base_currency', event.target.value)
                                }
                                error={form.errors.base_currency}
                                options={options.currencies}
                                required
                            />

                            <Select
                                label="Financial year starts in"
                                name="fiscal_year_start_month"
                                value={form.data.fiscal_year_start_month}
                                onChange={(event) =>
                                    form.setData(
                                        'fiscal_year_start_month',
                                        Number(event.target.value),
                                    )
                                }
                                error={form.errors.fiscal_year_start_month}
                                options={options.months}
                                required
                            />
                        </div>

                        <Select
                            label="Time zone"
                            name="timezone"
                            value={form.data.timezone}
                            onChange={(event) => form.setData('timezone', event.target.value)}
                            error={form.errors.timezone}
                            options={options.timezones}
                            hint="Document dates are shown in this zone. Everything is stored in UTC."
                            required
                        />

                        {/* The one genuinely irreversible choice on this form,
                            stated where the decision is made rather than buried
                            in documentation. */}
                        <div className="border-status-info-line bg-status-info text-status-info-fg flex items-start gap-2 rounded-md border p-3">
                            <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <p className="text-xs">
                                The <strong>base currency</strong> is permanent. Every journal entry
                                stores its value in this currency at the moment it is posted, so it
                                cannot be changed once you begin. The financial year can be adjusted
                                until your first posting.
                            </p>
                        </div>
                    </div>

                    <div className="border-line-subtle bg-surface-sunken flex items-center justify-between gap-3 border-t px-4 py-3">
                        <p className="text-content-muted text-xs">
                            You will be the owner of {form.data.name.trim() || 'this organisation'}
                            {fiscalMonthLabel !== '' &&
                                `, with a financial year from ${fiscalMonthLabel}`}
                            .
                        </p>

                        <Button
                            type="submit"
                            variant="primary"
                            size="md"
                            loading={form.processing}
                            disabled={form.data.name.trim() === ''}
                        >
                            Create organisation
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
