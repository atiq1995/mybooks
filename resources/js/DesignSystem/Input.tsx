import { forwardRef, useId } from 'react';
import type { InputHTMLAttributes, ReactNode } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/Utils/cn';

// `prefix` is omitted from the base because HTML defines it as an RDFa string
// attribute; ours is a rendered adornment.
export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size' | 'prefix'> {
    label?: string | undefined;
    /** Guidance shown before the user makes a mistake, not after. */
    hint?: string | undefined;
    /** Server- or client-side validation message. Replaces the hint. */
    error?: string | undefined;
    /** Adornment inside the field, e.g. a currency code or a search icon. */
    prefix?: ReactNode;
    suffix?: ReactNode;
    /** Right-aligns and applies tabular figures. For money and quantities. */
    numeric?: boolean;
    optional?: boolean;
    containerClassName?: string | undefined;
}

/**
 * Text input with its label, hint and error wired together.
 *
 * The wiring is the point. A bare `<input>` plus a separate `<p>` of error
 * text looks identical and is silent to a screen reader — here the error is
 * announced, `aria-invalid` is set, and `aria-describedby` points at whichever
 * of hint or error is actually showing.
 */
export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
    {
        label,
        hint,
        error,
        prefix,
        suffix,
        numeric = false,
        optional = false,
        required,
        disabled,
        className,
        containerClassName,
        id,
        ...props
    },
    ref,
) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const messageId = `${inputId}-message`;
    const hasError = typeof error === 'string' && error.length > 0;

    return (
        <div className={cn('flex flex-col gap-1', containerClassName)}>
            {label !== undefined && (
                <label htmlFor={inputId} className="text-content-secondary text-xs font-medium">
                    {label}
                    {/* Marking what is optional rather than what is required:
                        in a form where most fields are mandatory, asterisks
                        everywhere carry no information. */}
                    {optional && (
                        <span className="text-content-muted ml-1 font-normal">(optional)</span>
                    )}
                    {required === true && !optional && (
                        <span className="text-danger-600 ml-0.5" aria-hidden="true">
                            *
                        </span>
                    )}
                </label>
            )}

            <div
                className={cn(
                    'bg-surface-base flex items-center gap-1.5 rounded border px-2.5',
                    'h-8 transition-colors',
                    'focus-within:border-line-brand focus-within:ring-focus/25 focus-within:ring-2',
                    hasError
                        ? 'border-line-danger focus-within:border-line-danger focus-within:ring-danger-500/25'
                        : 'border-line',
                    disabled === true && 'bg-surface-disabled cursor-not-allowed',
                )}
            >
                {prefix !== undefined && (
                    <span className="text-content-muted shrink-0 text-xs select-none">
                        {prefix}
                    </span>
                )}

                <input
                    ref={ref}
                    id={inputId}
                    required={required}
                    disabled={disabled}
                    aria-invalid={hasError || undefined}
                    aria-describedby={hasError || hint !== undefined ? messageId : undefined}
                    // inputMode surfaces the numeric keypad on touch devices,
                    // which matters a great deal on a mobile expense form.
                    inputMode={numeric ? 'decimal' : props.inputMode}
                    className={cn(
                        'text-content min-w-0 flex-1 bg-transparent text-base',
                        'placeholder:text-content-disabled',
                        'disabled:text-content-disabled focus:outline-none disabled:cursor-not-allowed',
                        numeric && 'text-right tabular-nums',
                        className,
                    )}
                    {...props}
                />

                {suffix !== undefined && (
                    <span className="text-content-muted shrink-0 text-xs select-none">
                        {suffix}
                    </span>
                )}
            </div>

            {(hasError || hint !== undefined) && (
                <p
                    id={messageId}
                    // Errors are announced when they appear; hints are not, so
                    // a hint does not interrupt someone mid-field.
                    role={hasError ? 'alert' : undefined}
                    className={cn(
                        'flex items-start gap-1 text-xs',
                        hasError ? 'text-danger-600' : 'text-content-muted',
                    )}
                >
                    {hasError && (
                        <AlertCircle className="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                    )}
                    {hasError ? error : hint}
                </p>
            )}
        </div>
    );
});
