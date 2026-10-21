/**
 * A KRI reading with its unit of measure, as a person would write it.
 *
 * The unit is free text on the KRI form, so it is a symbol as often as a
 * word: "%" belongs against the number ("99.1%"), but "count", "days" or
 * "percentage" need a space ("4 count", not "4count" — E2E run 2026-10-18).
 * A symbol is any unit with no letter in it (%, ‰, ₦); bps, count and days
 * are words.
 */
export function withUnit(value, unit) {
    if (value === null || value === undefined || value === '') return '—';

    const u = (unit ?? '').trim();

    if (u === '') return `${value}`;

    return /\p{L}/u.test(u) ? `${value} ${u}` : `${value}${u}`;
}
