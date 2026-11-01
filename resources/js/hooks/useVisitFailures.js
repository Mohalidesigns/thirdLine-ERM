import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Turns Inertia's two "the page cannot be shown" events into a message the
 * screen owns (docs/ui/kri-cycle/00-common.md section 4), so the user never
 * sees Inertia's raw-response modal on these screens and a form never loses
 * its state to one.
 *
 *   exception  -> the request never completed (network)
 *   invalid    -> a non-Inertia response: 419/401 session, 429 throttle, 5xx
 *
 * Validation errors (422) and lifecycle flashes are ordinary Inertia responses
 * and do not come through here. Registered only while the screen is mounted.
 */
export default function useVisitFailures() {
    const [failure, setFailure] = useState(null);

    useEffect(() => {
        const offInvalid = router.on('invalid', (event) => {
            const status = event.detail?.response?.status;

            if (status === 419 || status === 401) {
                event.preventDefault();
                setFailure({ kind: 'session', message: 'Your session has expired. Sign in again in a new tab, then come back and press Retry. Your entries are kept.' });
            } else if (status === 403) {
                event.preventDefault();
                setFailure({ kind: 'forbidden', message: "You don't have permission to do that any more. Nothing was changed." });
            } else if (status === 404) {
                event.preventDefault();
                setFailure({ kind: 'notfound', message: 'That item was already removed or no longer exists. The list has been refreshed.' });
            } else if (status === 429) {
                event.preventDefault();
                setFailure({ kind: 'throttle', message: 'Too many requests. Wait a minute, then try again.' });
            } else if (status >= 500) {
                event.preventDefault();
                setFailure({ kind: 'server', message: 'Something went wrong on our side. Nothing was changed. Try again.' });
            }
        });

        const offException = router.on('exception', (event) => {
            event.preventDefault();
            setFailure({ kind: 'network', message: "Couldn't reach the server. Check your connection and try again." });
        });

        return () => {
            offInvalid();
            offException();
        };
    }, []);

    const clear = useCallback(() => setFailure(null), []);

    return { failure, clear };
}
