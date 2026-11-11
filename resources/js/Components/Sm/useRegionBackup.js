import { useEffect, useRef, useState } from 'react';

/**
 * The per-save-region `sessionStorage` backup (spec 01 §6.3).
 *
 * On every change, debounced 1 s, the region's form state goes to
 * `sessionStorage` under `sm-scenario-{id}-{region}`, and is cleared on that
 * region's successful save. On load, if a backup differs from the props, the
 * screen offers "We kept entries you hadn't saved from {hh:mm}. Restore them ·
 * Discard".
 *
 * `sessionStorage`, NOT `localStorage`, so entries do not outlive the tab on a
 * shared computer. Values only — no tokens.
 *
 * FROZEN STATUSES NEVER WRITE A BACKUP. A read-only tab has no form state to
 * lose, and offering "restore your entries" on a signed-off scenario would
 * invite exactly the confusion spec 01 §5.5 exists to prevent — so `enabled`
 * must be false whenever the scenario is read-only.
 */
export default function useRegionBackup(scenarioId, region, current, enabled) {
    const key = `sm-scenario-${scenarioId}-${region}`;
    const [offer, setOffer] = useState(null);
    const wroteBackup = useRef(false);

    // Offer a restore once, on mount, when a backup exists and differs from what
    // the server just sent. Comparing rather than always offering is the point:
    // a backup identical to the props is a backup of work that already landed.
    useEffect(() => {
        if (!enabled) {
            return;
        }

        let stored = null;

        try {
            const raw = window.sessionStorage.getItem(key);
            stored = raw ? JSON.parse(raw) : null;
        } catch {
            stored = null;
        }

        if (!stored || typeof stored !== 'object' || !stored.data) {
            return;
        }

        if (JSON.stringify(stored.data) === JSON.stringify(current)) {
            return;
        }

        setOffer(stored);
        // Deliberately mount-only: re-running on every keystroke would re-offer a
        // backup the user has already dismissed or restored.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, key]);

    useEffect(() => {
        if (!enabled) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            try {
                window.sessionStorage.setItem(
                    key,
                    JSON.stringify({ data: current, savedAt: new Date().toISOString() }),
                );
                wroteBackup.current = true;
            } catch {
                // A full or disabled sessionStorage is not worth breaking a save
                // over; the backup is a convenience, not a guarantee.
            }
        }, 1000);

        return () => window.clearTimeout(timer);
    }, [current, enabled, key]);

    const clear = () => {
        try {
            window.sessionStorage.removeItem(key);
        } catch {
            // Nothing to do — see above.
        }

        wroteBackup.current = false;
    };

    const dismiss = () => {
        setOffer(null);
        clear();
    };

    return { offer, dismiss, clear, take: () => { const data = offer?.data; setOffer(null); return data; } };
}

/** `hh:mm` in Lagos, for the "We kept entries from …" line. */
export function backupTime(iso) {
    if (!iso) {
        return null;
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Africa/Lagos',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);
}
