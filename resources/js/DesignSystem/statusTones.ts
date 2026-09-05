import type { BadgeTone } from './Badge';

/**
 * Document statuses, mapped to a tone once.
 *
 * Every list, detail page and email in the product renders a status through
 * this map, so "overdue" is the same red everywhere and adding a status is a
 * single edit rather than a search for every switch statement.
 *
 * Colour is never the only signal — the label always carries the meaning too.
 *
 * Lives apart from the Badge component so the component file exports only
 * components, which is what keeps React fast-refresh working.
 */
export const DOCUMENT_STATUS_TONE = {
    draft: 'neutral',
    sent: 'info',
    viewed: 'info',
    partially_paid: 'warning',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
    reversed: 'neutral',
    open: 'info',
    approved: 'success',
    pending_approval: 'warning',
    rejected: 'danger',
    reconciled: 'success',
    unreconciled: 'neutral',
    active: 'success',
    archived: 'neutral',
    invited: 'warning',
    suspended: 'danger',
} as const satisfies Record<string, BadgeTone>;

export type DocumentStatus = keyof typeof DOCUMENT_STATUS_TONE;

/** "partially_paid" → "Partially Paid" */
export function statusLabel(status: DocumentStatus): string {
    return status
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}
