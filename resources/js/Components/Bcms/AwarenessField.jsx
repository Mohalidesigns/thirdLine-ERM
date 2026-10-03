import { localInputToIso } from '@/Components/Bcms/dateInput';

/**
 * Shared by `Incidents/CrisisRoom.jsx` (the crisis-room reportability
 * banner) and `Incidents/NotificationLog.jsx` (`ClassifyForm`, "Classify a
 * new obligation") — the one place ADR 0020 Amendment 2 rules 5-7 are
 * implemented, so a fix to one does not silently miss the other (code-review
 * defect 4, found once on each screen).
 *
 * `awareness_at` DEFAULTS TO THE INCIDENT'S `detected_at`, FALLING BACK TO
 * `declared_at`, NEVER TO "NOW" — a clock that starts when somebody clicked
 * is often hours late on a busy night, and is the direction that lets a bank
 * buy itself regulatory time by delaying. Editable EARLIER with no
 * justification; editable LATER only with a required `awareness_reason`
 * (rule 6). `ClassifyBcmsIncidentRequest` validates both fields identically
 * regardless of `question` (cbn / personal_data), which is why one field
 * pair, not two, is correct here.
 */
export function awarenessDefault(incident) {
    return incident?.detected_at ?? incident?.declared_at ?? null;
}

export function isAwarenessLate(localValue, defaultAt) {
    if (!localValue || defaultAt == null) return false;

    const chosen = new Date(localInputToIso(localValue)).getTime();
    const fallback = new Date(defaultAt).getTime();

    return Number.isFinite(chosen) && Number.isFinite(fallback) && chosen > fallback;
}

export default function AwarenessField({
    value, onChange, reasonValue, onReasonChange, defaultAt,
    errors = {}, idPrefix = 'awareness',
    label = 'When was this first known? (used only if you answer Yes above; defaults to when the incident was detected)',
}) {
    const late = isAwarenessLate(value, defaultAt);
    const atId = `${idPrefix}_at`;
    const reasonId = `${idPrefix}_reason`;

    return (
        <div className="rounded border border-amber-200 bg-white p-2 text-xs text-slate-700">
            <label htmlFor={atId} className="block font-medium">{label}</label>
            <input id={atId} type="datetime-local" className="form-input mt-1 text-xs"
                value={value} onChange={(e) => onChange(e.target.value)} />
            {errors.awareness_at && <p role="alert" className="mt-1 text-rose-700">{errors.awareness_at}</p>}
            {late && (
                <div className="mt-2">
                    <label htmlFor={reasonId} className="block font-medium" aria-required="true">
                        Why is awareness later than detection?
                    </label>
                    <input id={reasonId} required className="form-input mt-1 w-full text-xs"
                        value={reasonValue} onChange={(e) => onReasonChange(e.target.value)} />
                    {errors.awareness_reason && <p role="alert" className="mt-1 text-rose-700">{errors.awareness_reason}</p>}
                </div>
            )}
        </div>
    );
}
