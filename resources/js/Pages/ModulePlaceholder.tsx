import { Head } from '@inertiajs/react';
import { Compass } from 'lucide-react';
import { AppLayout } from '@/Layouts/AppLayout';
import { Card } from '@ui/Card';
import { Badge } from '@ui/Badge';

interface ModulePlaceholderProps {
    module: string;
    section: string | null;
    phase: number;
    summary: string;
}

/**
 * Shown for a module the navigation advertises but that is not built yet.
 *
 * Deliberately quiet — this is not a feature, it is scaffolding, and it
 * should not look like an achievement. It answers the only two questions the
 * user actually has: what will be here, and when.
 */
export default function ModulePlaceholder({
    module,
    section,
    phase,
    summary,
}: ModulePlaceholderProps) {
    const title = section ?? module;

    return (
        <AppLayout
            title={title}
            description={section !== null ? `${module} · not yet built` : 'Not yet built'}
            breadcrumbs={section !== null ? [{ label: module }, { label: section }] : undefined}
        >
            <Head title={title} />

            <Card className="flex flex-col items-center px-6 py-16 text-center">
                <div className="bg-surface-active mb-4 flex size-10 items-center justify-center rounded-full">
                    <Compass className="text-content-muted size-5" aria-hidden="true" />
                </div>

                <Badge tone="neutral">Phase {phase}</Badge>

                <h2 className="text-content mt-3 text-lg font-semibold">{title} is on the way</h2>

                <p className="text-content-muted mt-2 max-w-md text-sm">{summary}</p>

                <p className="text-content-muted mt-6 max-w-md text-xs">
                    The navigation shows the full shape of the product from the start, so it does
                    not rearrange itself under you as each phase lands.
                </p>
            </Card>
        </AppLayout>
    );
}
