import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { cn } from '@/Utils/cn';
import type { SharedProps } from '@/Types/inertia';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { CommandPalette } from '@/Components/CommandPalette';
import { FlashMessages } from '@/Components/FlashMessages';

const SIDEBAR_STORAGE_KEY = 'my-books:sidebar-collapsed';

export interface AppLayoutProps {
    children: ReactNode;
    /** Page title, used for the document title and the header. */
    title: string;
    description?: string | undefined;
    /** Primary and secondary actions for this page. */
    actions?: ReactNode;
    breadcrumbs?: { label: string; href?: string }[] | undefined;
    /** Removes the default padding — for pages that manage their own. */
    flush?: boolean;
}

/**
 * The application shell: sidebar, top bar, and the content region.
 *
 * Everything signed-in renders inside this. The shell itself never scrolls —
 * only the content region does — so the navigation and the page's primary
 * actions stay reachable however long a ledger gets.
 */
export function AppLayout({
    children,
    title,
    description,
    actions,
    breadcrumbs,
    flush = false,
}: AppLayoutProps) {
    const page = usePage<SharedProps>();
    const { auth, organization, organizations } = page.props;

    const [collapsed, setCollapsed] = useState(readCollapsed);
    const [mobileNavOpen, setMobileNavOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);

    const toggleCollapsed = useCallback(() => {
        setCollapsed((value) => {
            const next = !value;
            try {
                localStorage.setItem(SIDEBAR_STORAGE_KEY, next ? '1' : '0');
            } catch {
                // Non-fatal; the choice simply will not persist.
            }
            return next;
        });
    }, []);

    // Ctrl/Cmd+K opens search from anywhere — including from inside a form,
    // which is exactly when someone needs to look up a customer.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setSearchOpen(true);
            }
        };

        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    // A route change must close the drawer, or the new page arrives underneath
    // it. Adjusted during render — React's documented pattern for state that
    // follows a changed prop — rather than in an effect.
    const [seenUrl, setSeenUrl] = useState(page.url);
    if (page.url !== seenUrl) {
        setSeenUrl(page.url);
        setMobileNavOpen(false);
    }

    if (auth.user === null) {
        return <>{children}</>;
    }

    return (
        <div className="bg-surface-sunken flex h-dvh overflow-hidden">
            {/* Desktop navigation */}
            <div className="hidden shrink-0 lg:block">
                <Sidebar
                    currentPath={page.url}
                    collapsed={collapsed}
                    onToggleCollapsed={toggleCollapsed}
                />
            </div>

            {/* Mobile navigation drawer */}
            {mobileNavOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <button
                        type="button"
                        aria-label="Close navigation"
                        onClick={() => setMobileNavOpen(false)}
                        className="absolute inset-0 bg-neutral-950/50"
                    />
                    <div className="shadow-modal relative h-full w-72">
                        <button
                            type="button"
                            onClick={() => setMobileNavOpen(false)}
                            aria-label="Close navigation"
                            className="absolute top-4 -right-10 rounded p-2 text-white"
                        >
                            <X className="size-5" aria-hidden="true" />
                        </button>
                        <Sidebar
                            currentPath={page.url}
                            collapsed={false}
                            onToggleCollapsed={toggleCollapsed}
                            mobile
                            onNavigate={() => setMobileNavOpen(false)}
                        />
                    </div>
                </div>
            )}

            <div className="flex min-w-0 flex-1 flex-col">
                <Topbar
                    user={auth.user}
                    organization={organization}
                    organizations={organizations}
                    onOpenSearch={() => setSearchOpen(true)}
                    onOpenMobileNav={() => setMobileNavOpen(true)}
                />

                {/* Only this region scrolls. */}
                <main className="flex-1 overflow-y-auto">
                    <div className={cn('max-w-page mx-auto w-full', !flush && 'px-4 py-5 sm:px-6')}>
                        <PageHeader
                            title={title}
                            description={description}
                            actions={actions}
                            breadcrumbs={breadcrumbs}
                        />
                        {children}
                    </div>
                </main>
            </div>

            <FlashMessages />

            <CommandPalette open={searchOpen} onClose={() => setSearchOpen(false)} />
        </div>
    );
}

function PageHeader({
    title,
    description,
    actions,
    breadcrumbs,
}: Pick<AppLayoutProps, 'title' | 'description' | 'actions' | 'breadcrumbs'>) {
    return (
        <div className="mb-5">
            {breadcrumbs !== undefined && breadcrumbs.length > 0 && (
                <nav aria-label="Breadcrumb" className="mb-1.5">
                    <ol className="text-content-muted flex flex-wrap items-center gap-1 text-xs">
                        {breadcrumbs.map((crumb, index) => (
                            <li key={crumb.label} className="flex items-center gap-1">
                                {index > 0 && <span aria-hidden="true">/</span>}
                                {crumb.href !== undefined ? (
                                    <a
                                        href={crumb.href}
                                        className="hover:text-content-link hover:underline"
                                    >
                                        {crumb.label}
                                    </a>
                                ) : (
                                    <span className="text-content-secondary">{crumb.label}</span>
                                )}
                            </li>
                        ))}
                    </ol>
                </nav>
            )}

            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-content text-xl font-semibold tracking-tight">{title}</h1>
                    {description !== undefined && (
                        <p className="text-content-muted mt-0.5 text-sm">{description}</p>
                    )}
                </div>

                {actions !== undefined && (
                    <div className="flex shrink-0 items-center gap-2">{actions}</div>
                )}
            </div>
        </div>
    );
}

function readCollapsed(): boolean {
    try {
        return localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}
