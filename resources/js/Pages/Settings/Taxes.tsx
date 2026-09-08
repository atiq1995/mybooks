import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Archive, Percent, Pencil, Plus, Trash2 } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState } from '@ui/States';
import { cn } from '@/Utils/cn';

interface Option {
    value: string;
    label: string;
}

interface Component {
    id: string;
    name: string;
    rate: string;
    percentage: string;
    sequence: number;
    is_compound: boolean;
    output_account_id: string | null;
    input_account_id: string | null;
    effective_from: string;
    effective_to: string | null;
    is_current: boolean;
}

interface Tax {
    id: string;
    name: string;
    code: string;
    applies_to: string;
    applies_to_label: string;
    is_inclusive_default: boolean;
    is_zero_rated: boolean;
    is_exempt: boolean;
    is_active: boolean;
    is_archived: boolean;
    effective_rate: string;
    components: Component[];
}

interface TaxesProps {
    taxes: Tax[];
    showArchived: boolean;
    options: {
        applies_to: Option[];
        liabilityAccounts: Option[];
        assetAccounts: Option[];
    };
    today: string;
    can: { manage: boolean };
}

/**
 * Tax rates.
 *
 * Configuration, but the sales module cannot work without it — so the page
 * leads with what each rate DOES rather than with a table of numbers.
 *
 * The effective rate shown accounts for compounding: "18% + 3% compound"
 * reads as 21.54, which is the figure a customer's invoice will actually
 * carry. Showing 21 would be arithmetic nobody could reconcile.
 *
 * @see ACCOUNTING_RULES.md §5
 */
export default function Taxes({ taxes, showArchived, options, today, can }: TaxesProps) {
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Tax | null>(null);

    const salesTaxes = taxes.filter((tax) => tax.applies_to !== 'withholding');
    const withholding = taxes.filter((tax) => tax.applies_to === 'withholding');

    return (
        <AppLayout
            title="Tax rates"
            description="What you charge, what you can claim back, and what is deducted at payment."
            breadcrumbs={[{ label: 'Settings' }, { label: 'Taxes' }]}
            actions={
                <div className="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="md"
                        onClick={() =>
                            router.get('/settings/taxes', showArchived ? {} : { archived: 1 }, {
                                preserveScroll: true,
                                preserveState: true,
                            })
                        }
                    >
                        {showArchived ? 'Hide archived' : 'Show archived'}
                    </Button>
                    {can.manage && !creating && (
                        <Button
                            variant="primary"
                            size="md"
                            icon={<Plus aria-hidden="true" />}
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                        >
                            New tax
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Tax rates" />

            {creating && can.manage && (
                <TaxForm options={options} today={today} onClose={() => setCreating(false)} />
            )}

            {editing !== null && can.manage && (
                <TaxForm
                    tax={editing}
                    options={options}
                    today={today}
                    onClose={() => setEditing(null)}
                />
            )}

            {taxes.length === 0 ? (
                <Card flush>
                    <EmptyState
                        icon={Percent}
                        title="No tax rates yet"
                        description="Add at least one before invoicing — even a zero-rated one, so the return has something to report. Finishing setup creates the standard set for your jurisdiction."
                        action={
                            can.manage ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => setCreating(true)}
                                >
                                    New tax
                                </Button>
                            ) : undefined
                        }
                    />
                </Card>
            ) : (
                <div className="flex flex-col gap-4">
                    <TaxTable
                        title="On documents"
                        description="Charged to customers on a sale, claimed back on a purchase."
                        taxes={salesTaxes}
                        can={can}
                        onEdit={(tax) => {
                            setCreating(false);
                            setEditing(tax);
                        }}
                    />

                    {withholding.length > 0 && (
                        <TaxTable
                            title="Withholding"
                            description="Never appears on a document. Deducted at payment time, which changes how a balance is settled rather than what the invoice says."
                            taxes={withholding}
                            can={can}
                            onEdit={(tax) => {
                                setCreating(false);
                                setEditing(tax);
                            }}
                        />
                    )}
                </div>
            )}
        </AppLayout>
    );
}

function TaxTable({
    title,
    description,
    taxes,
    can,
    onEdit,
}: {
    title: string;
    description: string;
    taxes: Tax[];
    can: { manage: boolean };
    onEdit: (tax: Tax) => void;
}) {
    if (taxes.length === 0) {
        return null;
    }

    return (
        <Card flush>
            <header className="border-line-subtle border-b px-4 py-3">
                <h2 className="text-content text-md font-semibold">{title}</h2>
                <p className="text-content-muted text-xs">{description}</p>
            </header>

            <div className="table-scroll">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                            <th className="px-4 py-2 text-left font-medium">Tax</th>
                            <th className="px-4 py-2 text-left font-medium">Code</th>
                            <th className="px-4 py-2 text-right font-medium">Rate today</th>
                            <th className="px-4 py-2 text-left font-medium">Made up of</th>
                            <th className="w-16 px-4 py-2" />
                        </tr>
                    </thead>

                    <tbody className="divide-line-subtle divide-y">
                        {taxes.map((tax) => (
                            <tr
                                key={tax.id}
                                className={cn(
                                    'hover:bg-surface-hover',
                                    tax.is_archived && 'opacity-60',
                                )}
                            >
                                <td className="px-4 py-2.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-content font-medium">{tax.name}</span>
                                        {tax.is_zero_rated && (
                                            <Badge
                                                tone="info"
                                                // Zero-rated appears on the return
                                                // at 0%; exempt does not appear at
                                                // all. Reporting them as one is a
                                                // filing error.
                                            >
                                                Zero-rated
                                            </Badge>
                                        )}
                                        {tax.is_exempt && <Badge tone="neutral">Exempt</Badge>}
                                        {tax.is_inclusive_default && (
                                            <Badge tone="neutral">Prices inclusive</Badge>
                                        )}
                                        {tax.is_archived && <Badge tone="neutral">Archived</Badge>}
                                    </div>
                                    <span className="text-content-muted text-xs">
                                        {tax.applies_to_label}
                                    </span>
                                </td>

                                <td className="text-content-secondary px-4 py-2.5 font-mono text-xs">
                                    {tax.code}
                                </td>

                                <td className="text-content px-4 py-2.5 text-right font-medium tabular-nums">
                                    {tax.effective_rate}%
                                </td>

                                <td className="px-4 py-2.5">
                                    {tax.components.length === 0 ? (
                                        <span className="text-content-muted text-xs">
                                            Nothing — outside the tax entirely
                                        </span>
                                    ) : (
                                        <ul className="flex flex-col gap-0.5 text-xs">
                                            {tax.components.map((component) => (
                                                <li
                                                    key={component.id}
                                                    className={cn(
                                                        'flex items-center gap-2',
                                                        component.is_current
                                                            ? 'text-content-secondary'
                                                            : 'text-content-disabled',
                                                    )}
                                                >
                                                    <span className="tabular-nums">
                                                        {component.percentage}%
                                                    </span>
                                                    <span>{component.name}</span>
                                                    {component.is_compound && (
                                                        <Badge tone="warning">Compound</Badge>
                                                    )}
                                                    <span className="text-content-muted">
                                                        from {component.effective_from}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </td>

                                <td className="px-4 py-2.5 text-right">
                                    {can.manage && !tax.is_archived && (
                                        <div className="flex justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => onEdit(tax)}
                                                aria-label={`Edit ${tax.name}`}
                                                className="text-content-muted hover:bg-surface-active hover:text-content rounded p-1.5 transition-colors"
                                            >
                                                <Pencil className="size-3.5" aria-hidden="true" />
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (
                                                        !window.confirm(
                                                            `Archive ${tax.name}?\n\nPast documents keep the rates they were priced with, so nothing already issued changes. It simply stops being offered on new lines.`,
                                                        )
                                                    ) {
                                                        return;
                                                    }

                                                    router.delete(`/settings/taxes/${tax.id}`, {
                                                        preserveScroll: true,
                                                    });
                                                }}
                                                aria-label={`Archive ${tax.name}`}
                                                className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 rounded p-1.5 transition-colors"
                                            >
                                                <Archive className="size-3.5" aria-hidden="true" />
                                            </button>
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Card>
    );
}

interface ComponentDraft {
    name: string;
    percentage: string;
    sequence: string;
    is_compound: boolean;
    output_account_id: string;
    input_account_id: string;
    effective_from: string;
}

function TaxForm({
    tax,
    options,
    today,
    onClose,
}: {
    tax?: Tax;
    options: TaxesProps['options'];
    today: string;
    onClose: () => void;
}) {
    const isEdit = tax !== undefined;

    const form = useForm<{
        name: string;
        code: string;
        applies_to: string;
        is_inclusive_default: boolean;
        is_zero_rated: boolean;
        is_exempt: boolean;
        components: ComponentDraft[];
    }>({
        name: tax?.name ?? '',
        code: tax?.code ?? '',
        applies_to: tax?.applies_to ?? 'both',
        is_inclusive_default: tax?.is_inclusive_default ?? false,
        is_zero_rated: tax?.is_zero_rated ?? false,
        is_exempt: tax?.is_exempt ?? false,
        components:
            tax !== undefined && tax.components.length > 0
                ? tax.components.map((component) => ({
                      name: component.name,
                      percentage: component.percentage,
                      sequence: String(component.sequence),
                      is_compound: component.is_compound,
                      output_account_id: component.output_account_id ?? '',
                      input_account_id: component.input_account_id ?? '',
                      effective_from: component.effective_from,
                  }))
                : [
                      {
                          name: '',
                          percentage: '',
                          sequence: '1',
                          is_compound: false,
                          output_account_id: '',
                          input_account_id: '',
                          effective_from: today,
                      },
                  ],
    });

    const setComponent = (index: number, patch: Partial<ComponentDraft>) => {
        form.setData(
            'components',
            form.data.components.map((component, i) =>
                i === index ? { ...component, ...patch } : component,
            ),
        );
    };

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/settings/taxes/${tax.id}`, {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.post('/settings/taxes', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    const isWithholding = form.data.applies_to === 'withholding';

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">
                        {isEdit ? `Edit ${tax.name}` : 'New tax'}
                    </h2>
                    <p className="text-content-muted text-xs">
                        A rate takes effect from a date and never changes retrospectively: a
                        document keeps whatever was in force when it was issued.
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Input
                        label="Name"
                        name="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                        placeholder="GST 18% (goods)"
                        containerClassName="sm:col-span-2"
                        required
                    />

                    <Input
                        label="Code"
                        name="code"
                        value={form.data.code}
                        onChange={(e) => form.setData('code', e.target.value)}
                        error={form.errors.code}
                        placeholder="GST18"
                        hint="Short form for a document line."
                        required
                    />

                    <Select
                        label="Applies to"
                        name="applies_to"
                        value={form.data.applies_to}
                        onChange={(e) => form.setData('applies_to', e.target.value)}
                        error={form.errors.applies_to}
                        options={options.applies_to}
                        required
                    />

                    <div className="flex flex-col gap-2 sm:col-span-2 lg:col-span-4">
                        <label className="text-content-secondary flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_inclusive_default"
                                checked={form.data.is_inclusive_default}
                                onChange={(e) =>
                                    form.setData('is_inclusive_default', e.target.checked)
                                }
                                className="border-line accent-brand size-4 rounded"
                            />
                            <span>Prices using this tax include it by default</span>
                        </label>

                        <label className="text-content-secondary flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_zero_rated"
                                checked={form.data.is_zero_rated}
                                onChange={(e) =>
                                    form.setData((data) => ({
                                        ...data,
                                        is_zero_rated: e.target.checked,
                                        // The two are different treatments, so a
                                        // tax cannot claim both.
                                        is_exempt: e.target.checked ? false : data.is_exempt,
                                    }))
                                }
                                className="border-line accent-brand mt-0.5 size-4 rounded"
                            />
                            <span>
                                Zero-rated
                                <span className="text-content-muted block text-xs">
                                    Taxable at 0% and reported on the return — an export, say.
                                </span>
                            </span>
                        </label>

                        <label className="text-content-secondary flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="is_exempt"
                                checked={form.data.is_exempt}
                                onChange={(e) =>
                                    form.setData((data) => ({
                                        ...data,
                                        is_exempt: e.target.checked,
                                        is_zero_rated: e.target.checked
                                            ? false
                                            : data.is_zero_rated,
                                    }))
                                }
                                className="border-line accent-brand mt-0.5 size-4 rounded"
                            />
                            <span>
                                Exempt
                                <span className="text-content-muted block text-xs">
                                    Outside the tax entirely, so it does not appear on the return at
                                    all. Not the same as zero-rated, and reporting them as one is a
                                    filing error.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                {!form.data.is_exempt && (
                    <div className="border-line-subtle border-t">
                        <header className="flex items-center justify-between px-4 py-3">
                            <div>
                                <h3 className="text-content text-sm font-semibold">Components</h3>
                                <p className="text-content-muted text-xs">
                                    Most taxes have one. Several occur when a supply carries, say, a
                                    provincial tax plus a further tax for an unregistered buyer —
                                    each landing in its own account and each reported separately.
                                </p>
                            </div>

                            <Button
                                variant="secondary"
                                size="sm"
                                icon={<Plus aria-hidden="true" />}
                                onClick={() =>
                                    form.setData('components', [
                                        ...form.data.components,
                                        {
                                            name: '',
                                            percentage: '',
                                            sequence: String(form.data.components.length + 1),
                                            is_compound: false,
                                            output_account_id: '',
                                            input_account_id: '',
                                            effective_from: today,
                                        },
                                    ])
                                }
                            >
                                Add component
                            </Button>
                        </header>

                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-3 py-2 text-left font-medium">Name</th>
                                        <th className="w-24 px-3 py-2 text-right font-medium">
                                            Rate %
                                        </th>
                                        <th className="w-32 px-3 py-2 text-left font-medium">
                                            From
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {isWithholding ? 'Payable to' : 'Charged to'}
                                        </th>
                                        <th className="px-3 py-2 text-left font-medium">
                                            {isWithholding ? 'Recoverable in' : 'Claimed in'}
                                        </th>
                                        <th className="w-24 px-3 py-2 text-left font-medium">
                                            Compound
                                        </th>
                                        <th className="w-10 px-3 py-2" />
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {form.data.components.map((component, index) => (
                                        <tr key={index} className="align-top">
                                            <td className="px-3 py-2">
                                                <Input
                                                    aria-label={`Component ${index + 1} name`}
                                                    name={`components.${index}.name`}
                                                    value={component.name}
                                                    onChange={(e) =>
                                                        setComponent(index, {
                                                            name: e.target.value,
                                                        })
                                                    }
                                                    required
                                                />
                                            </td>

                                            <td className="px-3 py-2">
                                                <Input
                                                    aria-label={`Component ${index + 1} rate`}
                                                    name={`components.${index}.percentage`}
                                                    inputMode="decimal"
                                                    numeric
                                                    value={component.percentage}
                                                    onChange={(e) =>
                                                        setComponent(index, {
                                                            percentage: e.target.value,
                                                        })
                                                    }
                                                    placeholder="18"
                                                    required
                                                />
                                            </td>

                                            <td className="px-3 py-2">
                                                <Input
                                                    aria-label={`Component ${index + 1} effective from`}
                                                    name={`components.${index}.effective_from`}
                                                    type="date"
                                                    value={component.effective_from}
                                                    onChange={(e) =>
                                                        setComponent(index, {
                                                            effective_from: e.target.value,
                                                        })
                                                    }
                                                    required
                                                />
                                            </td>

                                            <td className="px-3 py-2">
                                                <Select
                                                    aria-label={`Component ${index + 1} output account`}
                                                    name={`components.${index}.output_account_id`}
                                                    value={component.output_account_id}
                                                    onChange={(e) =>
                                                        setComponent(index, {
                                                            output_account_id: e.target.value,
                                                        })
                                                    }
                                                    options={[
                                                        { value: '', label: '— none —' },
                                                        ...options.liabilityAccounts,
                                                    ]}
                                                />
                                            </td>

                                            <td className="px-3 py-2">
                                                <Select
                                                    aria-label={`Component ${index + 1} input account`}
                                                    name={`components.${index}.input_account_id`}
                                                    value={component.input_account_id}
                                                    onChange={(e) =>
                                                        setComponent(index, {
                                                            input_account_id: e.target.value,
                                                        })
                                                    }
                                                    options={[
                                                        { value: '', label: '— none —' },
                                                        ...options.assetAccounts,
                                                    ]}
                                                />
                                            </td>

                                            <td className="px-3 py-2">
                                                <label className="text-content-secondary mt-2 flex items-center gap-2 text-xs">
                                                    <input
                                                        type="checkbox"
                                                        name={`components.${index}.is_compound`}
                                                        checked={component.is_compound}
                                                        onChange={(e) =>
                                                            setComponent(index, {
                                                                is_compound: e.target.checked,
                                                            })
                                                        }
                                                        className="border-line accent-brand size-4 rounded"
                                                    />
                                                    <span title="Charged on the value including the components before it">
                                                        On the tax above
                                                    </span>
                                                </label>
                                            </td>

                                            <td className="px-3 py-2">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData(
                                                            'components',
                                                            form.data.components.filter(
                                                                (_, i) => i !== index,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={`Remove component ${index + 1}`}
                                                    className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 mt-1 rounded p-1.5 transition-colors"
                                                >
                                                    <Trash2
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="ghost" size="md" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" size="md" loading={form.processing}>
                        {isEdit ? 'Save changes' : 'Create tax'}
                    </Button>
                </footer>
            </form>
        </Card>
    );
}
