import type { ComponentType, ReactNode } from 'react';
import { AlertTriangle, RefreshCw, SearchX } from 'lucide-react';
import { cn } from '@/Utils/cn';
import { Button } from './Button';

/**
 * The four states every data view has to answer for.
 *
 * They are separate components because they are separate situations with
 * separate correct responses, and collapsing them is how a user ends up
 * being told "No invoices" when what actually happened is that a filter
 * excluded all of them, or the request failed.
 *
 *   Loading   — we are fetching
 *   Empty     — there is genuinely nothing here yet    → offer the first action
 *   NoResults — there is data, this filter matched none → offer to clear it
 *   Error     — something broke                         → offer to retry
 */

// ---------------------------------------------------------------------------
// Loading
// ---------------------------------------------------------------------------

export function Skeleton({ className }: { className?: string }) {
    return (
        <div
            className={cn(
                'bg-surface-active animate-pulse rounded motion-reduce:animate-none',
                className,
            )}
            // Decorative: the surrounding region announces "loading", so each
            // individual bar should not.
            aria-hidden="true"
        />
    );
}

export interface TableSkeletonProps {
    rows?: number;
    columns?: number;
}

/**
 * Placeholder rows matched to the real table's density, so content does not
 * jump when it arrives. Varying widths avoid the mechanical look of identical
 * grey bars.
 */
export function TableSkeleton({ rows = 8, columns = 5 }: TableSkeletonProps) {
    const widths = ['w-32', 'w-24', 'w-40', 'w-20', 'w-28', 'w-16'];

    return (
        <div role="status" aria-label="Loading" className="divide-line-subtle divide-y">
            {Array.from({ length: rows }, (_, rowIndex) => (
                <div
                    key={rowIndex}
                    className="flex items-center gap-4 px-3"
                    style={{ height: 'var(--row-height)' }}
                >
                    {Array.from({ length: columns }, (_, columnIndex) => (
                        <Skeleton
                            key={columnIndex}
                            className={cn('h-3', widths[columnIndex % widths.length])}
                        />
                    ))}
                </div>
            ))}
            <span className="sr-only">Loading results</span>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Empty / no results / error
// ---------------------------------------------------------------------------

interface StateShellProps {
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean | 'true' | 'false' }>;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    tone?: 'neutral' | 'danger';
}

function StateShell({ icon: Icon, title, description, action, tone = 'neutral' }: StateShellProps) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
            <div
                className={cn(
                    'mb-3 flex size-10 items-center justify-center rounded-full',
                    tone === 'danger' ? 'bg-status-danger' : 'bg-surface-active',
                )}
            >
                <Icon
                    className={cn(
                        'size-5',
                        tone === 'danger' ? 'text-status-danger-fg' : 'text-content-muted',
                    )}
                    aria-hidden="true"
                />
            </div>

            <h3 className="text-md text-content font-semibold">{title}</h3>

            {description !== undefined && (
                <p className="text-content-muted mt-1 max-w-sm text-sm">{description}</p>
            )}

            {action !== undefined && <div className="mt-4 flex items-center gap-2">{action}</div>}
        </div>
    );
}

export interface EmptyStateProps {
    icon: StateShellProps['icon'];
    title: string;
    description?: ReactNode;
    action?: ReactNode;
}

/**
 * Nothing exists yet. This is a first-run moment, so it names the thing and
 * offers the action that creates one — never a shrug.
 */
export function EmptyState(props: EmptyStateProps) {
    return <StateShell {...props} />;
}

export interface NoResultsStateProps {
    /** What was searched for, so the message can name it. */
    query?: string | undefined;
    onClear?: (() => void) | undefined;
}

/**
 * Data exists; this filter matched none of it. Distinct from EmptyState,
 * because the useful action here is to widen the search, not to create a
 * record the user may already have.
 */
export function NoResultsState({ query, onClear }: NoResultsStateProps) {
    return (
        <StateShell
            icon={SearchX}
            title="No matches"
            description={
                query !== undefined && query !== '' ? (
                    <>
                        Nothing matched <span className="text-content font-medium">“{query}”</span>.
                        Try a different search, or widen your filters.
                    </>
                ) : (
                    'No records match the filters you have applied.'
                )
            }
            action={
                onClear !== undefined ? (
                    <Button variant="secondary" size="sm" onClick={onClear}>
                        Clear filters
                    </Button>
                ) : undefined
            }
        />
    );
}

export interface ErrorStateProps {
    title?: string;
    description?: ReactNode;
    onRetry?: () => void;
}

/**
 * Something failed.
 *
 * Says what happened and offers the way forward. No apology, no raw exception
 * text — a stack trace tells the user nothing and tells an attacker something.
 */
export function ErrorState({
    title = 'This could not be loaded',
    description = 'The request did not complete. This is usually temporary.',
    onRetry,
}: ErrorStateProps) {
    return (
        <StateShell
            icon={AlertTriangle}
            tone="danger"
            title={title}
            description={description}
            action={
                onRetry !== undefined ? (
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={<RefreshCw aria-hidden="true" />}
                        onClick={onRetry}
                    >
                        Try again
                    </Button>
                ) : undefined
            }
        />
    );
}
