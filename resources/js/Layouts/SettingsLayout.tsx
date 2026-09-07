import type { ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Building2, Palette, ShieldCheck, User, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { AppLayout } from './AppLayout';
import { cn } from '@/Utils/cn';
import type { SharedProps } from '@/Types/inertia';

interface SettingsSection {
    label: string;
    href: string;
    icon: LucideIcon;
    description: string;
}

export interface SettingsLayoutProps {
    title: string;
    description?: string;
    children: ReactNode;
    /** Right-aligned page actions, passed through to the app shell. */
    actions?: ReactNode;
}

/**
 * Settings, split by whose settings they are.
 *
 * "You" changes follow the person between organisations; "This organisation"
 * changes affect everyone in it. Keeping that distinction visible in the
 * navigation prevents the most common settings mistake — changing something
 * for the whole company while believing it was a personal preference.
 */
const PERSONAL: SettingsSection[] = [
    {
        label: 'Profile',
        href: '/settings/profile',
        icon: User,
        description: 'Your name and contact details',
    },
    {
        label: 'Security',
        href: '/settings/security',
        icon: ShieldCheck,
        description: 'Password and two-factor',
    },
    {
        label: 'Appearance',
        href: '/settings/appearance-preferences',
        icon: Palette,
        description: 'Theme and density',
    },
];

const ORGANISATION: SettingsSection[] = [
    {
        label: 'Organisation',
        href: '/settings/organization',
        icon: Building2,
        description: 'Business details and defaults',
    },
    {
        label: 'People',
        href: '/settings/members',
        icon: Users,
        description: 'Who has access',
    },
];

export function SettingsLayout({ title, description, children, actions }: SettingsLayoutProps) {
    const page = usePage<SharedProps>();
    const organizationName = page.props.organization?.name;

    return (
        <AppLayout
            title={title}
            description={description}
            actions={actions}
            breadcrumbs={[{ label: 'Settings' }, { label: title }]}
        >
            <div className="grid gap-6 lg:grid-cols-[13rem_1fr]">
                <nav aria-label="Settings" className="flex flex-col gap-5">
                    <SectionGroup heading="You" sections={PERSONAL} currentPath={page.url} />
                    <SectionGroup
                        heading={organizationName ?? 'This organisation'}
                        sections={ORGANISATION}
                        currentPath={page.url}
                    />
                </nav>

                <div className="min-w-0">{children}</div>
            </div>
        </AppLayout>
    );
}

function SectionGroup({
    heading,
    sections,
    currentPath,
}: {
    heading: string;
    sections: SettingsSection[];
    currentPath: string;
}) {
    return (
        <div>
            <p className="text-content-muted text-2xs mb-1.5 px-2.5 font-medium tracking-wide uppercase">
                {heading}
            </p>

            <ul className="flex flex-col gap-0.5">
                {sections.map((section) => {
                    const active = currentPath.startsWith(section.href);

                    return (
                        <li key={section.href}>
                            <Link
                                href={section.href}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex items-start gap-2.5 rounded px-2.5 py-2 transition-colors',
                                    'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-1',
                                    active ? 'bg-surface-selected' : 'hover:bg-surface-hover',
                                )}
                            >
                                <section.icon
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0',
                                        active ? 'text-brand-text' : 'text-content-muted',
                                    )}
                                    aria-hidden="true"
                                />
                                <span className="min-w-0">
                                    <span className="text-content block text-sm font-medium">
                                        {section.label}
                                    </span>
                                    <span className="text-content-muted block text-xs">
                                        {section.description}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
