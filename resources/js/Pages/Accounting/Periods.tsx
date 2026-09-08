import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarCheck, CalendarPlus, Lock, LockOpen, ShieldCheck } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Badge } from '@ui/Badge';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { EmptyState } from '@ui/States';
import { cn } from '@/Utils/cn';

interface Period {
    id: string;
    sequence: number;
    label: string;
    starts_on: string;
    ends_on: string;
    status: 'open' | 'closed' | 'locked';
    status_label: string;
    can_reopen: boolean;
    entries: number;
}

interface Year {
    id: string;
    label: string;
    starts_on: string;
    ends_on: string;
    status: string;
    status_label: string;
    closing_entry_no: string | null;
    can_close: boolean;
    periods: Period[];
}

interface PeriodsProps {
    years: Year[];
    baseCurrency: string;
    fiscalYearStartMonth: number;
    can: { manage: boolean; post_to_closed: boolean; close_year: boolean };
}

const TONES = {
    open: 'success',
    closed: 'warning',
    locked: 'neutral',
} as const;

/**
 * Financial years and the periods inside them.
 *
 * The three states differ in exactly one way that matters, and the page says
 * so plainly: open accepts postings, closed accepts them only from somebody
 * with the override permission and records every use, and locked accepts
 * none and can never be undone.
 *
 * Locking is deliberately the hardest action on the screen. It is what "we
 * have filed this" means, and it is the only one with no way back.
 *
 * @see ACCOUNTING_RULES.md §7
 */
export default function Periods({ years, fiscalYearStartMonth, can }: PeriodsProps) {
    const [openingYear, setOpeningYear] = useState(false);

    const setStatus = (period: Period, status: Period['status']) => {
        const confirmations: Record<Period['status'], string | null> = {
            open: null,
            closed:
                `Close ${period.label}?\n\n` +
                (period.entries > 0
                    ? `It holds ${period.entries} ${period.entries === 1 ? 'entry' : 'entries'}. `
                    : '') +
                'Postings will be refused unless somebody holds the override permission, and ' +
                'every use of it is recorded. You can reopen it later.',
            locked:
                `LOCK ${period.label}?\n\n` +
                'This cannot be undone. Nobody will ever post into this period again, whatever ' +
                'permission they hold. Lock it only once the figures have been filed.',
        };

        const message = confirmations[status];

        if (message !== null && !window.confirm(message)) {
            return;
        }

        router.patch(`/accounting/periods/${period.id}`, { status }, { preserveScroll: true });
    };

    const closeYear = (year: Year) => {
        if (
            !window.confirm(
                `Close financial year ${year.label}?\n\n` +
                    'Every income and expense account is emptied into retained earnings, and ' +
                    'all twelve periods are closed. Balance sheet accounts carry forward.\n\n' +
                    'This is reversible — the closing entry can be reversed like any other, ' +
                    'and a period can be reopened — but it is what you do once the year is ' +
                    'final.',
            )
        ) {
            return;
        }

        router.post(`/accounting/fiscal-years/${year.id}/close`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout
            title="Fiscal Periods"
            description="Which months accept postings, and which are shut. Closing a period is how a set of figures is made final."
            breadcrumbs={[{ label: 'Accounting' }, { label: 'Fiscal Periods' }]}
            actions={
                can.manage && !openingYear ? (
                    <Button
                        variant="primary"
                        size="md"
                        icon={<CalendarPlus aria-hidden="true" />}
                        onClick={() => setOpeningYear(true)}
                    >
                        Open a financial year
                    </Button>
                ) : undefined
            }
        >
            <Head title="Fiscal Periods" />

            {openingYear && can.manage && (
                <NewYearForm
                    startMonth={fiscalYearStartMonth}
                    existing={years.map((y) => y.label)}
                    onClose={() => setOpeningYear(false)}
                />
            )}

            {can.post_to_closed && (
                <Card className="border-line-brand mb-4">
                    <p className="text-content-secondary flex items-start gap-2 text-sm">
                        <ShieldCheck
                            className="text-brand mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            You can post into a closed period.
                            <span className="text-content-muted block text-xs">
                                Every time you use it, the entry is flagged in the audit trail with
                                your name against it. A locked period still refuses you.
                            </span>
                        </span>
                    </p>
                </Card>
            )}

            {years.length === 0 ? (
                <Card flush>
                    <EmptyState
                        icon={CalendarPlus}
                        title="No financial year yet"
                        description="Nothing can be posted until a year exists to post it into. Open one, and its twelve periods are created with it."
                        action={
                            can.manage ? (
                                <Button
                                    variant="primary"
                                    size="sm"
                                    onClick={() => setOpeningYear(true)}
                                >
                                    Open a financial year
                                </Button>
                            ) : undefined
                        }
                    />
                </Card>
            ) : (
                <div className="flex flex-col gap-4">
                    {years.map((year) => (
                        <Card key={year.id} flush>
                            <header className="border-line-subtle flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                                <div>
                                    <h2 className="text-content text-md font-semibold">
                                        {year.label}
                                    </h2>
                                    <p className="text-content-muted text-xs tabular-nums">
                                        {year.starts_on} to {year.ends_on}
                                    </p>
                                </div>

                                <div className="flex items-center gap-2">
                                    {year.closing_entry_no !== null && (
                                        <span className="text-content-muted text-xs">
                                            closed by{' '}
                                            <Link
                                                href={`/accounting/journals/${year.closing_entry_no}`}
                                                className="text-brand-text tabular-nums hover:underline"
                                            >
                                                {year.closing_entry_no}
                                            </Link>
                                        </span>
                                    )}

                                    <Badge
                                        tone={TONES[year.status as keyof typeof TONES] ?? 'neutral'}
                                    >
                                        {year.status_label}
                                    </Badge>

                                    {can.close_year && year.can_close && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            icon={<CalendarCheck aria-hidden="true" />}
                                            onClick={() => closeYear(year)}
                                        >
                                            Close the year
                                        </Button>
                                    )}
                                </div>
                            </header>

                            <div className="table-scroll">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-content-muted border-line-subtle text-2xs border-b uppercase">
                                            <th className="px-4 py-2 text-left font-medium">
                                                Period
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Dates
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Entries
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                {can.manage ? 'Actions' : ''}
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-line-subtle divide-y">
                                        {year.periods.map((period) => (
                                            <tr
                                                key={period.id}
                                                className={cn(
                                                    'hover:bg-surface-hover',
                                                    period.status === 'locked' && 'opacity-70',
                                                )}
                                            >
                                                <td className="text-content px-4 py-2.5 font-medium">
                                                    {period.label}
                                                </td>

                                                <td className="text-content-muted px-4 py-2.5 text-xs tabular-nums">
                                                    {period.starts_on} — {period.ends_on}
                                                </td>

                                                <td className="text-content-secondary px-4 py-2.5 text-right tabular-nums">
                                                    {period.entries === 0 ? '—' : period.entries}
                                                </td>

                                                <td className="px-4 py-2.5">
                                                    <Badge tone={TONES[period.status]}>
                                                        {period.status === 'locked' && (
                                                            <Lock
                                                                className="size-2.5"
                                                                aria-hidden="true"
                                                            />
                                                        )}
                                                        {period.status_label}
                                                    </Badge>
                                                </td>

                                                <td className="px-4 py-2.5 text-right">
                                                    {can.manage && period.status !== 'locked' && (
                                                        <div className="flex justify-end gap-1.5">
                                                            {period.status === 'open' ? (
                                                                <Button
                                                                    variant="secondary"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        setStatus(period, 'closed')
                                                                    }
                                                                >
                                                                    Close
                                                                </Button>
                                                            ) : (
                                                                <Button
                                                                    variant="secondary"
                                                                    size="sm"
                                                                    icon={
                                                                        <LockOpen aria-hidden="true" />
                                                                    }
                                                                    onClick={() =>
                                                                        setStatus(period, 'open')
                                                                    }
                                                                >
                                                                    Reopen
                                                                </Button>
                                                            )}

                                                            {/* Only a closed period can be
                                                                locked: locking straight from
                                                                open skips the review that
                                                                closing exists to prompt. */}
                                                            {period.status === 'closed' && (
                                                                <Button
                                                                    variant="danger"
                                                                    size="sm"
                                                                    icon={
                                                                        <Lock aria-hidden="true" />
                                                                    }
                                                                    onClick={() =>
                                                                        setStatus(period, 'locked')
                                                                    }
                                                                >
                                                                    Lock
                                                                </Button>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}

function NewYearForm({
    startMonth,
    existing,
    onClose,
}: {
    startMonth: number;
    existing: string[];
    onClose: () => void;
}) {
    const form = useForm({ starting_year: String(new Date().getFullYear()) });

    const submit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/accounting/fiscal-years', {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const monthName = new Date(2000, startMonth - 1, 1).toLocaleDateString(undefined, {
        month: 'long',
    });

    return (
        <Card flush className="mb-4">
            <form onSubmit={submit}>
                <header className="border-line-subtle border-b px-4 py-3">
                    <h2 className="text-content text-md font-semibold">Open a financial year</h2>
                    <p className="text-content-muted text-xs">
                        Twelve monthly periods are created with it, starting in {monthName} — your
                        organisation&rsquo;s fiscal start month.
                        {existing.length > 0 && ` You already have ${existing.join(', ')}.`}
                    </p>
                </header>

                <div className="grid gap-4 p-4 sm:grid-cols-[12rem_1fr]">
                    <Input
                        label="Starts in"
                        // Text rather than number: a year is an identifier here,
                        // not a quantity to be stepped through.
                        inputMode="numeric"
                        value={form.data.starting_year}
                        onChange={(e) => form.setData('starting_year', e.target.value)}
                        error={form.errors.starting_year}
                        hint={`The calendar year the ${monthName} start falls in.`}
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
                        icon={<CalendarPlus aria-hidden="true" />}
                    >
                        Open the year
                    </Button>
                </footer>
            </form>
        </Card>
    );
}
