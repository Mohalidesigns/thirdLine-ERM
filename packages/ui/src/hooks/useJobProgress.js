import { useEffect, useRef, useState } from 'react';

/**
 * Poll one JobRun's progress (migration Phase 2: the Livewire JobProgress
 * component). GET risk/jobs/{id}/progress every `interval` ms until the run
 * reports finished; the caller renders whatever it likes from the snapshot.
 *
 *   const { progress, error } = useJobProgress(jobRunId);
 */
export default function useJobProgress(jobRunId, { interval = 2000, enabled = true } = {}) {
    const [progress, setProgress] = useState(null);
    const [error, setError] = useState(null);
    const timer = useRef(null);

    useEffect(() => {
        if (!jobRunId || !enabled) return undefined;
        let cancelled = false;

        const tick = async () => {
            try {
                const res = await window.axios.get(`/risk/jobs/${jobRunId}/progress`, { headers: { Accept: 'application/json' } });
                if (cancelled) return;
                setProgress(res.data);
                setError(null);
                if (!res.data?.finished) timer.current = setTimeout(tick, interval);
            } catch (e) {
                if (cancelled) return;
                setError(e);
                // A transient failure is not the job failing: keep polling, slower.
                timer.current = setTimeout(tick, interval * 3);
            }
        };

        tick();

        return () => {
            cancelled = true;
            if (timer.current) clearTimeout(timer.current);
        };
    }, [jobRunId, interval, enabled]);

    return { progress, error, finished: Boolean(progress?.finished) };
}
