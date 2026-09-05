/**
 * Money formatting.
 *
 * The server sends decimal STRINGS, never JSON numbers, because a number in
 * JSON is a double and a double cannot hold 0.1 exactly. Nothing here ever
 * does arithmetic — the backend is authoritative for every figure. This
 * module only decides how an already-correct value looks.
 *
 * @see ACCOUNTING_RULES.md §2
 */

export type DecimalString = string;

export interface MoneyFormatOptions {
    /** ISO 4217 code, e.g. 'PKR'. */
    currency: string;
    /** BCP 47 tag; defaults to the organisation's locale. */
    locale?: string;
    /** Show the code/symbol. Off inside a column that is already labelled. */
    showCurrency?: boolean;
    /** Force sign display — useful in adjustment columns. */
    signDisplay?: Intl.NumberFormatOptions['signDisplay'];
}

/**
 * Format a decimal string for display.
 *
 * `Number(value)` here is safe and deliberate: it is the last step before
 * pixels, after every calculation has already happened in PHP at full
 * decimal precision. It is never fed back into a calculation.
 */
export function formatMoney(
    value: DecimalString | null | undefined,
    { currency, locale = 'en-PK', showCurrency = true, signDisplay = 'auto' }: MoneyFormatOptions,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const formatter = new Intl.NumberFormat(locale, {
        style: showCurrency ? 'currency' : 'decimal',
        currency: showCurrency ? currency : undefined,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay,
    });

    return formatter.format(Number(value));
}

/** Sign of a decimal string, without parsing it as a float. */
export function moneySign(value: DecimalString | null | undefined): -1 | 0 | 1 {
    if (!value) return 0;
    const trimmed = value.trim();
    if (trimmed.startsWith('-')) return -1;
    return /[1-9]/.test(trimmed) ? 1 : 0;
}

export function isNegative(value: DecimalString | null | undefined): boolean {
    return moneySign(value) === -1;
}
