import { useState } from 'react';
import useVisitFailures from '@/hooks/useVisitFailures';

/**
 * Spec 01 §0.2, §6.3 and §6.4 for ONE `PUT` save region.
 *
 * The Scenario Modelling screens have five save regions across four screens —
 * Overview, and Qualitative's three (§A1.11's three routes) — plus the shell's
 * two append-only `POST`s. Each region needs the same discipline and its own
 * banner, because §6.2 makes the banner name the region that failed: a failure
 * reported once at the top of the page does not tell a user which of the
 * Qualitative tab's three saves did not land.
 *
 * `useVisitFailures` registers GLOBAL router listeners, so more than one region
 * receives any given failure. The `failure && lastAction` gate — the pairing
 * `Kri/MyReturn.jsx:700-726` established — is what keeps exactly one banner on
 * screen: a region only claims a failure for a write IT issued.
 *
 * A `PUT` is safe to re-send (§6.4: "A `PUT` is safe to re-send"), which is why
 * this hook's network affordance is a bare **Try again**. The shell's `POST`s are
 * append-only with no unique key and must never be retried blind — those live in
 * `Workspace.jsx`, not here.
 *
 * @param {object}   options
 * @param {Function} options.begin       call at the start of the region's write
 * @param {Function} options.copySummary returns a plain-text summary of the
 *        region's dirty entries for §6.3's "Copy my entries" last resort
 * @returns {{failure: object|null, clearFailure: Function, begin: Function,
 *            succeeded: Function, copyEntries: Function, canResend: boolean}}
 */
export default function useSaveFailure({ copySummary }) {
    const { failure, clear } = useVisitFailures();
    const [lastAction, setLastAction] = useState(null);

    const begin = () => {
        clear();
        setLastAction('save');
    };

    /**
     * Dropping the marker on dismiss — NOT in an effect watching `failure`.
     *
     * An effect of the shape `useEffect(() => { if (!failure) setLastAction(null) },
     * [failure])` looks like the tidy way to do this and is not: `begin()` calls
     * `clear()` first, so on any SECOND failure the sequence is clear → failure
     * becomes null → the effect fires → the marker it was just given is wiped →
     * the new failure arrives with no marker and the banner never renders. The
     * first failure works, every later one is silently swallowed, which is the
     * worst possible shape for a bug in an error path. Verified in the browser:
     * a 500 on Save rendered its banner, and the 419 and 403 that followed it
     * rendered nothing at all.
     */
    const dismiss = () => {
        clear();
        setLastAction(null);
    };

    /** Spec 01 §6.3: the last resort inside the session-expired and 403 states. */
    const copyEntries = async () => {
        const text = typeof copySummary === 'function' ? copySummary() : null;

        if (!text) {
            return null;
        }

        try {
            await navigator.clipboard.writeText(text);

            return 'Your entries were copied.';
        } catch {
            return 'Could not copy automatically. Select the values on the page and copy them.';
        }
    };

    // Only THIS region's failure, and only the §6.4 wording for it. Everything
    // else the hook caught (419/401, 403, 404, 429, 5xx, network) already carries
    // §0.2's sentence.
    const regionFailure =
        failure && lastAction === 'save'
            ? failure.kind === 'network'
                ? {
                      ...failure,
                      message:
                          "Couldn't save. Check your connection and try again. Your entries are still on this page.",
                  }
                : failure.kind === 'forbidden'
                  ? {
                        ...failure,
                        message:
                            "You no longer have permission to change this scenario. Nothing was saved. Your entries are still on this page.",
                    }
                  : failure
            : null;

    return {
        failure: regionFailure,
        clearFailure: dismiss,
        begin,
        succeeded: () => setLastAction(null),
        copyEntries,
        // A 419 and a network failure may re-send; a 403 may not (there is no
        // permission to re-send into), and §6.3 gives it "Copy my entries"
        // instead.
        canResend: regionFailure?.kind === 'session' || regionFailure?.kind === 'network' || regionFailure?.kind === 'server',
        copyOffered: regionFailure?.kind === 'session' || regionFailure?.kind === 'forbidden',
    };
}
