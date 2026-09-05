import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Merge Tailwind classes with later ones winning.
 *
 * Plain string concatenation leaves both `px-2` and `px-4` in the class list
 * and lets source order in the stylesheet decide — which is invisible at the
 * call site and impossible to override from a prop. `twMerge` resolves the
 * conflict the way the caller expects.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
