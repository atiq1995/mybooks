/**
 * Props shared with every Inertia page.
 *
 * These mirror App\Http\Middleware\HandleInertiaRequests::share(). Domain
 * types are GENERATED from PHP `Data` classes into `generated.d.ts` — do not
 * hand-write those. This file covers only the shell's own shared props, which
 * are frontend-shaped rather than domain-shaped.
 */

export type Theme = 'light' | 'dark' | 'system';
export type Density = 'compact' | 'comfortable';

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    initials: string;
    theme: Theme;
    density: Density;
    two_factor_enabled: boolean;
    email_verified: boolean;
}

export interface SharedOrganization {
    id: string;
    name: string;
    slug: string;
    base_currency: string;
    locale: string;
    date_format: string;
    onboarding_complete: boolean;
}

export interface OrganizationSummary {
    id: string;
    name: string;
    slug: string;
    role: string | null;
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
}

export interface SharedProps {
    app: { name: string; environment: string };
    auth: { user: AuthUser | null };
    organization: SharedOrganization | null;
    organizations: OrganizationSummary[];
    flash: FlashMessages;
    [key: string]: unknown;
}
