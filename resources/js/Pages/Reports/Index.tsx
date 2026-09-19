import { Head, router } from '@inertiajs/react';
import { ArrowRight, ChartNoAxesColumn } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Card } from '@ui/Card';

interface ReportLink {
    title: string;
    summary: string;
    href: string;
}

interface ReportGroup {
    label: string;
    description: string;
    reports: ReportLink[];
}

interface IndexProps {
    groups: ReportGroup[];
    baseCurrency: string;
    organizationName: string;
}

/**
 * Every report in the product, in one place.
 *
 * Including the ones that arrived with earlier phases and live under
 * Accounting, Sales and Purchases. Somebody looking for "the reports" should
 * not have to know which module happened to build each one.
 *
 * Each card says what its report ANSWERS rather than restating its title,
 * because choosing between eleven reports is the actual task on this screen.
 */
export default function Index({ groups, baseCurrency, organizationName }: IndexProps) {
    return (
        <AppLayout
            title="Reports"
            description={`${organizationName} · amounts in ${baseCurrency}`}
            breadcrumbs={[{ label: 'Reports' }]}
        >
            <Head title="Reports" />

            <div className="flex flex-col gap-6">
                {groups.map((group) => (
                    <section key={group.label} className="flex flex-col gap-3">
                        <div>
                            <h2 className="text-content text-md font-semibold">{group.label}</h2>
                            <p className="text-content-muted text-xs">{group.description}</p>
                        </div>

                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {group.reports.map((report) => (
                                <Card key={report.href} className="p-0">
                                    <a
                                        href={report.href}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            router.get(report.href);
                                        }}
                                        className="group focus-visible:outline-focus flex h-full items-start justify-between gap-3 rounded-md p-4 focus-visible:outline-2 focus-visible:outline-offset-2"
                                    >
                                        <span className="min-w-0">
                                            <span className="text-content block text-sm font-medium">
                                                {report.title}
                                            </span>
                                            <span className="text-content-muted mt-0.5 block text-xs">
                                                {report.summary}
                                            </span>
                                        </span>

                                        <ChartNoAxesColumn
                                            className="text-content-muted mt-0.5 size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                    </a>
                                </Card>
                            ))}
                        </div>
                    </section>
                ))}

                <p className="text-content-muted flex items-center gap-1.5 text-xs">
                    <ArrowRight className="size-3.5" aria-hidden="true" />
                    Every figure on a statement links to the journal lines behind it.
                </p>
            </div>
        </AppLayout>
    );
}
