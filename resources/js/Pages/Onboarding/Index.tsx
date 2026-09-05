import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Clock, Lock } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Input } from '@ui/Input';
import { Card } from '@ui/Card';
import { Badge } from '@ui/Badge';
import { cn } from '@/Utils/cn';

interface OnboardingOrganization {
    id: string;
    name: string;
    legal_name: string | null;
    base_currency: string;
    country_code: string;
    fiscal_year_start_month: number;
    timezone: string;
    tax_registration_number: string | null;
    sales_tax_registration_number: string | null;
    business_registration_number: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    address: Record<string, string> | null;
    onboarding_complete: boolean;
}

interface OnboardingProps {
    organization: OnboardingOrganization;
    options: { months: { value: number; label: string }[] };
    canInvite: boolean;
}

type StepState = 'done' | 'current' | 'upcoming' | 'deferred';

interface WizardStep {
    title: string;
    hint: string;
    /** Shown but not reachable — the step needs the ledger, which is Phase 2. */
    deferred?: boolean;
}

/**
 * The setup wizard.
 *
 * Shows every step the product will eventually walk through, including the two
 * that need the ledger and therefore arrive in Phase 2. Marking them as
 * upcoming is better than hiding them: the wizard does not change shape under
 * users later, and someone setting up their books can see what is still coming
 * before they rely on it.
 */
export default function Onboarding({ organization, options, canInvite }: OnboardingProps) {
    const [step, setStep] = useState(0);

    const details = useForm({
        legal_name: organization.legal_name ?? '',
        tax_registration_number: organization.tax_registration_number ?? '',
        sales_tax_registration_number: organization.sales_tax_registration_number ?? '',
        business_registration_number: organization.business_registration_number ?? '',
        phone: organization.phone ?? '',
        email: organization.email ?? '',
        website: organization.website ?? '',
        address: {
            line1: organization.address?.line1 ?? '',
            line2: organization.address?.line2 ?? '',
            city: organization.address?.city ?? '',
            state: organization.address?.state ?? '',
            postal_code: organization.address?.postal_code ?? '',
        },
    });

    const isPakistan = organization.country_code === 'PK';

    const fiscalMonth =
        options.months.find((m) => m.value === organization.fiscal_year_start_month)?.label ?? '—';

    const steps: WizardStep[] = [
        { title: 'Business details', hint: 'What appears on your documents' },
        { title: 'Financial year', hint: 'Already set — review it' },
        { title: 'Taxes', hint: 'Arrives with the ledger', deferred: true },
        { title: 'Chart of accounts', hint: 'Arrives with the ledger', deferred: true },
        { title: 'Your team', hint: canInvite ? 'Invite people' : 'Ask an owner to invite people' },
        { title: 'Finish', hint: 'Start using My Books' },
    ];

    const stateOf = (index: number): StepState => {
        if (steps[index]?.deferred === true) return 'deferred';
        if (index < step) return 'done';
        if (index === step) return 'current';
        return 'upcoming';
    };

    const saveDetails = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        details.patch('/onboarding/details', {
            preserveScroll: true,
            onSuccess: () => setStep(1),
        });
    };

    return (
        <AppLayout
            title={`Set up ${organization.name}`}
            description="A few details and you are ready to start. Nothing here is permanent."
        >
            <Head title="Set up your organisation" />

            <div className="grid gap-4 lg:grid-cols-[15rem_1fr]">
                {/* Step rail */}
                <nav aria-label="Setup steps">
                    <ol className="flex flex-col gap-0.5">
                        {steps.map((item, index) => {
                            const state = stateOf(index);
                            const selectable = state !== 'deferred';

                            return (
                                <li key={item.title}>
                                    <button
                                        type="button"
                                        onClick={() => selectable && setStep(index)}
                                        disabled={!selectable}
                                        aria-current={state === 'current' ? 'step' : undefined}
                                        className={cn(
                                            'flex w-full items-start gap-2.5 rounded px-2.5 py-2 text-left transition-colors',
                                            'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-1',
                                            state === 'current' && 'bg-surface-selected',
                                            selectable &&
                                                state !== 'current' &&
                                                'hover:bg-surface-hover',
                                            !selectable && 'cursor-default opacity-60',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border text-[0.6rem] font-medium',
                                                state === 'done' &&
                                                    'border-brand bg-brand text-content-on-brand',
                                                state === 'current' &&
                                                    'border-brand text-brand-text',
                                                state === 'upcoming' &&
                                                    'border-line text-content-muted',
                                                state === 'deferred' &&
                                                    'border-line text-content-muted',
                                            )}
                                        >
                                            {state === 'done' ? (
                                                <Check className="size-2.5" aria-hidden="true" />
                                            ) : state === 'deferred' ? (
                                                <Lock className="size-2.5" aria-hidden="true" />
                                            ) : (
                                                index + 1
                                            )}
                                        </span>

                                        <span className="min-w-0">
                                            <span className="text-content block text-sm font-medium">
                                                {item.title}
                                            </span>
                                            <span className="text-content-muted block text-xs">
                                                {item.hint}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                </nav>

                {/* Step body */}
                <div className="min-w-0">
                    {step === 0 && (
                        <Card flush>
                            <form onSubmit={saveDetails}>
                                <header className="border-line-subtle border-b px-4 py-3">
                                    <h2 className="text-content text-md font-semibold">
                                        Business details
                                    </h2>
                                    <p className="text-content-muted text-xs">
                                        These appear on the invoices and statements your customers
                                        receive. All optional — you can fill them in later.
                                    </p>
                                </header>

                                <div className="flex flex-col gap-4 p-4">
                                    <Input
                                        label="Registered legal name"
                                        value={details.data.legal_name}
                                        onChange={(e) =>
                                            details.setData('legal_name', e.target.value)
                                        }
                                        error={details.errors.legal_name}
                                        optional
                                    />

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Input
                                            label={isPakistan ? 'NTN' : 'Tax registration number'}
                                            value={details.data.tax_registration_number}
                                            onChange={(e) =>
                                                details.setData(
                                                    'tax_registration_number',
                                                    e.target.value,
                                                )
                                            }
                                            error={details.errors.tax_registration_number}
                                            hint={isPakistan ? 'National Tax Number' : undefined}
                                            optional
                                        />

                                        <Input
                                            label={isPakistan ? 'STRN' : 'Sales tax number'}
                                            value={details.data.sales_tax_registration_number}
                                            onChange={(e) =>
                                                details.setData(
                                                    'sales_tax_registration_number',
                                                    e.target.value,
                                                )
                                            }
                                            error={details.errors.sales_tax_registration_number}
                                            hint={
                                                isPakistan
                                                    ? 'Sales Tax Registration Number'
                                                    : undefined
                                            }
                                            optional
                                        />
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Input
                                            label="Phone"
                                            type="tel"
                                            value={details.data.phone}
                                            onChange={(e) =>
                                                details.setData('phone', e.target.value)
                                            }
                                            error={details.errors.phone}
                                            optional
                                        />
                                        <Input
                                            label="Business email"
                                            type="email"
                                            value={details.data.email}
                                            onChange={(e) =>
                                                details.setData('email', e.target.value)
                                            }
                                            error={details.errors.email}
                                            optional
                                        />
                                    </div>

                                    <Input
                                        label="Website"
                                        type="url"
                                        placeholder="https://"
                                        value={details.data.website}
                                        onChange={(e) => details.setData('website', e.target.value)}
                                        error={details.errors.website}
                                        optional
                                    />

                                    <fieldset className="flex flex-col gap-4">
                                        <legend className="text-content-secondary text-xs font-medium">
                                            Address
                                        </legend>

                                        <Input
                                            label="Street address"
                                            value={details.data.address.line1}
                                            onChange={(e) =>
                                                details.setData('address', {
                                                    ...details.data.address,
                                                    line1: e.target.value,
                                                })
                                            }
                                            optional
                                        />

                                        <div className="grid gap-4 sm:grid-cols-3">
                                            <Input
                                                label="City"
                                                value={details.data.address.city}
                                                onChange={(e) =>
                                                    details.setData('address', {
                                                        ...details.data.address,
                                                        city: e.target.value,
                                                    })
                                                }
                                                optional
                                            />
                                            <Input
                                                label={isPakistan ? 'Province' : 'State / region'}
                                                value={details.data.address.state}
                                                onChange={(e) =>
                                                    details.setData('address', {
                                                        ...details.data.address,
                                                        state: e.target.value,
                                                    })
                                                }
                                                optional
                                            />
                                            <Input
                                                label="Postal code"
                                                value={details.data.address.postal_code}
                                                onChange={(e) =>
                                                    details.setData('address', {
                                                        ...details.data.address,
                                                        postal_code: e.target.value,
                                                    })
                                                }
                                                optional
                                            />
                                        </div>
                                    </fieldset>
                                </div>

                                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                                    <Button variant="ghost" size="md" onClick={() => setStep(1)}>
                                        Skip for now
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        size="md"
                                        loading={details.processing}
                                    >
                                        Save and continue
                                    </Button>
                                </footer>
                            </form>
                        </Card>
                    )}

                    {step === 1 && (
                        <Card flush>
                            <header className="border-line-subtle border-b px-4 py-3">
                                <h2 className="text-content text-md font-semibold">
                                    Financial year and currency
                                </h2>
                                <p className="text-content-muted text-xs">
                                    Set when you created this organisation.
                                </p>
                            </header>

                            <dl className="divide-line-subtle divide-y">
                                {[
                                    ['Base currency', organization.base_currency, true],
                                    ['Financial year starts', fiscalMonth, false],
                                    ['Time zone', organization.timezone, false],
                                    ['Country', organization.country_code, false],
                                ].map(([label, value, permanent]) => (
                                    <div
                                        key={String(label)}
                                        className="flex items-center justify-between gap-4 px-4 py-2.5"
                                    >
                                        <dt className="text-content-secondary text-sm">{label}</dt>
                                        <dd className="flex items-center gap-2">
                                            <span className="text-content font-mono text-sm">
                                                {value}
                                            </span>
                                            {permanent === true && (
                                                <Badge tone="neutral">Permanent</Badge>
                                            )}
                                        </dd>
                                    </div>
                                ))}
                            </dl>

                            <footer className="border-line-subtle bg-surface-sunken flex justify-between gap-2 border-t px-4 py-3">
                                <Button variant="ghost" size="md" onClick={() => setStep(0)}>
                                    Back
                                </Button>
                                <Button variant="primary" size="md" onClick={() => setStep(4)}>
                                    Continue
                                </Button>
                            </footer>
                        </Card>
                    )}

                    {step === 4 && (
                        <Card flush>
                            <header className="border-line-subtle border-b px-4 py-3">
                                <h2 className="text-content text-md font-semibold">Your team</h2>
                                <p className="text-content-muted text-xs">
                                    People are invited by email and choose their own password.
                                </p>
                            </header>

                            <div className="flex flex-col items-center px-6 py-12 text-center">
                                <div className="bg-surface-active mb-3 flex size-10 items-center justify-center rounded-full">
                                    <Clock
                                        className="text-content-muted size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <h3 className="text-content text-md font-semibold">
                                    Invitations are next
                                </h3>
                                <p className="text-content-muted mt-1 max-w-sm text-sm">
                                    Roles and permissions are in place — owner, accountant,
                                    bookkeeper, approver and viewer, with separation of duties
                                    between preparing and posting. The invitation flow that uses
                                    them lands shortly.
                                </p>
                            </div>

                            <footer className="border-line-subtle bg-surface-sunken flex justify-between gap-2 border-t px-4 py-3">
                                <Button variant="ghost" size="md" onClick={() => setStep(1)}>
                                    Back
                                </Button>
                                <Button variant="primary" size="md" onClick={() => setStep(5)}>
                                    Continue
                                </Button>
                            </footer>
                        </Card>
                    )}

                    {step === 5 && (
                        <Card className="flex flex-col items-center px-6 py-14 text-center">
                            <div className="bg-status-success mb-4 flex size-11 items-center justify-center rounded-full">
                                <Check
                                    className="text-status-success-fg size-6"
                                    aria-hidden="true"
                                />
                            </div>

                            <h2 className="text-content text-lg font-semibold">
                                {organization.name} is ready
                            </h2>

                            <p className="text-content-muted mt-2 max-w-md text-sm">
                                Your books are set up. Invoicing, bills and the ledger arrive over
                                the next phases — the dashboard will fill in as they do.
                            </p>

                            <div className="mt-6 flex items-center gap-2">
                                <Button variant="ghost" size="md" onClick={() => setStep(4)}>
                                    Back
                                </Button>
                                <Button
                                    variant="primary"
                                    size="lg"
                                    onClick={() => router.post('/onboarding/complete')}
                                >
                                    Finish setup
                                </Button>
                            </div>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
