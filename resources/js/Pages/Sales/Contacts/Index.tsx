import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Archive, Pencil, Plus, RotateCcw, Users } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { EmptyState, NoResultsState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney, isZero } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Contact {
    id: string;
    display_name: string;
    legal_name: string | null;
    kind: string;
    kind_label: string;
    email: string | null;
    phone: string | null;
    currency: string;
    payment_terms_days: number;
    is_tax_filer: boolean;
    is_archived: boolean;
    outstanding: string;
    /** What we owe them. Never netted against `outstanding`. */
    payable: string;
    credit_limit: string | null;
    over_limit: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface ContactsProps {
    contacts: {
        data: Contact[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { kind: string; search: string; archived: boolean };
    baseCurrency: string;
    options: { kinds: { value: string; label: string }[] };
    can: { create: boolean; update: boolean };
}

/**
 * Customers and vendors.
 *
 * The outstanding column is why this screen exists rather than being a
 * dropdown somewhere: "who owes us money" is the question people arrive with,
 * and a list of names would send them into forty detail pages to answer it.
 */
export default function ContactsIndex({
    contacts,
    filters,
    baseCurrency,
    options,
    can,
}: ContactsProps) {
    const [search, setSearch] = useState(filters.search);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Contact | null>(null);

    const apply = (next: Partial<{ kind: string; search: string; archived: boolean }>) => {
        const merged = { ...filters, search, ...next };

        router.get(
            '/sales/customers',
            {
                ...(merged.kind === '' ? {} : { kind: merged.kind }),
                ...(merged.search === '' ? {} : { search: merged.search }),
                ...(merged.archived ? { archived: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const archive = (contact: Contact) => {
        if (
            !window.confirm(
                `Archive ${contact.display_name}?\n\n` +
                    'Their past documents stay exactly as they are — archiving only removes them ' +
                    'from the customer and vendor pickers.',
            )
        ) {
            return;
        }

        router.delete(`/sales/customers/${contact.id}`, { preserveScroll: true });
    };

    const isFiltered = filters.search !== '' || filters.kind !== '';

    return (
        <AppLayout
            title="Customers and vendors"
            description={`Who you invoice and who you buy from. Balances in ${baseCurrency}.`}
            breadcrumbs={[{ label: 'Sales' }, { label: 'Customers' }]}
            actions={
                <div className="flex items-center gap-2">
                    <Button
                        variant="ghost"
                        size="md"
                        onClick={() => apply({ archived: !filters.archived })}
                    >
                        {filters.archived ? 'Hide archived' : 'Show archived'}
                    </Button>
                    {can.create && !creating && (
                        <Button
                            variant="primary"
                            size="md"
                            icon={<Plus aria-hidden="true" />}
                            onClick={() => {
                                setEditing(null);
                                setCreating(true);
                            }}
                        >
                            Add a contact
                        </Button>
                    )}
                </div>
            }
        >
            <Head title="Customers and vendors" />

            {creating && can.create && (
                <ContactForm options={options} onClose={() => setCreating(false)} />
            )}

            {editing !== null && can.update && (
                <ContactForm contact={editing} options={options} onClose={() => setEditing(null)} />
            )}

            <Card className="mb-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Input
                        label="Search"
                        name="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onBlur={() => apply({})}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({});
                        }}
                        placeholder="Name, email or tax number"
                    />

                    <Select
                        label="Kind"
                        name="kind"
                        value={filters.kind}
                        onChange={(e) => apply({ kind: e.target.value })}
                        options={[{ value: '', label: 'Everyone' }, ...options.kinds]}
                    />
                </div>
            </Card>

            <Card flush>
                {contacts.total === 0 && !isFiltered ? (
                    <EmptyState
                        icon={Users}
                        title="Nobody yet"
                        description="Add the businesses you invoice and buy from. The same contact can be both, which is more common than it sounds."
                        action={
                            can.create ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => setCreating(true)}
                                >
                                    Add a contact
                                </Button>
                            ) : undefined
                        }
                    />
                ) : contacts.data.length === 0 ? (
                    <NoResultsState
                        query={filters.search === '' ? undefined : filters.search}
                        onClear={() => {
                            setSearch('');
                            router.get('/sales/customers', {}, { preserveState: true });
                        }}
                    />
                ) : (
                    <>
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">Name</th>
                                        <th className="px-4 py-2 text-left font-medium">Kind</th>
                                        <th className="px-4 py-2 text-left font-medium">Contact</th>
                                        <th className="px-4 py-2 text-right font-medium">Terms</th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            They owe
                                        </th>
                                        {/*
                                         * A second money column rather than a
                                         * signed one. The same company can owe
                                         * us and be owed, and netting the two
                                         * would hide a receivable behind a
                                         * payable — leaving neither
                                         * collectable nor payable on its own.
                                         */}
                                        <th className="px-4 py-2 text-right font-medium">We owe</th>
                                        <th className="w-20 px-4 py-2" />
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {contacts.data.map((contact) => (
                                        <tr
                                            key={contact.id}
                                            className={cn(
                                                'hover:bg-surface-hover',
                                                contact.is_archived && 'opacity-60',
                                            )}
                                        >
                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center gap-2">
                                                    <Link
                                                        href={`/sales/customers/${contact.id}`}
                                                        className="text-brand-text font-medium hover:underline"
                                                    >
                                                        {contact.display_name}
                                                    </Link>
                                                    {contact.is_archived && (
                                                        <Badge tone="neutral">Archived</Badge>
                                                    )}
                                                    {!contact.is_tax_filer && (
                                                        <Badge
                                                            tone="warning"
                                                            // Not a note: a non-filer's
                                                            // withholding rate is roughly
                                                            // double, so it changes the
                                                            // arithmetic at payment.
                                                        >
                                                            Non-filer
                                                        </Badge>
                                                    )}
                                                </div>
                                                {contact.legal_name !== null && (
                                                    <span className="text-content-muted text-xs">
                                                        {contact.legal_name}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 text-xs">
                                                {contact.kind_label}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 text-xs">
                                                {contact.email ?? contact.phone ?? '—'}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                                {contact.payment_terms_days === 0
                                                    ? 'On receipt'
                                                    : `${contact.payment_terms_days}d`}
                                            </td>

                                            <td className="px-4 py-2.5 text-right">
                                                <span
                                                    className={cn(
                                                        'font-medium tabular-nums',
                                                        isZero(contact.outstanding)
                                                            ? 'text-content-disabled'
                                                            : 'text-content',
                                                    )}
                                                >
                                                    {isZero(contact.outstanding)
                                                        ? '—'
                                                        : formatMoney(contact.outstanding, {
                                                              currency: contact.currency,
                                                              showCurrency: false,
                                                          })}
                                                </span>
                                                {contact.over_limit && (
                                                    <span
                                                        className="text-warning-600 dark:text-warning-400 flex items-center justify-end gap-1 text-xs"
                                                        title={`Over their credit limit of ${contact.credit_limit ?? ''}`}
                                                    >
                                                        <AlertTriangle
                                                            className="size-3"
                                                            aria-hidden="true"
                                                        />
                                                        Over limit
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-4 py-2.5 text-right">
                                                <span
                                                    className={cn(
                                                        'font-medium tabular-nums',
                                                        isZero(contact.payable)
                                                            ? 'text-content-disabled'
                                                            : 'text-content',
                                                    )}
                                                >
                                                    {isZero(contact.payable)
                                                        ? '—'
                                                        : formatMoney(contact.payable, {
                                                              currency: contact.currency,
                                                              showCurrency: false,
                                                          })}
                                                </span>
                                            </td>

                                            <td className="px-4 py-2.5 text-right">
                                                {can.update && (
                                                    <div className="flex justify-end gap-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setCreating(false);
                                                                setEditing(contact);
                                                            }}
                                                            aria-label={`Edit ${contact.display_name}`}
                                                            className="text-content-muted hover:bg-surface-active hover:text-content rounded p-1.5 transition-colors"
                                                        >
                                                            <Pencil
                                                                className="size-3.5"
                                                                aria-hidden="true"
                                                            />
                                                        </button>

                                                        {contact.is_archived ? (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    router.post(
                                                                        `/sales/customers/${contact.id}/restore`,
                                                                        {},
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                                aria-label={`Restore ${contact.display_name}`}
                                                                className="text-content-muted hover:bg-surface-active hover:text-content rounded p-1.5 transition-colors"
                                                            >
                                                                <RotateCcw
                                                                    className="size-3.5"
                                                                    aria-hidden="true"
                                                                />
                                                            </button>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={() => archive(contact)}
                                                                aria-label={`Archive ${contact.display_name}`}
                                                                className="text-content-muted hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30 rounded p-1.5 transition-colors"
                                                            >
                                                                <Archive
                                                                    className="size-3.5"
                                                                    aria-hidden="true"
                                                                />
                                                            </button>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <footer className="border-line-subtle text-content-muted flex items-center justify-between border-t px-4 py-2.5 text-xs">
                            <span>
                                {contacts.from}–{contacts.to} of {contacts.total}
                            </span>
                            <Pagination links={contacts.links} />
                        </footer>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

function ContactForm({
    contact,
    options,
    onClose,
}: {
    contact?: Contact;
    options: ContactsProps['options'];
    onClose: () => void;
}) {
    const isEdit = contact !== undefined;

    const form = useForm({
        kind: contact?.kind ?? 'customer',
        display_name: contact?.display_name ?? '',
        legal_name: contact?.legal_name ?? '',
        email: contact?.email ?? '',
        phone: contact?.phone ?? '',
        tax_registration_number: '',
        sales_tax_registration_number: '',
        is_tax_filer: contact?.is_tax_filer ?? true,
        currency: '',
        payment_terms_days: String(contact?.payment_terms_days ?? 30),
        credit_limit: contact?.credit_limit ?? '',
    });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (isEdit) {
            form.patch(`/sales/customers/${contact.id}`, {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        form.post('/sales/customers', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">
                        {isEdit ? `Edit ${contact.display_name}` : 'Add a contact'}
                    </h2>
                    <p className="text-content-muted text-xs">
                        The same business can be both a customer and a vendor — one record keeps
                        their balance and tax numbers in one place.
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Select
                        label="Kind"
                        name="kind"
                        value={form.data.kind}
                        onChange={(e) => form.setData('kind', e.target.value)}
                        error={form.errors.kind}
                        options={options.kinds}
                        required
                    />

                    <Input
                        label="Name"
                        name="display_name"
                        value={form.data.display_name}
                        onChange={(e) => form.setData('display_name', e.target.value)}
                        error={form.errors.display_name}
                        hint="What appears on documents."
                        required
                    />

                    <Input
                        label="Registered legal name"
                        name="legal_name"
                        value={form.data.legal_name}
                        onChange={(e) => form.setData('legal_name', e.target.value)}
                        error={form.errors.legal_name}
                        optional
                    />

                    <Input
                        label="Email"
                        name="email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        error={form.errors.email}
                        optional
                    />

                    <Input
                        label="Phone"
                        name="phone"
                        type="tel"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                        error={form.errors.phone}
                        optional
                    />

                    <Input
                        label="Payment terms"
                        name="payment_terms_days"
                        inputMode="numeric"
                        value={form.data.payment_terms_days}
                        onChange={(e) => form.setData('payment_terms_days', e.target.value)}
                        error={form.errors.payment_terms_days}
                        hint="Days. Zero means on receipt."
                        required
                    />

                    <Input
                        label="NTN"
                        name="tax_registration_number"
                        value={form.data.tax_registration_number}
                        onChange={(e) => form.setData('tax_registration_number', e.target.value)}
                        error={form.errors.tax_registration_number}
                        hint="National Tax Number"
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
                        hint="Sales Tax Registration Number"
                        optional
                    />

                    <Input
                        label="Credit limit"
                        name="credit_limit"
                        inputMode="decimal"
                        numeric
                        value={form.data.credit_limit}
                        onChange={(e) => form.setData('credit_limit', e.target.value)}
                        error={form.errors.credit_limit}
                        hint="Advisory — nothing is blocked."
                        optional
                    />

                    <label className="text-content-secondary flex items-start gap-2 text-sm sm:col-span-2 lg:col-span-3">
                        <input
                            type="checkbox"
                            name="is_tax_filer"
                            checked={form.data.is_tax_filer}
                            onChange={(e) => form.setData('is_tax_filer', e.target.checked)}
                            className="border-line accent-brand mt-0.5 size-4 rounded"
                        />
                        <span>
                            They file tax returns
                            <span className="text-content-muted block text-xs">
                                A non-filer&rsquo;s withholding rate is roughly double a
                                filer&rsquo;s, so this changes what is deducted at payment — it is
                                not just a note.
                            </span>
                        </span>
                    </label>
                </div>

                <footer className="border-line-subtle bg-surface-sunken flex justify-end gap-2 border-t px-4 py-3">
                    <Button variant="ghost" size="md" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" size="md" loading={form.processing}>
                        {isEdit ? 'Save changes' : 'Add contact'}
                    </Button>
                </footer>
            </form>
        </Card>
    );
}
