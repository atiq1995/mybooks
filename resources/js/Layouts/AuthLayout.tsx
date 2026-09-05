import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { LogoWithName } from '@/Components/Logo';

export interface AuthLayoutProps {
    title: string;
    description?: ReactNode;
    children: ReactNode;
    /** Shown under the card — usually a way back to sign-in. */
    footer?: ReactNode;
}

/**
 * The single-card shell for authentication screens other than sign-in.
 *
 * Sign-in keeps its own two-panel layout because it is the product's front
 * door; everything reached from it — reset, verify, two-factor, confirm — is
 * a focused single task and gets this narrower, quieter frame.
 */
export function AuthLayout({ title, description, children, footer }: AuthLayoutProps) {
    return (
        <div className="bg-surface-sunken flex min-h-dvh items-center justify-center px-6 py-10">
            <div className="w-full max-w-sm">
                <LogoWithName className="mb-8" />

                <div className="border-line-subtle bg-surface-base rounded-md border p-6">
                    <h1 className="text-content text-lg font-semibold">{title}</h1>

                    {description !== undefined && (
                        <p className="text-content-muted mt-1 text-sm">{description}</p>
                    )}

                    <div className="mt-5">{children}</div>
                </div>

                {footer ?? (
                    <Link
                        href="/login"
                        className="text-content-link mt-5 inline-flex items-center gap-1.5 text-sm hover:underline"
                    >
                        <ArrowLeft className="size-3.5" aria-hidden="true" />
                        Back to sign in
                    </Link>
                )}
            </div>
        </div>
    );
}
