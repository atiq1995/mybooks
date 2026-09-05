import { cn } from '@/Utils/cn';

/**
 * The My Books mark.
 *
 * A ledger page split down the middle: debit on the left, credit on the
 * right, with the two sides drawn at equal weight. Original work — the
 * product's identity is entirely its own.
 */
export function Logo({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 32 32"
            fill="none"
            className={cn('shrink-0', className)}
            role="img"
            aria-label="My Books"
        >
            <rect x="2" y="3" width="28" height="26" rx="5" className="fill-brand-600" />

            {/* The dividing rule — the line between the two columns */}
            <path
                d="M16 9v14"
                className="stroke-brand-100"
                strokeWidth="1.75"
                strokeLinecap="round"
            />

            {/* Debit entries */}
            <path
                d="M7.5 13.5h5M7.5 18h5"
                className="stroke-brand-100"
                strokeWidth="1.75"
                strokeLinecap="round"
            />

            {/* Credit entries — same count, same weight: the sides balance */}
            <path
                d="M19.5 13.5h5M19.5 18h5"
                className="stroke-brand-100"
                strokeWidth="1.75"
                strokeLinecap="round"
            />
        </svg>
    );
}

/** Mark plus wordmark, for the login screen and printed documents. */
export function LogoWithName({ className }: { className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-2', className)}>
            <Logo className="size-7" />
            <span className="text-content text-lg font-semibold tracking-tight">My Books</span>
        </span>
    );
}
