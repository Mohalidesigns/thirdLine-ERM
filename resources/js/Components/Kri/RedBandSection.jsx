import { useCallback, useEffect, useRef, useState } from 'react';
import InputError from '@thirdline/ui/Components/InputError';
import Glyph from './Glyph';
import { formatDay } from '@/lib/kri-format';

/**
 * The red-band explanation (docs/ui/kri-cycle/08, ADR 0026 section 6): a red
 * value cannot be saved without a root cause, an action point and a timeline.
 *
 * Three placements share this: Edit KRI (Target Value), Record Measurement on
 * Kri/Show, and Complete my KRIs. A copy per screen would drift - the exact
 * failure the ADR records in BandPreview.
 *
 * What the host owns, and why:
 *  - the typed text (`values`), in ITS state, so the section can be unmounted
 *    when the band stops being red and the text is still there when it returns;
 *  - whether the band is red (`bandFor` from lib/kri-band.js);
 *  - the payload: nothing typed here is sent unless the band is red
 *    (`redBandPayload`).
 *
 * The section is NOT in the DOM while the band is not red - not display:none,
 * which some screen readers still read when toggled.
 */

const INPUT = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D] focus:border-[#1A365D]';
const FIELDS = ['root_cause', 'action_point', 'timeline', 'timeline_change_reason'];

export function emptyRedBand(seed = {}) {
    return {
        root_cause: seed.root_cause ?? '',
        action_point: seed.action_point ?? '',
        timeline: seed.timeline ?? '',
        owner_id: seed.owner_id ?? '',
        timeline_change_reason: seed.timeline_change_reason ?? '',
    };
}

/** Has the user typed anything at all? (Optional mode only posts when they have.) */
export function anyFilled(values) {
    return FIELDS.some((f) => String(values?.[f] ?? '').trim() !== '');
}

/** The reason is asked for only when an open Issue exists and its date is changing. */
export function reasonNeeded(values, openIssue) {
    return Boolean(openIssue?.due_date && values?.timeline && values.timeline !== openIssue.due_date);
}

/**
 * Client-side mirror of the server's rules. The server's 422 is always honoured
 * and shown through the same slots; this only saves a round trip.
 *
 * @returns {Record<string,string>} field -> message
 */
export function validateRedBand(values, { openIssue = null, today }) {
    const errors = {};

    if (!String(values.root_cause ?? '').trim()) errors.root_cause = 'Enter the root cause.';
    if (!String(values.action_point ?? '').trim()) errors.action_point = 'Enter the action point.';

    if (!values.timeline) {
        errors.timeline = 'Choose a date for the timeline.';
    } else if (today && values.timeline < today && values.timeline !== openIssue?.due_date) {
        errors.timeline = 'Choose today or a later date.';
    }

    if (reasonNeeded(values, openIssue) && !String(values.timeline_change_reason ?? '').trim()) {
        errors.timeline_change_reason = 'Say why the date is changing. This is recorded on the Issue.';
    }

    return errors;
}

/**
 * The `red_band` request payload, or undefined. Hosts call this only when the
 * band is red; hidden text is never sent.
 */
export function redBandPayload(values, openIssue = null) {
    const out = {
        root_cause: values.root_cause ?? '',
        action_point: values.action_point ?? '',
        timeline: values.timeline ?? '',
    };

    if (values.owner_id !== '' && values.owner_id !== null && values.owner_id !== undefined) out.owner_id = Number(values.owner_id);
    if (reasonNeeded(values, openIssue) && String(values.timeline_change_reason ?? '').trim()) {
        out.timeline_change_reason = values.timeline_change_reason;
    }

    return out;
}

/**
 * The live region and the debounce (08 section 3.3). The region is MOUNTED with
 * the host, empty, because a live region inserted together with its text is
 * unreliable. Announcements fire after 700 ms idle or on blur, whichever is
 * first, and only when redness differs from the last thing announced - so
 * typing "1", "12", "120" does not chatter.
 *
 * Focus is never moved while the user types. It moves on Tab (DOM order puts
 * the section right after the value) or on Enter (`onValueKeyDown`).
 */
export function useRedBandHost({ isRed, serverForced = false, messages, idleMs = 700 }) {
    const [message, setMessage] = useState('');
    const announced = useRef(isRed); // nothing is announced on load
    const timer = useRef(null);
    const latest = useRef({ isRed, messages });
    const sectionRef = useRef(null);
    const forcedAnnounced = useRef(false);

    latest.current = { isRed, messages };

    const flush = useCallback(() => {
        clearTimeout(timer.current);
        const { isRed: now, messages: m } = latest.current;

        if (now !== announced.current) {
            announced.current = now;
            setMessage(now ? m.enter : m.leave);
        }
    }, []);

    useEffect(() => {
        clearTimeout(timer.current);

        if (isRed !== announced.current) {
            timer.current = setTimeout(flush, idleMs);
        }

        return () => clearTimeout(timer.current);
    }, [isRed, idleMs, flush]);

    // Server-driven reveal (3.5): the server says red where the client did not.
    useEffect(() => {
        if (serverForced && !forcedAnnounced.current) {
            forcedAnnounced.current = true;
            setMessage(latest.current.messages.forced);
        }

        if (!serverForced) forcedAnnounced.current = false;
    }, [serverForced]);

    const focusSection = useCallback(() => {
        const root = sectionRef.current;

        if (!root) return false;

        const fields = [...root.querySelectorAll('[data-redband-field]')];
        const target = fields.find((el) => el.getAttribute('aria-invalid') === 'true')
            ?? fields.find((el) => String(el.value ?? '').trim() === '')
            ?? fields[0];

        target?.focus();

        return Boolean(target);
    }, []);

    /** Enter in the value field: move into the section instead of submitting, when it is shown and incomplete. */
    const onValueKeyDown = useCallback((event, incomplete = true) => {
        if (event.key === 'Enter' && latest.current.isRed && incomplete) {
            if (focusSection()) event.preventDefault();
        }
    }, [focusSection]);

    const liveRegion = (
        <div role="status" aria-live="polite" aria-atomic="true" className="sr-only">{message}</div>
    );

    return { liveRegion, sectionRef, flush, focusSection, onValueKeyDown, show: isRed || serverForced };
}

function ErrorLine({ id, message }) {
    if (!message) return null;

    return (
        <p id={id} className="mt-1 flex items-start gap-1 text-xs text-red-700">
            <Glyph name="error" className="!h-4 !w-4" />
            <span>{message}</span>
        </p>
    );
}

function Label({ htmlFor, children, required }) {
    return (
        <label htmlFor={htmlFor} className="mb-1 block text-xs font-medium text-gray-700">
            {children}
            {required && (
                <>
                    {' '}
                    <span aria-hidden="true" className="text-red-600">*</span>
                    <span className="sr-only"> (required)</span>
                </>
            )}
        </label>
    );
}

function Counter({ value, max }) {
    const left = max - String(value ?? '').length;

    if (left > 500) return null;

    return <p className="mt-1 text-xs text-gray-600">{left} characters left</p>;
}

/**
 * @param {object}   props
 * @param {string}   props.idPrefix        unique per instance on the page
 * @param {string}   props.title           the group heading
 * @param {'target'|'reading'} props.context  wording only
 * @param {object}   props.values          {root_cause, action_point, timeline, owner_id, timeline_change_reason}
 * @param {(field:string, value:string)=>void} props.onChange
 * @param {Record<string,string>} [props.errors]  keys WITHOUT any line prefix
 * @param {object|null} [props.openIssue]  {reference, status, due_date, action_plan, observation, is_overdue, url}
 * @param {object|null} [props.openBreach] Edit only: {period_label, root_cause}
 * @param {{id:number,name:string}[]|null} [props.ownerOptions] null omits the owner field
 * @param {string}   [props.ownerNote]     shown instead of the owner field when it is omitted
 * @param {string}   props.lagosToday      Y-m-d
 * @param {boolean}  [props.required]      false = optional mode (Edit, A1.11)
 * @param {string}   [props.intro]
 * @param {boolean}  [props.readOnly]
 * @param {React.Ref} [props.sectionRef]
 */
export default function RedBandSection({
    idPrefix,
    title,
    context = 'reading',
    values,
    onChange,
    errors = {},
    openIssue = null,
    openBreach = null,
    ownerOptions = null,
    ownerNote = null,
    lagosToday,
    required = true,
    intro = null,
    readOnly = false,
    sectionRef = null,
}) {
    const id = (f) => `${idPrefix}-${f}`;
    const err = (f) => `${idPrefix}-${f}-error`;
    const titleId = `${idPrefix}-title`;
    const showReason = reasonNeeded(values, openIssue) || Boolean(errors.timeline_change_reason);
    const pastDate = values.timeline && lagosToday && values.timeline < lagosToday && values.timeline !== openIssue?.due_date;

    const describedBy = (f, extra) => [errors[f] ? err(f) : null, extra].filter(Boolean).join(' ') || undefined;

    if (readOnly) {
        return (
            <div role="group" aria-labelledby={titleId} className="mt-3 rounded-lg border border-red-200 border-l-4 border-l-[#C53030] bg-red-50 p-4">
                <h3 id={titleId} className="text-sm font-semibold text-red-900">{title}</h3>
                <dl className="mt-2 space-y-2 text-sm text-gray-800">
                    <div><dt className="text-xs font-medium text-gray-700">Root cause</dt><dd className="whitespace-pre-line">{values.root_cause || 'None recorded.'}</dd></div>
                    <div><dt className="text-xs font-medium text-gray-700">Action point</dt><dd className="whitespace-pre-line">{values.action_point || 'None recorded.'}</dd></div>
                    <div><dt className="text-xs font-medium text-gray-700">Timeline</dt><dd>{values.timeline ? formatDay(values.timeline) : 'None recorded.'}</dd></div>
                    {ownerOptions && values.owner_id !== '' && values.owner_id !== null && (
                        <div><dt className="text-xs font-medium text-gray-700">Action owner</dt><dd>{ownerOptions.find((o) => String(o.id) === String(values.owner_id))?.name ?? 'Not shown'}</dd></div>
                    )}
                </dl>
            </div>
        );
    }

    const writes = openIssue
        ? `Saving will update Issue ${openIssue.reference}. Its status and priority don't change.`
        : 'Saving will raise a new high-priority Issue with this action point and due date. The root cause is also recorded on the breach.';

    return (
        <div
            ref={sectionRef}
            role="group"
            aria-labelledby={titleId}
            className="mt-3 rounded-lg border border-red-200 border-l-4 border-l-[#C53030] bg-red-50 p-4"
        >
            <h3 id={titleId} className="flex items-start gap-2 text-sm font-semibold text-red-900">
                <Glyph name="warning" className="mt-0.5" />
                <span>{title}</span>
            </h3>

            {intro && <p className="mt-2 text-sm text-gray-800">{intro}</p>}
            <p className="mt-2 text-sm text-gray-800">
                {writes}
                {context === 'target' && !openBreach && !openIssue && ' No breach is open for this KRI, so the root cause is recorded on the Issue.'}
            </p>

            {context === 'target' && openBreach && (
                <div className="mt-2 text-sm text-gray-800">
                    <p>The root cause will also be saved on the open red breach for {openBreach.period_label}.</p>
                    {openBreach.root_cause && (
                        <button type="button" onClick={() => onChange('root_cause', openBreach.root_cause)} className="btn-secondary mt-2 text-sm">
                            Start from the current root cause
                        </button>
                    )}
                </div>
            )}

            {openIssue && (
                <div className="mt-3 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-800">
                    <p className="font-semibold text-gray-900">This KRI already has an open Issue</p>
                    <p className="mt-1">
                        {openIssue.url
                            ? <a href={openIssue.url} target="_blank" rel="noopener noreferrer" className="font-mono font-medium text-[#1A365D] underline">{openIssue.reference}<span className="sr-only"> (opens in a new tab)</span></a>
                            : <span className="font-mono font-medium">{openIssue.reference}</span>}
                        {' · '}{String(openIssue.status ?? '').replace(/_/g, ' ').toLowerCase()}
                        {openIssue.due_date && <> {'·'} due {formatDay(openIssue.due_date)}</>}
                        {openIssue.is_overdue && <span className="ml-2 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">Overdue</span>}
                    </p>
                    <p className="mt-1">Saving updates this Issue. A second one is never opened.</p>
                    {openIssue.is_overdue && <p className="mt-1">This Issue is overdue. Keeping the same date is allowed. It stays overdue and keeps being chased.</p>}
                    {(openIssue.action_plan || openIssue.observation) && (
                        <details className="mt-2">
                            <summary className="cursor-pointer text-[#1A365D] underline">Current action plan and root cause</summary>
                            {openIssue.observation && <p className="mt-1 whitespace-pre-line"><span className="font-medium">Root cause: </span>{openIssue.observation}</p>}
                            {openIssue.action_plan && <p className="mt-1 whitespace-pre-line"><span className="font-medium">Action point: </span>{openIssue.action_plan}</p>}
                        </details>
                    )}
                    <button
                        type="button"
                        className="btn-secondary mt-2 text-sm"
                        onClick={() => {
                            onChange('root_cause', openIssue.observation ?? '');
                            onChange('action_point', openIssue.action_plan ?? '');
                            onChange('timeline', openIssue.due_date ?? '');
                        }}
                    >
                        Use the existing action
                    </button>
                </div>
            )}

            <div className="mt-3 space-y-4 rounded-lg bg-white p-3">
                <div>
                    <Label htmlFor={id('root_cause')} required={required}>Root cause</Label>
                    <textarea
                        id={id('root_cause')}
                        data-redband-field="root_cause"
                        rows={3}
                        maxLength={5000}
                        value={values.root_cause}
                        onChange={(e) => onChange('root_cause', e.target.value)}
                        required={required}
                        aria-required={required}
                        aria-invalid={errors.root_cause ? 'true' : undefined}
                        aria-describedby={describedBy('root_cause')}
                        className={INPUT}
                    />
                    <Counter value={values.root_cause} max={5000} />
                    <ErrorLine id={err('root_cause')} message={errors.root_cause} />
                </div>

                <div>
                    <Label htmlFor={id('action_point')} required={required}>Action point</Label>
                    <textarea
                        id={id('action_point')}
                        data-redband-field="action_point"
                        rows={3}
                        maxLength={5000}
                        value={values.action_point}
                        onChange={(e) => onChange('action_point', e.target.value)}
                        required={required}
                        aria-required={required}
                        aria-invalid={errors.action_point ? 'true' : undefined}
                        aria-describedby={describedBy('action_point')}
                        className={INPUT}
                    />
                    <Counter value={values.action_point} max={5000} />
                    <ErrorLine id={err('action_point')} message={errors.action_point} />
                </div>

                <div>
                    <Label htmlFor={id('timeline')} required={required}>Timeline for resolution</Label>
                    <input
                        id={id('timeline')}
                        data-redband-field="timeline"
                        type="date"
                        min={lagosToday}
                        value={values.timeline}
                        onChange={(e) => onChange('timeline', e.target.value)}
                        required={required}
                        aria-required={required}
                        aria-invalid={errors.timeline || pastDate ? 'true' : undefined}
                        aria-describedby={describedBy('timeline', pastDate && !errors.timeline ? `${idPrefix}-timeline-past` : undefined)}
                        className={INPUT}
                    />
                    {pastDate && !errors.timeline && <p id={`${idPrefix}-timeline-past`} className="mt-1 text-xs text-red-700">Choose today or a later date.</p>}
                    <ErrorLine id={err('timeline')} message={errors.timeline} />
                </div>

                {showReason && (
                    <div>
                        <Label htmlFor={id('timeline_change_reason')} required>Reason for changing the timeline</Label>
                        <input
                            id={id('timeline_change_reason')}
                            data-redband-field="timeline_change_reason"
                            type="text"
                            maxLength={500}
                            value={values.timeline_change_reason}
                            onChange={(e) => onChange('timeline_change_reason', e.target.value)}
                            required
                            aria-required="true"
                            aria-invalid={errors.timeline_change_reason ? 'true' : undefined}
                            aria-describedby={describedBy('timeline_change_reason', `${idPrefix}-reason-help`)}
                            className={INPUT}
                        />
                        <p id={`${idPrefix}-reason-help`} className="mt-1 text-xs text-gray-600">Why is the date changing? This is recorded on the Issue.</p>
                        <ErrorLine id={err('timeline_change_reason')} message={errors.timeline_change_reason} />
                    </div>
                )}

                {ownerOptions ? (
                    <div>
                        <Label htmlFor={id('owner_id')}>Action owner</Label>
                        <select
                            id={id('owner_id')}
                            value={values.owner_id ?? ''}
                            onChange={(e) => onChange('owner_id', e.target.value)}
                            aria-invalid={errors.owner_id ? 'true' : undefined}
                            aria-describedby={describedBy('owner_id')}
                            className={INPUT}
                        >
                            <option value="">{openIssue ? 'Keep the current owner' : 'Me (the person saving)'}</option>
                            {ownerOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                        </select>
                        <ErrorLine id={err('owner_id')} message={errors.owner_id} />
                    </div>
                ) : ownerNote ? (
                    <p className="text-sm text-gray-700">{ownerNote}</p>
                ) : null}
            </div>
        </div>
    );
}
