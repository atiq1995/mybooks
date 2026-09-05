import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronsUpDown,
    CircleHelp,
    LogOut,
    Menu,
    Moon,
    Plus,
    Search,
    Settings,
    Sun,
    User as UserIcon,
} from 'lucide-react';
import { cn } from '@/Utils/cn';
import { Button } from '@ui/Button';
import type { AuthUser, OrganizationSummary, SharedOrganization } from '@/Types/inertia';
import { useTheme } from '@/Hooks/useTheme';

export interface TopbarProps {
    user: AuthUser;
    organization: SharedOrganization | null;
    organizations: OrganizationSummary[];
    onOpenSearch: () => void;
    onOpenMobileNav: () => void;
}

/**
 * The persistent header: organisation context on the left, search in the
 * middle, account controls on the right.
 *
 * Search sits centre-stage rather than tucked in a corner, because in an
 * accounting product it is the fastest route to almost everything — finding
 * one invoice out of nine thousand beats navigating to a filtered list.
 */
export function Topbar({
    user,
    organization,
    organizations,
    onOpenSearch,
    onOpenMobileNav,
}: TopbarProps) {
    return (
        <header
            data-app-header
            className="h-topbar border-line-subtle bg-surface-base flex shrink-0 items-center gap-2 border-b px-3 sm:px-4"
        >
            <button
                type="button"
                onClick={onOpenMobileNav}
                aria-label="Open navigation"
                className="text-content-secondary hover:bg-surface-hover -ml-1 rounded p-2 lg:hidden"
            >
                <Menu className="size-5" aria-hidden="true" />
            </button>

            <OrganizationSwitcher organization={organization} organizations={organizations} />

            <SearchTrigger onClick={onOpenSearch} />

            <div className="ml-auto flex items-center gap-1">
                <Button
                    variant="primary"
                    size="sm"
                    icon={<Plus aria-hidden="true" />}
                    className="hidden sm:inline-flex"
                    onClick={onOpenSearch}
                >
                    New
                </Button>

                <ThemeToggle />

                <a
                    href="https://github.com/my-books/my-books"
                    target="_blank"
                    rel="noreferrer noopener"
                    aria-label="Help and documentation"
                    className="text-content-muted hover:bg-surface-hover hover:text-content rounded p-2 transition-colors"
                >
                    <CircleHelp className="size-4" aria-hidden="true" />
                </a>

                <UserMenu user={user} />
            </div>
        </header>
    );
}

// ---------------------------------------------------------------------------

function SearchTrigger({ onClick }: { onClick: () => void }) {
    // Ctrl on Windows and Linux, Command on macOS. Showing the wrong one is a
    // small thing that makes a product feel foreign. Read once, lazily — the
    // platform does not change mid-session.
    const [isMac] = useState(() => /Mac|iPhone|iPad/.test(navigator.userAgent));

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'border-line ml-1 hidden h-8 max-w-md flex-1 items-center gap-2 rounded border px-2.5 md:flex',
                'text-content-muted text-sm transition-colors',
                'hover:border-line-strong hover:bg-surface-hover',
                'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-2',
            )}
        >
            <Search className="size-3.5 shrink-0" aria-hidden="true" />
            <span className="flex-1 text-left">Search invoices, contacts, accounts…</span>
            <kbd className="border-line bg-surface-sunken text-2xs text-content-muted rounded-sm border px-1.5 py-0.5 font-mono">
                {isMac ? '⌘' : 'Ctrl'} K
            </kbd>
        </button>
    );
}

// ---------------------------------------------------------------------------

function OrganizationSwitcher({
    organization,
    organizations,
}: {
    organization: SharedOrganization | null;
    organizations: OrganizationSummary[];
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    useDismissable(ref, open, () => setOpen(false));

    if (organization === null) {
        return null;
    }

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-haspopup="menu"
                className={cn(
                    'flex h-8 items-center gap-2 rounded px-2 text-sm transition-colors',
                    'hover:bg-surface-hover',
                    'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-2',
                )}
            >
                <span className="bg-brand text-2xs text-content-on-brand flex size-5 items-center justify-center rounded-sm font-semibold">
                    {organization.name.charAt(0).toUpperCase()}
                </span>
                <span className="text-content max-w-[10rem] truncate font-medium">
                    {organization.name}
                </span>
                <span className="text-2xs text-content-muted hidden font-mono sm:inline">
                    {organization.base_currency}
                </span>
                <ChevronsUpDown className="text-content-muted size-3.5" aria-hidden="true" />
            </button>

            {open && (
                <div
                    role="menu"
                    className="border-line bg-surface-overlay shadow-overlay absolute top-full left-0 z-50 mt-1 w-64 overflow-hidden rounded-md border"
                >
                    <p className="border-line-subtle text-2xs text-content-muted border-b px-3 py-2 font-medium tracking-wide uppercase">
                        Organisations
                    </p>

                    <ul className="max-h-72 overflow-y-auto py-1">
                        {organizations.map((candidate) => (
                            <li key={candidate.id}>
                                <button
                                    type="button"
                                    role="menuitem"
                                    onClick={() => {
                                        setOpen(false);
                                        if (candidate.id !== organization.id) {
                                            router.post(`/organizations/${candidate.slug}/switch`);
                                        }
                                    }}
                                    className="hover:bg-surface-hover flex w-full items-center gap-2 px-3 py-1.5 text-sm"
                                >
                                    <span className="bg-surface-active text-2xs text-content-secondary flex size-5 shrink-0 items-center justify-center rounded-sm font-semibold">
                                        {candidate.name.charAt(0).toUpperCase()}
                                    </span>
                                    <span className="text-content flex-1 truncate text-left">
                                        {candidate.name}
                                    </span>
                                    {candidate.id === organization.id && (
                                        <Check
                                            className="text-brand size-3.5"
                                            aria-label="Current organisation"
                                        />
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>

                    <div className="border-line-subtle border-t p-1">
                        <Link
                            href="/organizations/create"
                            className="text-content-secondary hover:bg-surface-hover flex items-center gap-2 rounded px-2 py-1.5 text-sm"
                            onClick={() => setOpen(false)}
                        >
                            <Building2 className="size-3.5" aria-hidden="true" />
                            New organisation
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------

function ThemeToggle() {
    const { theme, setTheme } = useTheme();
    const next = theme === 'dark' ? 'light' : 'dark';

    return (
        <button
            type="button"
            onClick={() => setTheme(next)}
            aria-label={`Switch to ${next} theme`}
            className="text-content-muted hover:bg-surface-hover hover:text-content rounded p-2 transition-colors"
        >
            {theme === 'dark' ? (
                <Sun className="size-4" aria-hidden="true" />
            ) : (
                <Moon className="size-4" aria-hidden="true" />
            )}
        </button>
    );
}

// ---------------------------------------------------------------------------

function UserMenu({ user }: { user: AuthUser }) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    useDismissable(ref, open, () => setOpen(false));

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-haspopup="menu"
                aria-label="Account menu"
                className="bg-surface-active text-content-secondary hover:bg-surface-hover focus-visible:outline-focus flex size-8 items-center justify-center rounded-full text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                {user.initials}
            </button>

            {open && (
                <div
                    role="menu"
                    className="border-line bg-surface-overlay shadow-overlay absolute top-full right-0 z-50 mt-1 w-60 overflow-hidden rounded-md border"
                >
                    <div className="border-line-subtle border-b px-3 py-2.5">
                        <p className="text-content truncate text-sm font-medium">{user.name}</p>
                        <p className="text-content-muted truncate text-xs">{user.email}</p>
                        {!user.two_factor_enabled && (
                            <p className="text-2xs text-warning-600 mt-1.5">
                                Two-factor authentication is off
                            </p>
                        )}
                    </div>

                    <ul className="py-1">
                        <MenuLink href="/settings/profile" icon={UserIcon} label="Your profile" />
                        <MenuLink href="/settings" icon={Settings} label="Settings" />
                    </ul>

                    <div className="border-line-subtle border-t py-1">
                        <li className="list-none">
                            <button
                                type="button"
                                role="menuitem"
                                onClick={() => router.post('/logout')}
                                className="text-content-secondary hover:bg-surface-hover flex w-full items-center gap-2 px-3 py-1.5 text-sm"
                            >
                                <LogOut className="size-3.5" aria-hidden="true" />
                                Sign out
                            </button>
                        </li>
                    </div>
                </div>
            )}
        </div>
    );
}

function MenuLink({
    href,
    icon: Icon,
    label,
}: {
    href: string;
    icon: typeof Settings;
    label: string;
}) {
    return (
        <li>
            <Link
                href={href}
                role="menuitem"
                className="text-content-secondary hover:bg-surface-hover flex items-center gap-2 px-3 py-1.5 text-sm"
            >
                <Icon className="size-3.5" aria-hidden="true" />
                {label}
            </Link>
        </li>
    );
}

// ---------------------------------------------------------------------------

/**
 * Close on an outside click or on Escape.
 *
 * Escape matters as much as the click: a keyboard user who opened a menu must
 * be able to leave it without tabbing through every item.
 */
function useDismissable(
    ref: React.RefObject<HTMLElement | null>,
    active: boolean,
    onDismiss: () => void,
) {
    useEffect(() => {
        if (!active) return;

        const onPointerDown = (event: MouseEvent) => {
            if (ref.current !== null && !ref.current.contains(event.target as Node)) {
                onDismiss();
            }
        };

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onDismiss();
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [active, onDismiss, ref]);
}
