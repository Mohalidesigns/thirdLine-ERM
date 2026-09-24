/**
 * `<input type="datetime-local">` carries no timezone — the value is the
 * browser's own local wall-clock time as a bare string. This app's server
 * runs in UTC (`config('app.timezone')`), so a bare "2026-09-14T08:56"
 * posted as-is is parsed as 08:56 UTC, not 08:56 local — on any tenant not
 * itself in UTC this silently fails every `before_or_equal:now` check the
 * incident screens rely on (`DeclareBcmsIncidentRequest`,
 * `ClassifyBcmsIncidentRequest`). These two helpers are the one place that
 * conversion happens, so every incident screen's datetime fields agree.
 */
export function toLocalInput(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function localInputToIso(value) {
    if (!value) return value;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toISOString();
}
