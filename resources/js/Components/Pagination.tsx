import { Link } from '@inertiajs/react';

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/**
 * Laravel's own pagination links, rendered.
 *
 * The first and last entries are "Previous" and "Next"; the rest are page
 * numbers, with "..." arriving as an unlinked label. Laravel builds those
 * labels as HTML entities (`&laquo;`), which is why they are set as markup —
 * the strings come from the framework, never from a user.
 */
export function Pagination({ links }: { links: PaginationLink[] }) {
    // One page means the control says nothing worth the space.
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav aria-label="Pages" className="flex items-center gap-1">
            {links.map((link, index) =>
                link.url === null ? (
                    <span
                        key={index}
                        className="text-content-disabled px-2 py-1"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        className={
                            link.active
                                ? 'bg-brand text-content-on-brand rounded px-2 py-1'
                                : 'hover:bg-surface-hover text-content-secondary rounded px-2 py-1'
                        }
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}
