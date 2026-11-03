/**
 * Dates and words for the departmental KRI cycle (docs/ui/kri-cycle/00-common.md
 * section 2). The props carry Y-m-d strings; they are formatted WITHOUT a
 * timezone conversion, because the cycle is date-against-date (standard 14) and
 * `new Date('2026-11-09')` plus local formatting can shift the day.
 */

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function parts(ymd) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd ?? ''));

    if (!m) return null;

    const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
    const weekday = new Date(Date.UTC(y, mo - 1, d)).getUTCDay();

    return { y, mo, d, weekday };
}

/** "Mon 9 Nov 2026" */
export function formatDay(ymd) {
    const p = parts(ymd);

    return p ? `${WEEKDAYS[p.weekday]} ${p.d} ${MONTHS[p.mo - 1]} ${p.y}` : '';
}

/** "Mon 9 Nov" */
export function formatDayShort(ymd) {
    const p = parts(ymd);

    return p ? `${WEEKDAYS[p.weekday]} ${p.d} ${MONTHS[p.mo - 1]}` : '';
}

export function monthName(month) {
    return MONTH_NAMES[Number(month) - 1] ?? '';
}

/** Is this Y-m-d a Saturday or Sunday? Null for a blank or malformed date. */
export function isWeekend(ymd) {
    const p = parts(ymd);

    return p ? p.weekday === 0 || p.weekday === 6 : null;
}

export function yearOf(ymd) {
    return parts(ymd)?.y ?? null;
}

/** Today in Africa/Lagos as Y-m-d. NEVER new Date().toISOString(), which is UTC. */
export function lagosToday(now = new Date()) {
    const f = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Lagos', year: 'numeric', month: '2-digit', day: '2-digit' });

    return f.format(now);
}

/** "9 Nov 2026, 14:05" in Lagos time, from an ISO-8601 timestamp. */
export function formatLagosTime(iso) {
    if (!iso) return '';

    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) return '';

    const f = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Africa/Lagos', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
    });

    return f.format(d);
}

/** "14:05" in Lagos time. */
export function formatLagosClock(date = new Date()) {
    return new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Lagos', hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
}

export function plural(n, one, many) {
    return Number(n) === 1 ? one : (many ?? `${one}s`);
}

/* ------------------------------------------------------------------ */
/*  Vocabulary: one wording for every screen                           */
/* ------------------------------------------------------------------ */

/** display_status -> words, glyph and (static, purge-safe) classes. */
export const RETURN_STATUS = {
    submitted: { label: 'Submitted', glyph: 'check_circle', tone: 'bg-green-100 text-green-800' },
    overdue: { label: 'Overdue', glyph: 'warning', tone: 'bg-red-100 text-red-800' },
    reopened: { label: 'Reopened', glyph: 'lock_open', tone: 'bg-orange-100 text-orange-800' },
    in_progress: { label: 'In progress', glyph: 'edit_note', tone: 'bg-blue-100 text-blue-800' },
    not_started: { label: 'Not started', glyph: 'radio_button_unchecked', tone: 'bg-gray-100 text-gray-700' },
};

export const BAND_BADGE = {
    green: { label: 'Green', glyph: 'check_circle', tone: 'bg-green-100 text-green-800' },
    amber: { label: 'Amber', glyph: 'warning', tone: 'bg-yellow-100 text-yellow-800' },
    red: { label: 'Red', glyph: 'error', tone: 'bg-red-100 text-red-800' },
    none: { label: 'No band', glyph: 'remove', tone: 'bg-gray-100 text-gray-700' },
};

export const ROLE_LABELS = {
    risk_champion: 'Risk champion',
    team_lead: 'Team lead',
    unit_head: 'Unit head',
    group_head: 'Group head',
};

/** Who a reminder of this tier reaches (ADR section 7, cumulative). */
export const TIER_WORDS = {
    1: 'Risk champions',
    2: 'Risk champions and team leads',
    3: 'Risk champions, team leads and unit heads',
    4: 'All four levels, including group heads',
};

export function tierWords(tier) {
    return TIER_WORDS[Math.min(4, Math.max(1, Number(tier) || 1))];
}

export const NOTICE_STATUS = {
    queued: 'Queued',
    dispatching: 'Sending',
    sent: 'Handed to mail server',
    partially_failed: 'Some failed',
    failed: 'Failed',
    no_recipients: 'Nobody to send to',
    cancelled: 'Not sent: the return was submitted first',
};

export const RECIPIENT_STATUS = {
    pending: 'Waiting to send',
    sent: 'Handed to mail server',
    failed: 'Failed',
};

export function recipientStatusWords(status, skipReason) {
    if (status === 'skipped') {
        if (skipReason === 'inactive_user') return 'Skipped: account is inactive';
        if (skipReason === 'no_email') return 'Skipped: no email address';
        if (skipReason === 'return_submitted') return 'Not sent: the return was submitted first';

        return 'Skipped';
    }

    return RECIPIENT_STATUS[status] ?? status;
}

export const DELIVERY_SENTENCE =
    'Handed to mail server means our mail system accepted the message. It does not confirm it reached an inbox.';

/** A figure that is absent is a dash, never a zero. */
export function orDash(value) {
    return value === null || value === undefined || value === '' ? '—' : value;
}
