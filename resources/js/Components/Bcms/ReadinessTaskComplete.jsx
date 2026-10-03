import { useId, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/**
 * "Mark complete" for one readiness task — shared by the facilitator's full
 * checklist (`Exercises/Readiness.jsx`) and an owner's own list
 * (`Exercises/MyReadiness.jsx`, GAP 5), so the two screens never end up with
 * two different resolutions of the same evidence requirement.
 *
 * A TASK THAT `requires_evidence` CANNOT BE COMPLETED WITHOUT A FILE. Both
 * screens read the same `requires_evidence` flag `ReadinessController::show()`
 * and `ReadinessService::forUser()` already compute; this control is the one
 * place that turns it into a client-side requirement, matching the server's
 * own — `ReadinessController::complete()` accepts the file as `evidence` on
 * the completion POST itself, not through the generic occurrence-evidence
 * endpoint, so this posts multipart directly to `completeUrl`.
 *
 * POSITIONED ABSOLUTE, NOT INLINE. The two screens that use this sit the
 * button inside a narrow flex row (a checklist row's action cluster) and a
 * table cell — an inline form wide enough for a caption field would wrap
 * either layout unpredictably. The evidence form drops below the button
 * instead, in its own small panel, regardless of where the button sits.
 */
export default function ReadinessTaskComplete({ completeUrl, requiresEvidence, taskTitle, label = 'Complete', onDone }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [file, setFile] = useState(null);
    const [caption, setCaption] = useState('');
    const triggerRef = useRef(null);
    const popoverId = useId();

    // Escape closes the popover and puts focus back on the button that
    // opened it — a keyboard user who dismisses the evidence form should not
    // be dropped back at the top of the page.
    const closePopover = () => {
        setOpen(false);
        setError(null);
        setFile(null);
        setCaption('');
        triggerRef.current?.focus();
    };

    const handlePopoverKeyDown = (e) => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            closePopover();
        }
    };

    const finish = (errors) => {
        setBusy(false);
        if (errors && Object.keys(errors).length > 0) {
            setError(Object.values(errors)[0]);
            return;
        }
        setOpen(false);
        setFile(null);
        setCaption('');
        setError(null);
        if (onDone) onDone();
    };

    const completePlain = () => {
        setBusy(true);
        setError(null);
        router.post(completeUrl, {}, {
            preserveScroll: true,
            onError: (errors) => finish(errors),
            onSuccess: () => finish(null),
        });
    };

    const submitWithEvidence = (e) => {
        e.preventDefault();

        if (!file) {
            setError('This task requires evidence — attach a file before marking it complete.');
            return;
        }

        setBusy(true);
        setError(null);
        router.post(completeUrl, { evidence: file, caption: caption || undefined }, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => finish(errors),
            onSuccess: () => finish(null),
        });
    };

    if (!requiresEvidence) {
        return (
            <span className="inline-flex items-center gap-2">
                <button type="button" className="text-xs text-blue-700 hover:underline" onClick={completePlain} disabled={busy}>
                    {busy ? 'Completing…' : label}
                </button>
                {error && <span role="alert" className="text-xs text-rose-700">{error}</span>}
            </span>
        );
    }

    return (
        <span className="relative inline-block">
            <button
                ref={triggerRef}
                type="button"
                className="text-xs text-blue-700 hover:underline"
                aria-expanded={open}
                aria-controls={popoverId}
                aria-haspopup="dialog"
                onClick={() => setOpen((v) => !v)}
            >
                {label}
            </button>

            {open && (
                <form
                    id={popoverId}
                    role="dialog"
                    aria-label={`Evidence for: ${taskTitle || label}`}
                    onSubmit={submitWithEvidence}
                    onKeyDown={handlePopoverKeyDown}
                    className="absolute right-0 top-full z-10 mt-1 w-64 space-y-2 rounded border border-gray-200 bg-white p-3 text-left shadow-lg"
                >
                    <label className="block text-xs font-medium text-gray-700">
                        Evidence (required)
                        <input
                            type="file"
                            required
                            className="mt-1 block w-full text-xs"
                            accept=".jpg,.jpeg,.png,.gif,.pdf,.docx,.xlsx,.csv,.txt"
                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                        />
                    </label>
                    <input
                        type="text"
                        placeholder="Caption (optional)"
                        aria-label="Evidence caption"
                        className="form-input text-xs"
                        value={caption}
                        onChange={(e) => setCaption(e.target.value)}
                    />
                    {error && <p role="alert" className="text-xs text-rose-700">{error}</p>}
                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            className="text-xs text-gray-600 hover:underline"
                            onClick={closePopover}
                        >
                            Cancel
                        </button>
                        <button type="submit" disabled={busy} className="rounded bg-gray-900 px-2 py-1 text-xs text-white disabled:opacity-50">
                            {busy ? 'Uploading…' : 'Mark complete'}
                        </button>
                    </div>
                </form>
            )}
        </span>
    );
}
