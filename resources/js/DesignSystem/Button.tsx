import { forwardRef } from 'react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Loader2 } from 'lucide-react';
import { cn } from '@/Utils/cn';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'> {
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** Shows a spinner and blocks interaction. Use for real pending work. */
    loading?: boolean;
    /** Rendered before the label. Decorative — give the button a real label. */
    icon?: ReactNode;
    iconRight?: ReactNode;
    fullWidth?: boolean;
    className?: string;
}

/**
 * Exactly one visual language for actions, used everywhere.
 *
 * Five variants, and only one of them — `primary` — is filled. A screen with
 * three filled buttons has told the user nothing about which one to press,
 * which in an accounting workflow is how a draft gets posted by accident.
 */
const VARIANTS: Record<ButtonVariant, string> = {
    primary: cn(
        'bg-brand text-content-on-brand shadow-raised',
        'hover:bg-brand-hover active:bg-brand-active',
        'disabled:bg-surface-disabled disabled:text-content-disabled disabled:shadow-none',
    ),
    secondary: cn(
        'bg-surface-base text-content border border-line',
        'hover:bg-surface-hover active:bg-surface-active',
        'disabled:bg-surface-disabled disabled:text-content-disabled',
    ),
    ghost: cn(
        'bg-transparent text-content-secondary',
        'hover:bg-surface-hover hover:text-content active:bg-surface-active',
        'disabled:text-content-disabled disabled:hover:bg-transparent',
    ),
    // Destructive actions are outlined, not filled: a red block draws the eye
    // towards the one action that should be hardest to press by accident.
    danger: cn(
        'bg-surface-base text-danger-600 border border-line-danger',
        'hover:bg-danger-50 active:bg-danger-100',
        'dark:hover:bg-danger-900/30 dark:active:bg-danger-900/50',
        'disabled:border-line disabled:text-content-disabled disabled:hover:bg-surface-base',
    ),
    link: cn(
        'bg-transparent text-content-link underline underline-offset-2 decoration-1',
        'hover:decoration-2 disabled:text-content-disabled disabled:no-underline',
    ),
};

const SIZES: Record<ButtonSize, string> = {
    sm: 'h-7 px-2.5 text-xs gap-1.5 rounded-sm',
    md: 'h-8 px-3 text-base gap-2 rounded',
    lg: 'h-10 px-4 text-md gap-2 rounded-md',
};

const ICON_SIZE: Record<ButtonSize, string> = {
    sm: '[&_svg]:size-3.5',
    md: '[&_svg]:size-4',
    lg: '[&_svg]:size-4',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    {
        variant = 'secondary',
        size = 'md',
        loading = false,
        icon,
        iconRight,
        fullWidth = false,
        disabled,
        children,
        type = 'button',
        ...props
    },
    ref,
) {
    const isDisabled = disabled === true || loading;

    return (
        <button
            ref={ref}
            // Defaulting to "button" rather than the HTML default of "submit"
            // — an unlabelled button inside a form submitting it is a classic
            // source of accidental saves.
            type={type}
            disabled={isDisabled}
            // `disabled` alone is not announced as a state change to a screen
            // reader mid-request; aria-busy is.
            aria-busy={loading || undefined}
            className={cn(
                'inline-flex items-center justify-center font-medium whitespace-nowrap',
                'transition-colors duration-100',
                'focus-visible:outline-focus focus-visible:outline-2 focus-visible:outline-offset-2',
                'disabled:cursor-not-allowed',
                VARIANTS[variant],
                SIZES[size],
                ICON_SIZE[size],
                fullWidth && 'w-full',
                props.className,
            )}
            {...props}
        >
            {loading ? (
                <Loader2 className="animate-spin motion-reduce:animate-none" aria-hidden="true" />
            ) : (
                icon
            )}
            {children}
            {!loading && iconRight}
        </button>
    );
});
