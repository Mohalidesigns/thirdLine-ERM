/**
 * How a capital figure is rendered when it is not there (migration Phase 5.2).
 *
 * MISSING STAYS MISSING. These four reports are printed for a board and filed
 * with the CBN, and a zero in a capital column is a statement about the bank
 * rather than a placeholder — 0% CAR is a specific, catastrophic claim. Every
 * figure the services return is `null` when it could not be computed, and it
 * has to survive the whole way to the screen as an explicit absence.
 *
 * The Blade views these replace did this with a pair of closures at the top of
 * each file; the same rule lives here once so the four pages cannot drift.
 */

/** The two absences the reports distinguish. */
export const NOT_RECORDED = 'Not recorded';
export const NOT_ASSESSED = 'Not assessed';

export const isMissing = (value) => value === null || value === undefined;

/** Naira, to two decimals. Returns null so the caller renders the absence. */
export function naira(value) {
    if (isMissing(value)) return null;

    return `₦${Number(value).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** A percentage, to two decimals. */
export function percent(value) {
    if (isMissing(value)) return null;

    return `${Number(value).toFixed(2)}%`;
}

/** A percentage with its trailing zeroes trimmed: 15.00 -> "15", 12.50 -> "12.5". */
export function trimmedPercent(value) {
    if (isMissing(value)) return null;

    return String(Number(Number(value).toFixed(2)));
}

/** A plain count or ordinal total — NOT money, so it gets no currency sign. */
export function number(value, decimals = 0) {
    if (isMissing(value)) return null;

    return Number(value).toLocaleString('en-NG', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}
