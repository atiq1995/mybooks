import { forwardRef, useId } from 'react';
import type { SelectHTMLAttributes } from 'react';
import { AlertCircle, ChevronDown } from 'lucide-react';
import { cn } from '@/Utils/cn';

export interface SelectOption {
    value: string | number;
    label: string;
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> {
    label?: string | undefined;
    hint?: string | undefined;
    error?: string | undefined;
    options: readonly SelectOption[];
    /** Shown as a disabled first option when no value is chosen yet. */
    placeholder?: string | undefined;
    optional?: boolean;
    containerClassName?: string | undefined;
}

/**
 * A native select, styled to match {@see Input}.
 *
 * Native on purpose: on a phone this becomes the platform's own picker, which
 * is faster and more accessible than any custom listbox — and an accounting
 * app is filled with choose-one-of-twelve decisions where that matters more
 * than visual novelty. The searchable combobox for large sets (customers,
 * accounts) is a separate component.
 */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
    {
        label,
        hint,
        error,
        options,
        placeholder,
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
    const selectId = id ?? generatedId;
    const messageId = `${selectId}-message`;
    const hasError = typeof error === 'string' && error.length > 0;

    return (
        <div className={cn('flex flex-col gap-1', containerClassName)}>
            {label !== undefined && (
                <label htmlFor={selectId} className="text-content-secondary text-xs font-medium">
                    {label}
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

            <div className="relative">
                <select
                    ref={ref}
                    id={selectId}
                    required={required}
                    disabled={disabled}
                    aria-invalid={hasError || undefined}
                    aria-describedby={hasError || hint !== undefined ? messageId : undefined}
                    className={cn(
                        'h-8 w-full appearance-none rounded border pr-8 pl-2.5 text-base',
                        'bg-surface-base text-content transition-colors',
                        'focus:border-line-brand focus:ring-focus/25 focus:ring-2 focus:outline-none',
                        'disabled:bg-surface-disabled disabled:text-content-disabled disabled:cursor-not-allowed',
                        hasError ? 'border-line-danger focus:border-line-danger' : 'border-line',
                        className,
                    )}
                    {...props}
                >
                    {placeholder !== undefined && (
                        <option value="" disabled>
                            {placeholder}
                        </option>
                    )}

                    {options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>

                <ChevronDown
                    className="text-content-muted pointer-events-none absolute top-1/2 right-2.5 size-3.5 -translate-y-1/2"
                    aria-hidden="true"
                />
            </div>

            {(hasError || hint !== undefined) && (
                <p
                    id={messageId}
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
