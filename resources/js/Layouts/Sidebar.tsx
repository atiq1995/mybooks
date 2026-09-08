import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { cn } from '@/Utils/cn';
import { CURRENT_PHASE, NAVIGATION, isActive, isAvailable } from './navigation';
import type { NavSection } from './navigation';
import { Logo } from '@/Components/Logo';

export interface SidebarProps {
    currentPath: string;
    collapsed: boolean;
    onToggleCollapsed: () => void;
    /** Mobile: the sidebar is a drawer rather than a rail. */
    mobile?: boolean;
    onNavigate?: (() => void) | undefined;
}

/**
 * Primary navigation.
 *
 * Dark chrome against the light workspace, so the content area reads as the
 * bright, focused part of the screen and the navigation recedes once learned.
 *
 * Collapsing to an icon rail matters more here than in most products: an
 * invoice line-item grid wants every pixel of width it can get.
 */
export function Sidebar({
    currentPath,
    collapsed,
    onToggleCollapsed,
    mobile = false,
    onNavigate,
}: SidebarProps) {
    // A section opens when it contains the current page, so a deep link lands
    // with its context already expanded.
    const [openSections, setOpenSections] = useState<string[]>(() =>
        NAVIGATION.filter(
            (section) => section.items !== undefined && isActive(currentPath, section.match),
        ).map((section) => section.label),
    );

    const toggleSection = (label: string) => {
        setOpenSections((open) =>
            open.includes(label) ? open.filter((item) => item !== label) : [...open, label],
        );
    };

    const showLabels = !collapsed || mobile;

    return (
        <nav
            aria-label="Main"
            className={cn(
                'bg-surface-sidebar flex h-full flex-col',
                mobile ? 'w-72' : collapsed ? 'w-sidebar-collapsed' : 'w-sidebar',
                'transition-[width] duration-150 ease-out motion-reduce:transition-none',
            )}
        >
            {/* Brand */}
            <div
                className={cn(
                    'h-topbar flex shrink-0 items-center border-b border-white/8',
                    showLabels ? 'justify-between px-3' : 'justify-center px-2',
                )}
            >
                <Link
                    href="/dashboard"
                    className="flex items-center gap-2 rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/60"
                    onClick={() => onNavigate?.()}
                >
                    <Logo className="size-6 shrink-0" />
                    {showLabels && (
                        <span className="text-md font-semibold tracking-tight text-white">
                            My Books
                        </span>
                    )}
                </Link>

                {!mobile && showLabels && (
                    <button
                        type="button"
                        onClick={onToggleCollapsed}
                        aria-label="Collapse sidebar"
                        className="text-content-sidebar-muted hover:bg-surface-sidebar-hover rounded p-1 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-white/60"
                    >
                        <PanelLeftClose className="size-4" aria-hidden="true" />
                    </button>
                )}
            </div>

            {/* Sections */}
            <div className="flex-1 overflow-y-auto overscroll-contain px-2 py-3">
                <ul className="flex flex-col gap-0.5">
                    {NAVIGATION.map((section) => (
                        <SidebarSection
                            key={section.label}
                            section={section}
                            currentPath={currentPath}
                            showLabels={showLabels}
                            open={openSections.includes(section.label)}
                            onToggle={() => toggleSection(section.label)}
                            onNavigate={onNavigate}
                        />
                    ))}
                </ul>
            </div>

            {/* Collapsed rail keeps a way back out */}
            {!mobile && collapsed && (
                <div className="border-t border-white/8 p-2">
                    <button
                        type="button"
                        onClick={onToggleCollapsed}
                        aria-label="Expand sidebar"
                        className="text-content-sidebar-muted hover:bg-surface-sidebar-hover flex w-full items-center justify-center rounded p-1.5 transition-colors hover:text-white focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-white/60"
                    >
                        <PanelLeftOpen className="size-4" aria-hidden="true" />
                    </button>
                </div>
            )}

            {/* Development-only: which phase the product is at. Removed in production. */}
            {import.meta.env.DEV && showLabels && (
                <div className="border-t border-white/8 px-3 py-2">
                    <p className="text-2xs text-content-sidebar-muted font-mono">
                        Phase {CURRENT_PHASE} · Sales
                    </p>
                </div>
            )}
        </nav>
    );
}

interface SidebarSectionProps {
    section: NavSection;
    currentPath: string;
    showLabels: boolean;
    open: boolean;
    onToggle: () => void;
    onNavigate?: (() => void) | undefined;
}

function SidebarSection({
    section,
    currentPath,
    showLabels,
    open,
    onToggle,
    onNavigate,
}: SidebarSectionProps) {
    const active = isActive(currentPath, section.match);
    const Icon = section.icon;

    const rowClasses = cn(
        'group flex w-full items-center gap-2.5 rounded px-2 py-1.5 text-sm transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-white/60',
        active
            ? 'bg-surface-sidebar-active font-medium text-content-sidebar-active'
            : 'text-content-sidebar hover:bg-surface-sidebar-hover hover:text-white',
        !showLabels && 'justify-center px-0',
    );

    // Leaf: a section with no children is a single destination.
    if (section.items === undefined) {
        return (
            <li>
                <SidebarLink
                    href={section.match}
                    available={isAvailable(section.phase)}
                    phase={section.phase}
                    className={rowClasses}
                    title={!showLabels ? section.label : undefined}
                    onNavigate={onNavigate}
                >
                    <Icon className="size-4 shrink-0" aria-hidden="true" />
                    {showLabels && <span className="truncate">{section.label}</span>}
                </SidebarLink>
            </li>
        );
    }

    // Collapsed rail: the group header navigates rather than expanding, since
    // there is nowhere to show children.
    if (!showLabels) {
        return (
            <li>
                <SidebarLink
                    href={section.match}
                    available={isAvailable(section.phase)}
                    phase={section.phase}
                    className={rowClasses}
                    title={section.label}
                    onNavigate={onNavigate}
                >
                    <Icon className="size-4 shrink-0" aria-hidden="true" />
                </SidebarLink>
            </li>
        );
    }

    return (
        <li>
            <button type="button" onClick={onToggle} aria-expanded={open} className={rowClasses}>
                <Icon className="size-4 shrink-0" aria-hidden="true" />
                <span className="flex-1 truncate text-left">{section.label}</span>
                <ChevronDown
                    className={cn(
                        'size-3.5 shrink-0 transition-transform duration-150 motion-reduce:transition-none',
                        open && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
            </button>

            {open && (
                <ul className="mt-0.5 mb-1 ml-[1.4rem] flex flex-col gap-0.5 border-l border-white/10 pl-2">
                    {section.items.map((item) => {
                        const itemActive = isActive(currentPath, item.match);

                        return (
                            <li key={item.label}>
                                <SidebarLink
                                    href={item.match}
                                    available={isAvailable(item.phase)}
                                    phase={item.phase}
                                    onNavigate={onNavigate}
                                    className={cn(
                                        'flex items-center rounded px-2 py-1 text-sm transition-colors',
                                        'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-white/60',
                                        itemActive
                                            ? 'bg-surface-sidebar-active text-content-sidebar-active font-medium'
                                            : 'text-content-sidebar hover:bg-surface-sidebar-hover hover:text-white',
                                    )}
                                >
                                    <span className="truncate">{item.label}</span>
                                </SidebarLink>
                            </li>
                        );
                    })}
                </ul>
            )}
        </li>
    );
}

interface SidebarLinkProps {
    href: string;
    available: boolean;
    phase: number;
    className: string;
    title?: string | undefined;
    children: React.ReactNode;
    onNavigate?: (() => void) | undefined;
}

/**
 * A navigation entry that is honest about whether its module exists yet.
 *
 * Unbuilt modules still navigate — to a designed placeholder that says which
 * phase delivers them. A visibly dead link reads as a broken product; a
 * placeholder reads as one under construction, which is the truth.
 */
function SidebarLink({
    href,
    available,
    phase,
    className,
    title,
    children,
    onNavigate,
}: SidebarLinkProps) {
    return (
        <Link
            href={href}
            className={cn(className, !available && 'opacity-55')}
            title={title ?? (available ? undefined : `Arrives in Phase ${phase}`)}
            onClick={() => onNavigate?.()}
        >
            {children}
        </Link>
    );
}
