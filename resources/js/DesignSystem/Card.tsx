import type { ReactNode } from 'react';
import { cn } from '@/Utils/cn';

export interface CardProps {
    children: ReactNode;
    className?: string;
    /** Removes inner padding. For a card whose body is a full-bleed table. */
    flush?: boolean;
}

/**
 * A bordered container.
 *
 * Border and background only — no shadow by default. Shadows are reserved for
 * things that genuinely float above the page (dropdowns, dialogs). A grid of
 * shadowed cards flattens the hierarchy it was meant to create, because
 * everything is lifted and so nothing is.
 */
export function Card({ children, className, flush = false }: CardProps) {
    return (
        <div
            className={cn(
                'border-line-subtle bg-surface-raised rounded-md border',
                !flush && 'p-4',
                className,
            )}
        >
            {children}
        </div>
    );
}

export interface CardHeaderProps {
    title: ReactNode;
    description?: ReactNode;
    /** Right-aligned actions. Keep to one primary, and prefer a menu beyond two. */
    actions?: ReactNode;
    className?: string;
}

export function CardHeader({ title, description, actions, className }: CardHeaderProps) {
    return (
        <div
            className={cn(
                'border-line-subtle flex items-start justify-between gap-4 border-b px-4 py-3',
                className,
            )}
        >
            <div className="min-w-0">
                <h2 className="text-md text-content truncate font-semibold">{title}</h2>
                {description !== undefined && (
                    <p className="text-content-muted mt-0.5 text-xs">{description}</p>
                )}
            </div>
            {actions !== undefined && (
                <div className="flex shrink-0 items-center gap-2">{actions}</div>
            )}
        </div>
    );
}

export function CardBody({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('p-4', className)}>{children}</div>;
}

export function CardFooter({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div
            className={cn(
                'border-line-subtle bg-surface-sunken flex items-center justify-end gap-2 border-t px-4 py-3',
                className,
            )}
        >
            {children}
        </div>
    );
}
