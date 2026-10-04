/**
 * The one formatter for every incident timestamp shown to a user — due_at,
 * awareness_at, submitted_at, detected_at, declared_at, closed_at,
 * activated_at and decision-log entry times, across the crisis room, the
 * notification log, stand-down, the incident list, the post-incident review
 * and the DR invocation record.
 *
 * THE DEFECT THIS REPLACES: every one of those screens used to render an
 * incident timestamp its own way — some ran it through `new Date(iso)
 * .toLocaleString()` (the viewer's own zone, unlabelled), others sliced the
 * raw ISO string's characters directly (`iso.slice(0, 16)`, the SERVER's UTC
 * digits, printed as if they were the viewer's own, with no conversion at
 * all). A viewer in Lagos (UTC+1) reading a crisis-room countdown tile
 * (converted) against a notification-log row (sliced) saw the same CBN
 * deadline an hour apart. One formatter, applied everywhere an incident
 * timestamp is shown, is what stops that recurring.
 *
 * EVERY INCIDENT TIMESTAMP THE SERVER SENDS IS `Carbon::toIso8601String()`
 * — ISO 8601 WITH AN OFFSET (e.g. `2026-09-15T08:13:00+00:00`), verified
 * against `IncidentPresenter` as of this change — so `new Date(iso)` parses
 * it correctly and `Intl.DateTimeFormat` below renders it in the VIEWER's
 * own local zone, never the server's. The one exception found is named in
 * this phase's handoff rather than assumed correct: `dr_invocations.
 * {uuid}.failback.at` (`DrInvocation.jsx`) is read straight out of a JSON
 * blob (`bcms_aars.quantitative_results`) with no `toIso8601String()` cast
 * and no store route yet built to say what wrote it — this formatter is
 * applied to it too, on the working assumption every other incident
 * timestamp already holds, but that field's actual shape is unverified.
 */
export function formatIncidentDateTime(iso, { compact = false } = {}) {
    if (!iso) return null;

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) return null;

    return new Intl.DateTimeFormat(undefined, compact
        ? { hour: '2-digit', minute: '2-digit', hour12: false }
        : {
            day: 'numeric', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: false,
            timeZoneName: 'short',
        }).format(date);
}
