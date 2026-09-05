import type { ReactNode } from 'react';
import { cn } from '@/Utils/cn';
import { DOCUMENT_STATUS_TONE, statusLabel } from './statusTones';
import type { DocumentStatus } from './statusTones';

export type BadgeTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';

export interface BadgeProps {
    tone?: BadgeTone;
    children: ReactNode;
    /** Adds a filled dot. Use where the badge is the only status signal. */
    dot?: boolean;
    className?: string | undefined;
}

const TONES: Record<BadgeTone, string> = {
    neutral: 'bg-status-neutral text-status-neutral-fg border-status-neutral-line',
    success: 'bg-status-success text-status-success-fg border-status-success-line',
    warning: 'bg-status-warning text-status-warning-fg border-status-warning-line',
    danger: 'bg-status-danger text-status-danger-fg border-status-danger-line',
    info: 'bg-status-info text-status-info-fg border-status-info-line',
    brand: 'bg-brand-subtle text-brand-text border-line-brand',
};

const DOTS: Record<BadgeTone, string> = {
    neutral: 'bg-neutral-500',
    success: 'bg-success-500',
    warning: 'bg-warning-500',
    danger: 'bg-danger-500',
    info: 'bg-info-500',
    brand: 'bg-brand',
};

export function Badge({ tone = 'neutral', dot = false, children, className }: BadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-sm border px-1.5 py-0.5',
                'text-2xs font-medium whitespace-nowrap',
                TONES[tone],
                className,
            )}
        >
            {dot && <span className={cn('size-1.5 rounded-full', DOTS[tone])} aria-hidden="true" />}
            {children}
        </span>
    );
}

export function StatusBadge({
    status,
    className,
}: {
    status: DocumentStatus;
    className?: string | undefined;
}) {
    return (
        <Badge tone={DOCUMENT_STATUS_TONE[status]} dot className={className}>
            {statusLabel(status)}
        </Badge>
    );
}
