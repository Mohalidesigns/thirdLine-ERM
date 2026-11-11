/**
 * Date and decimal formatting for the Scenario Modelling screens.
 *
 * TWO RULES FROM THE SPECS LIVE HERE AND NOWHERE ELSE, because both are the kind
 * of thing that is correct in one component and wrong in the next:
 *
 * 1. `_on` VALUES ARE LAGOS CALENDAR DAYS, NOT INSTANTS (spec 01 §4.1, standard
 *    §14). `designed_on`, `validated_on` and `review_due_on` arrive as `Y-m-d`
 *    strings and are formatted WITHOUT a timezone conversion — split the string
 *    and build the date with `Date.UTC`, then format with `timeZone: 'UTC'`.
 *    `new Date('2026-11-09')` plus local formatting shifts the day for every user
 *    west of Greenwich, and the browser never decides whether a date is past:
 *    `review.days_overdue` is computed on the server.
 *
 * 2. PERCENTS ARE SUMMED AS SCALED INTEGERS (spec 03 §5.2). They arrive as
 *    decimal strings at `decimal(5,2)`; adding them as floats gives
 *    `33.33 + 33.33 + 33.34 = 100.00000000000001`, and a screen that says
 *    "Adds to 100%" while the server answers 422 on `allocations` destroys trust
 *    in both. Parse to cents, total the integers, compare to 10 000.
 */

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * `2026-11-09` → `Mon 9 Nov 2026`, with no timezone conversion.
 *
 * Returns `—` for a null or unparseable value: an unset date renders an em dash,
 * never "0 days" and never blank (spec 01 §0.5).
 */
export function formatDay(value) {
    if (!value || typeof value !== 'string') {
        return '—';
    }

    const parts = value.split('-');

    if (parts.length !== 3) {
        return '—';
    }

    const year = Number(parts[0]);
    const month = Number(parts[1]);
    const day = Number(parts[2]);

    if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
        return '—';
    }

    // UTC in, UTC out: the calendar day survives the round trip untouched.
    const date = new Date(Date.UTC(year, month - 1, day));

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return `${DAY_NAMES[date.getUTCDay()]} ${date.getUTCDate()} ${MONTH_NAMES[date.getUTCMonth()]} ${date.getUTCFullYear()}`;
}

const LAGOS = 'Africa/Lagos';

const lagosFormatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: LAGOS,
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

/**
 * An instant (`updated_at`, `signed_at`, a note's `created_at`) →
 * `09 Nov 2026, 14:22 Lagos time`.
 *
 * The words "Lagos time" are part of the string rather than a separate caption
 * so the first use on a page carries them and a screen-reader user hears the
 * zone rather than guessing it (spec 01 §4.1, spec 02 §7.2).
 */
export function formatLagosInstant(value) {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts = lagosFormatter.formatToParts(date).reduce((acc, part) => {
        acc[part.type] = part.value;
        return acc;
    }, {});

    return `${parts.day} ${parts.month} ${parts.year}, ${parts.hour}:${parts.minute} Lagos time`;
}

/** `2026-11-09T14:22:00+01:00` → `14:22`, for the "Last saved" line. */
export function formatLagosClock(value) {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts = lagosFormatter.formatToParts(date).reduce((acc, part) => {
        acc[part.type] = part.value;
        return acc;
    }, {});

    return `${parts.hour}:${parts.minute}`;
}

/**
 * A percent string (`"40.00"`, `"33.33"`) in hundredths, or `null` when the row
 * carries no percentage at all.
 *
 * `null` and `0` are DIFFERENT and stay different: an empty percent is *missing*
 * and is counted as missing in the sum line, while `0` is a real answer meaning
 * "this line is in scope but carries none of the impact" (spec 03 §5.1, §5.2).
 */
export function percentToCents(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return null;
    }

    return Math.round(number * 100);
}

/**
 * The live allocation sum.
 *
 * @returns {{cents: number, missing: number}} `cents` over the rows that DO
 *          carry a percentage, and `missing` counting the rows that do not.
 *          A row with an empty percent is never read as `0`, so the sum shown
 *          is never a lie about what has been entered (spec 03 §5.2).
 */
export function sumPercents(percents) {
    let cents = 0;
    let missing = 0;

    for (const value of percents) {
        const scaled = percentToCents(value);

        if (scaled === null) {
            missing += 1;
            continue;
        }

        cents += scaled;
    }

    return { cents, missing };
}

/** Hundredths → the shortest true decimal string (`10000` → `"100"`, `7550` → `"75.5"`). */
export function centsToPercentString(cents) {
    const whole = Math.trunc(cents / 100);
    const fraction = Math.abs(cents % 100);

    if (fraction === 0) {
        return String(whole);
    }

    return `${whole}.${String(fraction).padStart(2, '0').replace(/0$/, '')}`;
}

/** The exact sum-to-100 comparison, in scaled integers rather than floats. */
export function isExactly100(cents) {
    return cents === 10000;
}

/**
 * A `decimal(18,8)` rate string trimmed of trailing zeros for READING only —
 * `"1210.44000000"` → `"1210.44"`.
 *
 * Never used to produce a stored value: spec 02 §5.6 chose 18,8 to match
 * `fx_rates.rate` exactly because "narrower silently rounds a figure an examiner
 * recomputes", and the trim happens on the way to the screen and not on the way
 * back to the server.
 */
export function trimDecimal(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const text = String(value);

    if (!text.includes('.')) {
        return text;
    }

    return text.replace(/0+$/, '').replace(/\.$/, '');
}

/** Thousands separators for a character count — `"3412"` → `"3 412"`. */
export function groupDigits(value) {
    return String(value).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/** `1` → `''`, anything else → `'s'`, for the specs' `{n} item{s}` copy. */
export function plural(count) {
    return count === 1 ? '' : 's';
}
