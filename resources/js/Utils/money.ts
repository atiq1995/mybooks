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

/** True when a decimal string is exactly zero, without parsing it as a float. */
export function isZero(value: DecimalString | null | undefined): boolean {
    return moneySign(value) === 0;
}

// ---------------------------------------------------------------------------
// Display arithmetic
// ---------------------------------------------------------------------------

/*
 * Everything below adds up decimal strings for the SCREEN only.
 *
 * The backend is authoritative for every stored figure and recomputes each one
 * in PHP at full decimal precision. These exist for the two places a total has
 * to appear before a round trip — the running totals on a journal form, and a
 * group subtotal on a report whose authoritative total already came from the
 * server — and their results are never sent back as an amount.
 *
 * They live here rather than in a page so that the whole application's
 * float-adjacent code is in one file, under one explanation, and the lint rule
 * that bans Number.parseFloat elsewhere keeps its meaning.
 *
 * Results are returned as decimal strings at four places, matching
 * numeric(19,4), so a caller cannot accidentally keep using the double.
 */

const SCALE = 4;

export function sumForDisplay(
    values: readonly (DecimalString | null | undefined)[],
): DecimalString {
    let total = 0;

    for (const value of values) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        const parsed = Number(value);

        if (Number.isFinite(parsed)) {
            total += parsed;
        }
    }

    return total.toFixed(SCALE);
}

export function subtractForDisplay(a: DecimalString, b: DecimalString): DecimalString {
    return (Number(a) - Number(b)).toFixed(SCALE);
}

export function absForDisplay(value: DecimalString): DecimalString {
    return Math.abs(Number(value)).toFixed(SCALE);
}

/**
 * Whether a typed amount is a usable positive figure.
 *
 * Rejects blanks, non-numeric text, zero and negatives — a line amount must be
 * positive, because a negative debit is a credit and permitting both spellings
 * makes every report ambiguous.
 */
export function isPositiveAmount(value: string): boolean {
    const trimmed = value.trim();

    if (trimmed === '') {
        return false;
    }

    const parsed = Number(trimmed);

    return Number.isFinite(parsed) && parsed > 0;
}

export function multiplyForDisplay(a: DecimalString, b: DecimalString): DecimalString {
    return (Number(a) * Number(b)).toFixed(SCALE);
}

/**
 * Division for display, at rate precision rather than money precision.
 *
 * Ten places, because the result of this is usually multiplied again — a
 * percentage turned into a fraction, say — and rounding it to money scale
 * first would lose the part that matters.
 */
export function divideForDisplay(a: DecimalString, b: DecimalString): DecimalString {
    const divisor = Number(b);

    return divisor === 0 ? '0' : (Number(a) / divisor).toFixed(10);
}
