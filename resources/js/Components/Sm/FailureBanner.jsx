/**
 * The Scenario Modelling banner for a failure captured by
 * `resources/js/hooks/useVisitFailures.js` — spec 01 §0.2's five rows
 * (network, non-Inertia response, 419/401 session, 403, 429, 5xx) and §6.4's
 * write-failure discipline.
 *
 * `role="alert"`: it follows a user action, and §0.3 reserves `alert` for exactly
 * that ("never on page load"). The banner is NOT transient — a failure sentence
 * that disappears on a timer is a sentence the user never read, which is the
 * defect class spec 01 §6.4 exists to prevent.
 *
 * The control it names must be the control it renders. §5.6.9's wording trap —
 * a banner saying "Press Retry" beside no Retry button — is why `onRetry` and
 * `retryLabel` are paired here rather than left to the caller to reconcile.
 *
 * A `POST` (status transition, sign-off write) never gets a blind "Try again":
 * both are append-only with no unique key, so the caller passes a
 * reload-first control instead ("Check what happened") and this banner renders
 * whatever label it is given. `children` is the slot for the §6.3 last resort,
 * "Copy my entries".
 */
export default function FailureBanner({ failure, onRetry, retryLabel, onDismiss, children }) {
    if (!failure) {
        return null;
    }

    return (
        <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p>{failure.message}</p>
            <div className="mt-2 flex flex-wrap items-center gap-3">
                {onRetry && (
                    <button type="button" onClick={onRetry} className="btn-secondary text-sm">
                        {retryLabel ?? (failure.kind === 'session' ? 'Retry' : 'Try again')}
                    </button>
                )}
                {children}
                {onDismiss && (
                    <button type="button" onClick={onDismiss} className="text-sm font-medium text-red-800 underline">
                        Dismiss
                    </button>
                )}
            </div>
        </div>
    );
}

/**
 * The §6.3/§6.4 pairing for ONE `PUT` save region, wired to
 * `Components/Sm/useSaveFailure.js`.
 *
 * Which control appears is decided here rather than at each call site, because
 * the rule is easy to get wrong and the mistake is a banner that names a control
 * it does not render (spec 01 §5.6.9's trap):
 *
 *   network / 5xx / 419 → the write is repeated. A `PUT` is safe to re-send
 *                         (§6.4), and §0.2 gives the 419 a "Retry".
 *   403                 → "Copy my entries" ONLY. There is no permission to
 *                         re-send into, and §6.3 makes the clipboard the remedy.
 *   419                 → both: Retry, and "Copy my entries" beside it.
 *   anything else       → no control; the sentence is the whole message.
 *
 * @param {Function} onAnnounce receives the copy outcome so the region can say
 *        it in its own `role="status"` — a clipboard write with no confirmation
 *        is indistinguishable from one that failed.
 */
export function RegionSaveFailure({ saveFailure, onResend, onAnnounce, failure, onDismiss }) {
    if (saveFailure) {
        if (!saveFailure.failure) {
            return null;
        }

        const copy = async () => {
            const result = await saveFailure.copyEntries?.();

            if (result) {
                onAnnounce?.(result);
            }
        };

        const copyOnly = saveFailure.copyOffered && !saveFailure.canResend;

        return (
            <FailureBanner
                failure={saveFailure.failure}
                onRetry={saveFailure.canResend ? onResend : copyOnly ? copy : undefined}
                retryLabel={copyOnly ? 'Copy my entries' : undefined}
                onDismiss={saveFailure.clearFailure || saveFailure.dismiss}
            >
                {saveFailure.copyOffered && saveFailure.canResend && (
                    <button type="button" onClick={copy} className="text-sm font-medium text-[#1A365D] underline">
                        Copy my entries
                    </button>
                )}
            </FailureBanner>
        );
    }

    if (failure) {
        return (
            <FailureBanner
                failure={failure}
                onRetry={onResend}
                onDismiss={onDismiss}
            />
        );
    }

    return null;
}
