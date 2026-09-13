import { useEffect, useRef, useState } from 'react';

/**
 * Poll one generated report's progress (migration Phase 5.4).
 *
 * WHY NOT `useJobProgress`. The phase prompt asks for the status page's
 * hand-rolled `setInterval` + `fetch` to become `useJobProgress`. That hook
 * polls `/risk/jobs/{id}/progress`, which needs a JobRun id — and
 * `GenerateReportJob` does not use the `TracksJobProgress` trait, so no JobRun
 * is ever created for a report and `generated_reports` carries no
 * `job_run_id`. Wiring one up is a change to the job pipeline, not a port of a
 * screen, so the instruction is declined and recorded in the module notes.
 *
 * What this does instead is poll the endpoint that already exists —
 * `reports/{report}/status.json`, unchanged — with the same shape and the same
 * transient-failure behaviour as useJobProgress, so the page has no polling
 * logic of its own.
 *
 *   const { status, percent, downloadUrl } = useReportStatus(reportId);
 */
export default function useReportStatus(reportId, { interval = 2000, enabled = true } = {}) {
    const [snapshot, setSnapshot] = useState(null);
    const [error, setError] = useState(null);
    const timer = useRef(null);

    useEffect(() => {
        if (!reportId || !enabled) return undefined;
        let cancelled = false;

        const finished = (status) => ['completed', 'failed', 'cancelled'].includes(status);

        const tick = async () => {
            try {
                const res = await window.axios.get(`/risk/reports/${reportId}/status.json`, {
                    headers: { Accept: 'application/json' },
                });
                if (cancelled) return;

                setSnapshot(res.data);
                setError(null);

                if (!finished(res.data?.status)) timer.current = setTimeout(tick, interval);
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
    }, [reportId, interval, enabled]);

    return {
        status: snapshot?.status ?? null,
        percent: snapshot?.progress_pct ?? null,
        errorMessage: snapshot?.error_message ?? null,
        downloadUrl: snapshot?.download_url ?? null,
        error,
    };
}
