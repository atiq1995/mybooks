import type { SyntheticEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Lock, Save } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';

interface LockedField {
    locked: boolean;
    reason: string;
}

interface OrganizationProps {
    organization: {
        id: string;
        name: string;
        slug: string;
        legal_name: string | null;
        base_currency: string;
        country_code: string;
        jurisdiction: string;
        fiscal_year_start_month: number;
        rounding_mode: string;
        tax_registration_number: string | null;
        sales_tax_registration_number: string | null;
        business_registration_number: string | null;
        timezone: string;
        locale: string;
        date_format: string;
        address: Record<string, string> | null;
        phone: string | null;
        email: string | null;
        website: string | null;
    };
    locked: {
        base_currency: LockedField;
        fiscal_year_start_month: LockedField;
    };
    countries: { value: string; label: string; fiscal_year_start: number }[];
    timezones: { value: string; label: string }[];
    can: { update: boolean };
}

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/**
 * The organisation's own details.
 *
 * The screen's real work is the two locked fields. Base currency can never
 * change, and the financial year stops being movable the moment anything
 * posts — so both are shown, both are disabled, and each carries the reason
 * beside it rather than in a paragraph nobody reads.
 *
 * A disabled field with an explanation beats a field that accepts a change
 * and then refuses it, and beats by a long way one that accepts it.
 */
export default function OrganizationSettings({
    organization,
    locked,
    countries,
    timezones,
    can,
}: OrganizationProps) {
    const form = useForm({
        name: organization.name,
        legal_name: organization.legal_name ?? '',
        country_code: organization.country_code,
        fiscal_year_start_month: String(organization.fiscal_year_start_month),
        rounding_mode: organization.rounding_mode,
        tax_registration_number: organization.tax_registration_number ?? '',
        sales_tax_registration_number: organization.sales_tax_registration_number ?? '',
        business_registration_number: organization.business_registration_number ?? '',
        timezone: organization.timezone,
        locale: organization.locale,
        date_format: organization.date_format,
        address: {
            line1: organization.address?.line1 ?? '',
            line2: organization.address?.line2 ?? '',
            city: organization.address?.city ?? '',
            state: organization.address?.state ?? '',
            postal_code: organization.address?.postal_code ?? '',
            country: organization.address?.country ?? '',
        },
        phone: organization.phone ?? '',
        email: organization.email ?? '',
        website: organization.website ?? '',
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.transform((data) => {
            if (!locked.fiscal_year_start_month.locked) {
                return data;
            }

            /*
             * Not sent at all where it is locked: the server prohibits the
             * field, and sending it would produce an error about something
             * the form already knows it cannot change.
             *
             * Built by copying rather than destructuring away, because the
             * discarded binding reads as dead code to a linter that cannot
             * tell omission from oversight.
             */
            const sent: Record<string, unknown> = { ...data };
            delete sent.fiscal_year_start_month;

            return sent;
        });

        form.patch('/settings/organization', { preserveScroll: true });
    };

    const setAddress = (field: keyof typeof form.data.address, value: string) => {
        form.setData('address', { ...form.data.address, [field]: value });
    };

    return (
        <AppLayout
            title="Organisation"
            description="The company's own details — the ones that appear on every document it issues."
            breadcrumbs={[{ label: 'Settings' }, { label: 'Organisation' }]}
        >
            <Head title="Organisation settings" />

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">Who you are</h2>
                        <p className="text-content-muted text-xs">
                            The legal name goes on invoices and tax returns; the name is what you
                            call yourself day to day.
                        </p>
                    </header>

                    <div className="grid gap-4 p-4 sm:grid-cols-2">
                        <Input
                            label="Name"
                            name="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            error={form.errors.name}
                            disabled={!can.update}
                            required
                        />

                        <Input
                            label="Legal name"
                            name="legal_name"
                            value={form.data.legal_name}
                            onChange={(e) => form.setData('legal_name', e.target.value)}
                            error={form.errors.legal_name}
                            disabled={!can.update}
                            hint="As registered. Left blank, the name above is used."
                            optional
                        />

                        <Input
                            label="NTN"
                            name="tax_registration_number"
                            value={form.data.tax_registration_number}
                            onChange={(e) =>
                                form.setData('tax_registration_number', e.target.value)
                            }
                            error={form.errors.tax_registration_number}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="STRN"
                            name="sales_tax_registration_number"
                            value={form.data.sales_tax_registration_number}
                            onChange={(e) =>
                                form.setData('sales_tax_registration_number', e.target.value)
                            }
                            error={form.errors.sales_tax_registration_number}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="Company registration"
                            name="business_registration_number"
                            value={form.data.business_registration_number}
                            onChange={(e) =>
                                form.setData('business_registration_number', e.target.value)
                            }
                            error={form.errors.business_registration_number}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="Identifier"
                            name="slug"
                            value={organization.slug}
                            onChange={() => undefined}
                            disabled
                            hint="Used in URLs. Fixed once created, so existing links keep working."
                        />
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">Accounting decisions</h2>
                        <p className="text-content-muted text-xs">
                            Two of these cannot be undone, and each says why.
                        </p>
                    </header>

                    <div className="grid gap-4 p-4 sm:grid-cols-2">
                        {/*
                         * Shown and locked rather than hidden. Somebody
                         * looking for the base currency needs to find it and
                         * learn that it is settled — not conclude the screen
                         * is incomplete.
                         */}
                        <LockedInput
                            label="Base currency"
                            name="base_currency"
                            value={organization.base_currency}
                            reason={locked.base_currency.reason}
                        />

                        {locked.fiscal_year_start_month.locked ? (
                            <LockedInput
                                label="Financial year starts"
                                name="fiscal_year_start_month"
                                value={MONTHS[organization.fiscal_year_start_month - 1] ?? ''}
                                reason={locked.fiscal_year_start_month.reason}
                            />
                        ) : (
                            <Select
                                label="Financial year starts"
                                name="fiscal_year_start_month"
                                value={form.data.fiscal_year_start_month}
                                onChange={(e) =>
                                    form.setData('fiscal_year_start_month', e.target.value)
                                }
                                error={form.errors.fiscal_year_start_month}
                                disabled={!can.update}
                                options={MONTHS.map((month, index) => ({
                                    value: String(index + 1),
                                    label: month,
                                }))}
                                hint={locked.fiscal_year_start_month.reason}
                            />
                        )}

                        <Select
                            label="Country"
                            name="country_code"
                            value={form.data.country_code}
                            onChange={(e) => form.setData('country_code', e.target.value)}
                            error={form.errors.country_code}
                            disabled={!can.update}
                            options={countries.map((country) => ({
                                value: country.value,
                                label: country.label,
                            }))}
                            hint="Decides which tax defaults and labels apply."
                            required
                        />

                        <Select
                            label="Rounding"
                            name="rounding_mode"
                            value={form.data.rounding_mode}
                            onChange={(e) => form.setData('rounding_mode', e.target.value)}
                            error={form.errors.rounding_mode}
                            disabled={!can.update}
                            options={[
                                { value: 'HALF_UP', label: 'Half up (0.5 rounds away from zero)' },
                                {
                                    value: 'HALF_EVEN',
                                    label: 'Half even (banker’s rounding)',
                                },
                                { value: 'HALF_DOWN', label: 'Half down (0.5 rounds to zero)' },
                            ]}
                            hint="Applies to new documents. Anything already posted keeps the figures it posted with."
                            required
                        />
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">Where you are</h2>
                        <p className="text-content-muted text-xs">
                            The address on your documents, and the clock the books run on.
                        </p>
                    </header>

                    <div className="grid gap-4 p-4 sm:grid-cols-2">
                        <Input
                            label="Address line 1"
                            name="address.line1"
                            value={form.data.address.line1}
                            onChange={(e) => setAddress('line1', e.target.value)}
                            error={form.errors['address.line1']}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="Address line 2"
                            name="address.line2"
                            value={form.data.address.line2}
                            onChange={(e) => setAddress('line2', e.target.value)}
                            error={form.errors['address.line2']}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="City"
                            name="address.city"
                            value={form.data.address.city}
                            onChange={(e) => setAddress('city', e.target.value)}
                            error={form.errors['address.city']}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="Province or state"
                            name="address.state"
                            value={form.data.address.state}
                            onChange={(e) => setAddress('state', e.target.value)}
                            error={form.errors['address.state']}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="Postal code"
                            name="address.postal_code"
                            value={form.data.address.postal_code}
                            onChange={(e) => setAddress('postal_code', e.target.value)}
                            error={form.errors['address.postal_code']}
                            disabled={!can.update}
                            optional
                        />

                        <Select
                            label="Time zone"
                            name="timezone"
                            value={form.data.timezone}
                            onChange={(e) => form.setData('timezone', e.target.value)}
                            error={form.errors.timezone}
                            disabled={!can.update}
                            options={timezones}
                            hint="Decides what “today” means when a document is dated."
                            required
                        />

                        <Input
                            label="Date format"
                            name="date_format"
                            value={form.data.date_format}
                            onChange={(e) => form.setData('date_format', e.target.value)}
                            error={form.errors.date_format}
                            disabled={!can.update}
                            hint="How dates are printed, e.g. d M Y."
                            required
                        />

                        <Input
                            label="Locale"
                            name="locale"
                            value={form.data.locale}
                            onChange={(e) => form.setData('locale', e.target.value)}
                            error={form.errors.locale}
                            disabled={!can.update}
                            required
                        />
                    </div>
                </Card>

                <Card flush>
                    <header className="border-line-subtle border-b px-4 py-3">
                        <h2 className="text-content text-md font-semibold">How to reach you</h2>
                    </header>

                    <div className="grid gap-4 p-4 sm:grid-cols-3">
                        <Input
                            label="Phone"
                            name="phone"
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                            error={form.errors.phone}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="Email"
                            name="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            error={form.errors.email}
                            disabled={!can.update}
                            optional
                        />

                        <Input
                            label="Website"
                            name="website"
                            value={form.data.website}
                            onChange={(e) => form.setData('website', e.target.value)}
                            error={form.errors.website}
                            disabled={!can.update}
                            placeholder="https://"
                            optional
                        />
                    </div>
                </Card>

                {can.update && (
                    <div className="flex items-center justify-end">
                        <Button
                            type="submit"
                            variant="primary"
                            size="md"
                            loading={form.processing}
                            icon={<Save aria-hidden="true" />}
                        >
                            Save changes
                        </Button>
                    </div>
                )}
            </form>
        </AppLayout>
    );
}

/**
 * A field that is shown, disabled, and explained.
 *
 * Its own component because the explanation is the point: a locked field
 * without one reads as a bug, and a locked field that simply is not there
 * reads as an unfinished screen.
 */
function LockedInput({
    label,
    name,
    value,
    reason,
}: {
    label: string;
    name: string;
    value: string;
    reason: string;
}) {
    return (
        <div>
            <Input
                label={label}
                name={name}
                value={value}
                onChange={() => undefined}
                disabled
                suffix={<Lock className="text-content-muted size-3.5" aria-hidden="true" />}
            />
            <p className="text-content-muted mt-1 text-xs">{reason}</p>
        </div>
    );
}
