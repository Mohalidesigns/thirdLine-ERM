import { useEffect, useRef, useState } from 'react';
import { formatLagosInstant } from './format';

/**
 * The notes log (spec 02 §7) — `Components/Sm/NotesLog.jsx`.
 *
 * BESPOKE, justified at spec 01 §0.1.4: a list with its own write path (`POST` a
 * note). A list is not a field, so it cannot be a schema entry.
 *
 * APPEND-ONLY, ENFORCED BY THE ABSENCE OF ROUTES (§A1.7). Phase 1 registers no
 * update and no destroy route, and `NotePolicy` has neither ability, so there is
 * NO EDIT CONTROL AND NO DELETE CONTROL ANYWHERE ON THIS PANEL — not greyed out,
 * not hidden behind a permission, simply absent. A corrected note is a new note:
 * a dissent its author can quietly edit is not a dissent trail.
 *
 * GATED ON `can.note`, NOT `can.update` (spec 01 §3, §A1.10). They are different
 * authorities on purpose: editing a scenario is first-line work, recording a
 * dissent against somebody else's is second-line work, and one permission for
 * both would let the designer write the dissent log the trail exists to keep. So
 * a `risk-owner` holding `scenario.view` + `scenario.note` can add notes here
 * while every field on both tabs is read-only.
 *
 * IDENTITY IS NOT A FIELD. The author is the authenticated user and the
 * timestamp is the server's; `author_name` is a snapshot because an anonymous
 * dissent cannot be followed up, which is worse than none.
 */
const KIND_CHIPS = {
    assumption: 'badge badge-status-pending',
    dissent: 'badge badge-status-overdue',
    challenge: 'badge badge-medium',
    workshop_minute: 'badge badge-status-draft',
};

export default function NotesLog({
    notes,
    notesMeta,
    noteKinds,
    canNote,
    errors = {},
    earlierUrl,
    onReload,
}) {
    const defaultKind = noteKinds?.[0]?.value ?? 'assumption';
    const [kind, setKind] = useState(defaultKind);
    const [body, setBody] = useState('');
    const [saving, setSaving] = useState(false);
    const bodyRef = useRef(null);
    const summaryRef = useRef(null);

    const chosen = noteKinds?.find((entry) => entry.value === kind);
    const errorKeys = Object.keys(errors).filter((key) => (key === 'kind' || key === 'body') && Boolean(errors[key]));

    useEffect(() => {
        if (errorKeys.length > 0) {
            summaryRef.current?.focus();
        }
    }, [errors.kind, errors.body]);

    const submit = (event) => {
        event.preventDefault();
        setSaving(true);

        // Inertia's own form post. The list reloads through
        // `only: ['notes','notesMeta']` on success (spec 02 §7.1) — the rest of
        // the payload is untouched, so a note added during a workshop does not
        // cost a full workspace round trip.
        //
        // `after` is resolved by the shell EXACTLY ONCE with the outcome. It used
        // to be wired to `onFinish`, which fires on a 422, a 403 and an exception
        // too — so a rejected dissent was wiped from the textarea before its
        // author could read why. The log is append-only with no edit route, so
        // that text was simply GONE (spec 01 §6.2: "Nothing was saved. Your
        // entries are still on this page").
        onReload({ kind, body }, (result) => {
            setSaving(false);

            if (!result?.ok) {
                // Keep the draft and the kind. Focus is NOT taken here: on a 422
                // the error summary heading claims it (§6.2), via the effect
                // above, and on a lost response the shell's banner does.
                return;
            }

            setBody('');
            setKind(defaultKind);
            // A genuine success returns focus to the textarea for the next note
            // (spec 02 §7.1).
            bodyRef.current?.focus();
        });
    };

    return (
        <div className="space-y-4">
            {canNote && (
                <form onSubmit={submit} className="space-y-3 rounded-lg bg-gray-50 p-4">
                    {errorKeys.length > 0 && (
                        <div className="rounded-lg border border-red-200 bg-red-50 p-3">
                            <h3
                                ref={summaryRef}
                                tabIndex={-1}
                                className="text-sm font-semibold text-red-800 focus:outline-none"
                            >
                                Fix {errorKeys.length} {errorKeys.length === 1 ? 'item' : 'items'}
                            </h3>
                            <ul className="mt-1 list-inside list-disc text-sm text-red-700">
                                {errorKeys.map((key) => (
                                    <li key={key}>
                                        <a href={`#note-${key}`} className="underline">
                                            {errors[key]}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div>
                        <label htmlFor="note-kind" className="form-label">
                            Kind
                        </label>
                        <select
                            id="note-kind"
                            className="form-input text-sm"
                            value={kind}
                            onChange={(event) => setKind(event.target.value)}
                            aria-invalid={errors.kind ? 'true' : undefined}
                            aria-describedby={errors.kind ? 'note-kind-error' : chosen?.hint ? 'note-kind-hint' : undefined}
                        >
                            {noteKinds?.map((entry) => (
                                <option key={entry.value} value={entry.value}>
                                    {entry.label}
                                </option>
                            ))}
                        </select>
                        {errors.kind ? (
                            <p id="note-kind-error" className="mt-1 text-sm text-red-700">
                                {errors.kind}
                            </p>
                        ) : (
                            // The hints are the point — they are what makes a
                            // risk officer write a useful note instead of
                            // "discussed" (spec 02 §7.1).
                            chosen?.hint && (
                                <p id="note-kind-hint" className="mt-1 text-xs text-gray-600">
                                    {chosen.hint}
                                </p>
                            )
                        )}
                    </div>

                    <div>
                        <label htmlFor="note-body" className="form-label">
                            Note
                        </label>
                        <textarea
                            id="note-body"
                            ref={bodyRef}
                            rows={3}
                            maxLength={2000}
                            className="form-input text-sm"
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                            aria-invalid={errors.body ? 'true' : undefined}
                            aria-describedby={errors.body ? 'note-body-error' : undefined}
                        />
                        {errors.body && (
                            <p id="note-body-error" className="mt-1 text-sm text-red-700">
                                {errors.body}
                            </p>
                        )}
                    </div>

                    <button type="submit" className="btn-secondary text-sm" disabled={saving} aria-busy={saving || undefined}>
                        {saving ? 'Adding…' : 'Add note'}
                    </button>
                    <p className="text-xs text-gray-500">
                        Notes are append-only. A corrected note is a new note — there is no edit and no delete.
                    </p>
                </form>
            )}

            {(!notes || notes.length === 0) ? (
                <div className="py-4 text-center">
                    <p className="text-sm font-semibold text-gray-800">No notes yet</p>
                    <p className="mx-auto mt-1 max-w-md text-sm text-gray-500">
                        Record an assumption, a dissent, a challenge or a workshop minute. The trail is what an
                        examiner asks for, and it is what makes a signed-off number defensible.
                    </p>
                </div>
            ) : (
                <ol className="space-y-4">
                    {notes.map((note, index) => (
                        <li key={note.id} className="border-l-2 border-gray-200 pl-3">
                            <span className={KIND_CHIPS[note.kind] || 'badge badge-status-draft'}>{note.kind_label}</span>
                            <p className="mt-1.5 whitespace-pre-line text-sm text-gray-800">{note.body}</p>
                            <p className="mt-1 text-xs text-gray-600">
                                {note.author_name}
                                {note.created_at && (
                                    <>
                                        {' · '}
                                        {/* The words "Lagos time" appear once per page, on the first
                                            timestamp (spec 02 §7.2). */}
                                        {index === 0
                                            ? formatLagosInstant(note.created_at)
                                            : formatLagosInstant(note.created_at)?.replace(' Lagos time', '')}
                                    </>
                                )}
                            </p>
                        </li>
                    ))}
                </ol>
            )}

            {notesMeta && notesMeta.total > notesMeta.shown && (
                // Nothing auto-loads: a scenario workshopped over a year can carry
                // hundreds of notes and this panel is above the fold (spec 01 §4.4).
                <button
                    type="button"
                    onClick={() => earlierUrl && onReload(null, null, earlierUrl)}
                    className="btn-secondary text-sm"
                >
                    Load earlier notes
                </button>
            )}
        </div>
    );
}

/** The header count — "{shown} of {total} shown" — kept beside the list it describes. */
export function NotesCount({ notesMeta }) {
    if (!notesMeta) {
        return null;
    }

    return (
        <span className="text-xs font-normal text-gray-500">
            {notesMeta.shown} of {notesMeta.total} shown
        </span>
    );
}
