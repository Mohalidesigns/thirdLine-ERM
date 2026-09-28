import { useEffect, useRef } from 'react';
import { useForm } from '@inertiajs/react';
import FormField from '@thirdline/ui/Components/FormField';
import InputError from '@thirdline/ui/Components/InputError';

/**
 * The inline "raise a finding" form, extracted from `Exercises/Aar.jsx`
 * (Phase 9) so `Incidents/Review.jsx` (the post-incident review) can post to
 * the SAME route — `bcms.findings.store` — through the SAME flow, rather than
 * growing a second copy that drifts from the first. AAR behaviour is
 * unchanged: `source` defaults to `'aar'`, exactly what this component
 * hard-coded before extraction.
 *
 * CONTROLLED OPEN/CLOSED FROM THE CALLER, deliberately — a caller that wants
 * one shared form behind several triggers (AAR's per-objective row buttons,
 * the PIR's per-plan-section buttons) keeps a single `raising` context object
 * and passes it down; this component never owns which trigger opened it.
 *
 * `key`-ed remount is the caller's job (see both pages) — `useForm`'s initial
 * state is read once per mount, so a caller that reuses this component across
 * different `context` values must remount it when the context changes, the
 * same way `Aar.jsx` already does.
 *
 * EVERY SERVER REFUSAL IS RENDERED. `form.errors` was previously never read —
 * a validation failure on `description`, `iso_clause_ref`, `classification`
 * or `severity`, or a service-level refusal on `aar_id` ("this AAR has
 * already been finalised" / "not a post-incident review"), all landed on the
 * wire and then nowhere, so Raise looked like it silently did nothing.
 * `aar_id` has no field of its own on this form, so its error renders as a
 * form-level message (`FindingController::store`'s validation names it, but
 * nothing on screen names a control for a reader to fix).
 *
 * CLAUSE IS A SELECT, NOT FREE TEXT — `iso_clause_ref` is validated against
 * `IsoClauseRef::values()` server-side (`FindingController::store`), so a
 * free-text box that looked optional was actually a field only a handful of
 * exact strings could ever pass. `Findings/Index.jsx`'s own raise-a-finding
 * form is the reference for both the control and its "take it from the
 * source" empty option. `clauseRefs` is supplied by the caller's own
 * `options.clause_refs` — `Exercises/Aar.jsx` carries it too
 * (`AarController::show()`, `options.clause_refs` via `IsoClauseRef::
 * options()`), so a nonconformity can be raised with a real clause from
 * either caller; a caller that omits the prop simply renders the empty
 * option alone rather than a hard-coded list this component would have to
 * keep in sync with the enum by hand.
 *
 * A REFUSED SUBMIT NEVER CLOSES THE FORM. `form.post()`'s `onSuccess` is
 * Inertia's own distinction between "the server accepted this" and "the
 * server sent back an error bag" — it never fires on a validation failure or
 * a service-level refusal (`FindingController::store()` flashes every one of
 * those as a named field error, `iso_clause_ref` included, code review D2),
 * so `form.errors` populates and this form stays open with what was typed
 * still in it. No separate `onError` is needed to achieve that; one would
 * only be for a side effect beyond what `form.errors` already renders below.
 */
export default function RaiseFinding({ aarKey, raiseUrl, context, open, onOpen, onClose, source = 'aar', clauseRefs = [] }) {
    const form = useForm({
        source, classification: 'observation', description: context?.description || '',
        severity: 'medium', iso_clause_ref: '', aar_id: aarKey, objective_text: context?.objectiveText || '',
    });
    const descriptionRef = useRef(null);

    // A1 — a per-section (or per-objective) trigger turns itself into inert
    // text the moment it opens this form (see both callers), so the browser
    // has nowhere left to leave focus. Move it to the field the officer
    // actually types into, the same way opening any other panel would.
    useEffect(() => {
        if (open) descriptionRef.current?.focus();
    }, [open]);

    if (!open) {
        return (
            <button type="button" className="mt-3 text-xs text-blue-700 hover:underline" onClick={onOpen}>
                Raise a finding
            </button>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(raiseUrl, { preserveScroll: true, onSuccess: () => { form.reset(); onClose(); } });
            }}
            className="mt-3 space-y-2 rounded border border-dashed border-slate-300 p-3"
        >
            {form.errors.aar_id && (
                <InputError role="alert" message={form.errors.aar_id} />
            )}
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <FormField label="Classification" className="text-xs" error={form.errors.classification}>
                    <select className="form-select text-xs" value={form.data.classification}
                        onChange={(e) => form.setData('classification', e.target.value)}>
                        <option value="observation">Observation</option>
                        <option value="improvement">Improvement</option>
                        <option value="nonconformity">Nonconformity</option>
                    </select>
                </FormField>
                <FormField label="Severity" className="text-xs" error={form.errors.severity}>
                    <select className="form-select text-xs" value={form.data.severity}
                        onChange={(e) => form.setData('severity', e.target.value)}>
                        {['low', 'medium', 'high', 'critical'].map((s) => <option key={s} value={s}>{s}</option>)}
                    </select>
                </FormField>
                <FormField label="ISO clause" className="text-xs" error={form.errors.iso_clause_ref}>
                    <select className="form-select text-xs" value={form.data.iso_clause_ref}
                        onChange={(e) => form.setData('iso_clause_ref', e.target.value)}>
                        <option value="">Take it from the source</option>
                        {clauseRefs.map((c) => (
                            <option key={c.value} value={c.value}>{c.standard} — {c.value}</option>
                        ))}
                    </select>
                </FormField>
            </div>
            <FormField label="What was found" className="text-xs" error={form.errors.description}>
                <textarea ref={descriptionRef} rows={2} className="form-textarea w-full text-xs" placeholder="What was found"
                    value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            </FormField>
            {form.data.objective_text && (
                <p className="text-[11px] text-slate-500">Against objective: {form.data.objective_text}</p>
            )}
            <div className="flex gap-2">
                <button type="submit" disabled={form.processing} className="rounded bg-slate-800 px-3 py-1 text-xs text-white hover:bg-slate-700">
                    Raise
                </button>
                <button type="button" className="text-xs text-slate-500 hover:underline" onClick={onClose}>Cancel</button>
            </div>
        </form>
    );
}
