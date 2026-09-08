import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Plus, Wallet } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { EmptyState, NoResultsState } from '@ui/States';
import { Pagination } from '@/Components/Pagination';
import { formatMoney, isZero } from '@/Utils/money';

interface VendorPayment {
    id: string;
    number: string;
    contact_name: string | null;
    payment_date: string;
    bank_account: string | null;
    method: string;
    reference: string | null;
    currency: string;
    amount: string;
    withholding_amount: string;
    amount_paid_out: string;
    allocated_amount: string;
    unallocated: string;
    status: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaymentsProps {
    payments: {
        data: VendorPayment[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { search: string };
    baseCurrency: string;
    can: { record: boolean };
}

/**
 * Money paid to vendors.
 *
 * Three amounts per row, because they genuinely differ: what settled the
 * vendor's balance, what actually left the bank, and what is still sitting as
 * an advance with them. Collapsing them into one figure is how a withholding
 * deduction becomes invisible — and on this side that deduction is a
 * liability we owe the tax authority, so losing sight of it is expensive.
 */
export default function VendorPaymentsIndex({
    payments,
    filters,
    baseCurrency,
    can,
}: PaymentsProps) {
    const [search, setSearch] = useState(filters.search);

    const apply = () => {
        router.get('/purchases/payments', search === '' ? {} : { search }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <AppLayout
            title="Payments made"
            description={`Money out to vendors. Amounts in ${baseCurrency} unless a payment says otherwise.`}
            breadcrumbs={[{ label: 'Purchases' }, { label: 'Payments' }]}
            actions={
                can.record ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<Plus aria-hidden="true" />}
                        onClick={() => router.get('/purchases/payments/new')}
                    >
                        Record a payment
                    </Button>
                ) : undefined
            }
        >
            <Head title="Payments made" />

            <Card className="mb-4">
                <Input
                    label="Search"
                    name="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onBlur={apply}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') apply();
                    }}
                    placeholder="Payment number, reference or vendor"
                    containerClassName="max-w-md"
                />
            </Card>

            <Card flush>
                {payments.total === 0 && filters.search === '' ? (
                    <EmptyState
                        icon={Wallet}
                        title="No payments yet"
                        description="Record money as it leaves. One payment can settle several bills, part of one, or nothing at all — in which case it is held as an advance with the vendor."
                        action={
                            can.record ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => router.get('/purchases/payments/new')}
                                >
                                    Record a payment
                                </Button>
                            ) : undefined
                        }
                    />
                ) : payments.data.length === 0 ? (
                    <NoResultsState
                        query={filters.search}
                        onClear={() => {
                            setSearch('');
                            router.get('/purchases/payments', {}, { preserveState: true });
                        }}
                    />
                ) : (
                    <>
                        <div className="table-scroll">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                        <th className="px-4 py-2 text-left font-medium">Payment</th>
                                        <th className="px-4 py-2 text-left font-medium">Vendor</th>
                                        <th className="px-4 py-2 text-left font-medium">Date</th>
                                        <th className="px-4 py-2 text-left font-medium">From</th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Settled
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Withheld
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            On account
                                        </th>
                                    </tr>
                                </thead>

                                <tbody className="divide-line-subtle divide-y">
                                    {payments.data.map((payment) => (
                                        <tr key={payment.id} className="hover:bg-surface-hover">
                                            <td className="px-4 py-2.5">
                                                <span className="text-content font-medium tabular-nums">
                                                    {payment.number}
                                                </span>
                                                {payment.reference !== null && (
                                                    <span className="text-content-muted ml-2 text-xs">
                                                        {payment.reference}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5">
                                                {payment.contact_name ?? '—'}
                                            </td>

                                            <td className="text-content-secondary px-4 py-2.5 tabular-nums">
                                                {payment.payment_date}
                                            </td>

                                            <td className="text-content-muted px-4 py-2.5 text-xs">
                                                {payment.bank_account ?? '—'}
                                                <span className="block">
                                                    {humanise(payment.method)}
                                                </span>
                                            </td>

                                            <td className="text-content px-4 py-2.5 text-right font-medium tabular-nums">
                                                {formatMoney(payment.amount, {
                                                    currency: payment.currency,
                                                    showCurrency: false,
                                                })}
                                            </td>

                                            <td className="px-4 py-2.5 text-right tabular-nums">
                                                {isZero(payment.withholding_amount) ? (
                                                    <span className="text-content-disabled">—</span>
                                                ) : (
                                                    <>
                                                        <span className="text-content-secondary">
                                                            {formatMoney(
                                                                payment.withholding_amount,
                                                                {
                                                                    currency: payment.currency,
                                                                    showCurrency: false,
                                                                },
                                                            )}
                                                        </span>
                                                        <span className="text-content-muted block text-xs">
                                                            {formatMoney(payment.amount_paid_out, {
                                                                currency: payment.currency,
                                                                showCurrency: false,
                                                            })}{' '}
                                                            paid out
                                                        </span>
                                                    </>
                                                )}
                                            </td>

                                            <td className="px-4 py-2.5 text-right tabular-nums">
                                                {isZero(payment.unallocated) ? (
                                                    <span className="text-content-disabled">—</span>
                                                ) : (
                                                    <Badge tone="warning">
                                                        {formatMoney(payment.unallocated, {
                                                            currency: payment.currency,
                                                            showCurrency: false,
                                                        })}
                                                    </Badge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <footer className="border-line-subtle text-content-muted flex items-center justify-between border-t px-4 py-2.5 text-xs">
                            <span>
                                {payments.from}–{payments.to} of {payments.total}
                            </span>
                            <Pagination links={payments.links} />
                        </footer>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}

function humanise(value: string): string {
    const spaced = value.replace(/_/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
