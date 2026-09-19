import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, Download, Printer, Sheet, TriangleAlert } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Button } from '@ui/Button';
import { Card } from '@ui/Card';
import { Input } from '@ui/Input';
import { Select } from '@ui/Select';
import { formatMoney } from '@/Utils/money';
import { cn } from '@/Utils/cn';

interface Column {
    key: string;
    label: string;
    kind: string;
}

interface Row {
    label: string;
    code: string | null;
    values: Record<string, string | null>;
    depth: number;
    style: string;
    drill: { account_id: string; from: string | null; to: string } | null;
}

interface Section {
    label: string | null;
    rows: Row[];
    total: Row | null;
}

interface Report {
    title: string;
    subtitle: string | null;
    currency: string;
    period: { from: string; to: string; label: string };
    comparison: { from: string; to: string; label: string } | null;
    columns: Column[];
    sections: Section[];
    footer: Row[];
    reconciles: boolean;
    reconciliation: string | null;
    notes: Record<string, string>;
}

interface Option {
    value: string;
    label: string;
}

interface StatementProps {
    report: Report;
    organizationName: string;
    route: string;
    dateMode: 'range' | 'as_at';
    filters: {
        preset?: string;
        from?: string;
        to?: string;
        as_of?: string;
        compare?: string;
        zero?: boolean;
        view?: string;
    };
    presetOptions?: Option[];
    comparisonOptions?: Option[];
    viewOptions?: Option[];
}

const PATHS: Record<string, string> = {
    'reports.profit-and-loss': '/reports/profit-and-loss',
    'reports.balance-sheet': '/reports/balance-sheet',
    'reports.cash-flow': '/reports/cash-flow',
    'reports.tax-summary': '/reports/tax-summary',
    'reports.analytics': '/reports/analytics',
};

/**
 * One screen for every statement.
 *
 * The reports differ in what they compute, not in how they are read: a period,
 * an optional comparison, sections of accounts, subtotals, and a statement
 * about whether the thing reconciles. Five screens would be five places for
 * those to diverge.
 *
 * Two details carry most of the weight. Money is right-aligned and tabular, so
 * a column of figures can be scanned rather than read; and a negative is shown
 * in the accounting convention with a minus AND a colour, never colour alone —
 * a reader who cannot distinguish red is otherwise looking at a profit.
 */
export default function Statement({
    report,
    organizationName,
    route,
    dateMode,
    filters,
    presetOptions = [],
    comparisonOptions = [],
    viewOptions = [],
}: StatementProps) {
    const path = PATHS[route] ?? '/reports';

    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const reload = (changes: Record<string, string | boolean>) => {
        router.get(
            path,
            {
                ...filters,
                from,
                to,
                ...changes,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const exportTo = (format: string) => {
        const query = new URLSearchParams();

        Object.entries({ ...filters, from, to, format }).forEach(([key, value]) => {
            if (value !== undefined && value !== '' && value !== false) {
                query.set(key, String(value));
            }
        });

        // A full page load, not an Inertia visit: the response is a file or a
        // print document, neither of which is a page the router can swap in.
        window.open(`${path}?${query.toString()}`, format === 'print' ? '_blank' : '_self');
    };

    return (
        <AppLayout
            title={report.title}
            description={`${organizationName} · ${report.period.label} · amounts in ${report.currency}`}
            breadcrumbs={[{ label: 'Reports', href: '/reports' }, { label: report.title }]}
            actions={
                <div className="flex items-center gap-2">
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<Download aria-hidden="true" />}
                        onClick={() => exportTo('csv')}
                    >
                        CSV
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<Sheet aria-hidden="true" />}
                        onClick={() => exportTo('xlsx')}
                    >
                        Excel
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<Printer aria-hidden="true" />}
                        onClick={() => exportTo('print')}
                    >
                        Print / PDF
                    </Button>
                </div>
            }
        >
            <Head title={report.title} />

            <div className="flex flex-col gap-4">
                <Card>
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event: SyntheticEvent) => {
                            event.preventDefault();
                            reload({ preset: '' });
                        }}
                    >
                        {viewOptions.length > 0 && (
                            <Select
                                label="View"
                                containerClassName="w-full sm:w-56"
                                options={viewOptions}
                                value={filters.view ?? 'customers'}
                                onChange={(event) => reload({ view: event.target.value })}
                            />
                        )}

                        {dateMode === 'as_at' ? (
                            <Input
                                type="date"
                                label="As at"
                                containerClassName="w-full sm:w-48"
                                value={filters.as_of ?? ''}
                                onChange={(event) => reload({ as_of: event.target.value })}
                            />
                        ) : (
                            <>
                                <Select
                                    label="Period"
                                    containerClassName="w-full sm:w-56"
                                    options={presetOptions}
                                    value={filters.preset ?? ''}
                                    onChange={(event) =>
                                        router.get(path, {
                                            ...filters,
                                            preset: event.target.value,
                                            from: '',
                                            to: '',
                                        })
                                    }
                                />

                                <Input
                                    type="date"
                                    label="From"
                                    containerClassName="w-full sm:w-44"
                                    value={from}
                                    onChange={(event) => setFrom(event.target.value)}
                                />

                                <Input
                                    type="date"
                                    label="To"
                                    containerClassName="w-full sm:w-44"
                                    value={to}
                                    onChange={(event) => setTo(event.target.value)}
                                />

                                <Button type="submit" variant="secondary">
                                    Apply
                                </Button>
                            </>
                        )}

                        {comparisonOptions.length > 0 && (
                            <Select
                                label="Compare with"
                                containerClassName="w-full sm:w-56"
                                options={comparisonOptions}
                                value={filters.compare ?? ''}
                                onChange={(event) => reload({ compare: event.target.value })}
                            />
                        )}
                    </form>
                </Card>

                {report.reconciliation !== null && (
                    <div
                        className={cn(
                            'flex items-start gap-3 rounded-md border p-3',
                            report.reconciles
                                ? 'border-line-subtle bg-surface-sunken'
                                : 'border-danger bg-danger-subtle',
                        )}
                    >
                        {report.reconciles ? (
                            <CheckCircle2
                                className="text-success mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                        ) : (
                            <TriangleAlert
                                className="text-danger mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                        )}
                        <p
                            className={cn(
                                'text-xs',
                                report.reconciles ? 'text-content-secondary' : 'text-danger',
                            )}
                        >
                            {report.reconciliation}
                        </p>
                    </div>
                )}

                <Card flush>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-sm">
                            <caption className="sr-only">
                                {report.title}, {report.period.label}, in {report.currency}
                            </caption>

                            <thead className="border-line-subtle text-content-muted border-b text-xs">
                                <tr>
                                    {report.columns.map((column, index) => (
                                        <th
                                            key={column.key}
                                            scope="col"
                                            className={cn(
                                                'px-4 py-2 font-medium',
                                                index === 0 ? 'text-left' : 'text-right',
                                            )}
                                        >
                                            {column.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>

                            <tbody>
                                {report.sections.map((section, sectionIndex) => (
                                    <SectionRows
                                        key={section.label ?? `section-${sectionIndex}`}
                                        section={section}
                                        report={report}
                                    />
                                ))}

                                {report.footer.map((row, index) => (
                                    <Line key={`footer-${index}`} row={row} report={report} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {Object.entries(report.notes).length > 0 && (
                    <ul className="text-content-muted flex flex-col gap-1 text-xs">
                        {Object.entries(report.notes).map(([key, note]) => (
                            <li key={key}>{note}</li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}

function SectionRows({ section, report }: { section: Section; report: Report }) {
    return (
        <>
            {section.label !== null && (
                <tr className="border-line-subtle border-b">
                    <th
                        scope="rowgroup"
                        colSpan={report.columns.length}
                        className="text-content px-4 pt-5 pb-1 text-left text-xs font-semibold tracking-wide uppercase"
                    >
                        {section.label}
                    </th>
                </tr>
            )}

            {section.rows.map((row, index) => (
                <Line key={`${section.label ?? 'section'}-${index}`} row={row} report={report} />
            ))}

            {section.total !== null && <Line row={section.total} report={report} />}
        </>
    );
}

function Line({ row, report }: { row: Row; report: Report }) {
    const emphasised = row.style === 'total' || row.style === 'subtotal';

    return (
        <tr
            className={cn(
                'border-line-subtle border-b last:border-0',
                row.style === 'total' && 'border-line bg-surface-sunken',
            )}
        >
            {report.columns.map((column, index) => {
                if (index === 0) {
                    return (
                        <td
                            key={column.key}
                            className={cn(
                                'px-4 py-1.5',
                                emphasised && 'text-content font-semibold',
                            )}
                            style={{ paddingLeft: `${16 + row.depth * 16}px` }}
                        >
                            {row.code !== null && row.style === 'row' && (
                                <span className="text-content-muted mr-2 text-xs tabular-nums">
                                    {row.code}
                                </span>
                            )}
                            {row.drill !== null ? (
                                <a
                                    href={drillHref(row)}
                                    onClick={(event) => {
                                        event.preventDefault();
                                        router.get(drillHref(row));
                                    }}
                                    className="hover:text-brand focus-visible:outline-focus rounded-sm underline decoration-dotted underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2"
                                >
                                    {row.label}
                                </a>
                            ) : (
                                row.label
                            )}
                        </td>
                    );
                }

                const value = row.values[column.key] ?? null;

                return (
                    <td
                        key={column.key}
                        className={cn(
                            'px-4 py-1.5 text-right tabular-nums',
                            emphasised && 'text-content font-semibold',
                            value !== null && value.startsWith('-') && 'text-danger',
                        )}
                    >
                        {format(value, column, report.currency)}
                    </td>
                );
            })}
        </tr>
    );
}

/**
 * The general ledger, filtered to exactly the lines behind this figure.
 *
 * The bounds come from the row rather than from the screen's own filters,
 * because the figure was summed over the row's bounds — a balance sheet line
 * has no lower bound at all, and using the page's "from" would show a
 * different set of lines than the total was built from.
 */
function drillHref(row: Row): string {
    const query = new URLSearchParams({ account: row.drill?.account_id ?? '' });

    if (row.drill?.from != null) {
        query.set('from', row.drill.from);
    }

    if (row.drill?.to != null) {
        query.set('to', row.drill.to);
    }

    return `/accounting/general-ledger?${query.toString()}`;
}

function format(value: string | null, column: Column, currency: string): string {
    if (value === null || value === '') {
        return '';
    }

    if (column.kind === 'percent') {
        return `${value}%`;
    }

    if (column.kind === 'text') {
        return value;
    }

    return formatMoney(value, { currency, showCurrency: false });
}
