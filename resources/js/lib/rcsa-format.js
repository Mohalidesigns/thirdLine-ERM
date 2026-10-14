/**
 * How the RCSA screens write a score and a count.
 *
 * ONE FORMATTER, because the grid read 3.8 while the cycle dashboard read 3.75
 * for the same residual (RCSA-07). The engine rounds to two decimal places and
 * nowhere else (rcsa-calc.js), so the screen shows exactly that — two places,
 * trailing zeros trimmed: 3.75, 2.25, 8, 16. Rounding again to one place on
 * the way to the glass is how two pages come to disagree about one number.
 *
 * A missing score is an em dash, never 0: an unassessed risk has no score, and
 * a zero would read as "assessed, and harmless".
 */
export function formatScore(score) {
    if (score === null || score === undefined || score === '') return '—';

    const value = Number(score);

    if (!Number.isFinite(value)) return '—';

    // parseFloat on the 2-dp string drops the trailing zeros; String() then
    // never produces an exponent for anything a 5x5 matrix can reach.
    return String(parseFloat(value.toFixed(2)));
}

/**
 * "1 risk", "2 risks" (RCSA-08). The plural is passed when it is not just an
 * "s" away, so a caller never has to guess how a noun inflects.
 */
export function pluralise(count, singular, plural = `${singular}s`) {
    return `${count} ${Number(count) === 1 ? singular : plural}`;
}
