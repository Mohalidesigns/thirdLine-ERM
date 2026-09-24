import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import QrCode from '@thirdline/ui/Components/QrCode';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The facilitator's check-in poster/kiosk view (qr-checkin spec, screen A).
 *
 * ADDENDUM 2026-09-17 (qr-checkin.md §9, Gate 2 blocking defect 5): this
 * poster used to print one QR and short code PER PARTICIPANT on one shared
 * sheet displayed at the assembly point. Any bystander standing near it could
 * read a name, scan that person's QR (or type their short code) and check
 * them in — forging headcount and time-to-assembly, the evidence criterion 1
 * turns on — and the sheet doubled as a printed staff roster left outdoors.
 *
 * THE POSTER NOW CARRIES NO PER-PARTICIPANT TOKEN OR CODE AT ALL. It shows
 * only the exercise identity, the "THIS IS AN EXERCISE" banner, ONE QR
 * encoding the fallback short-code *form* URL (never any one participant's
 * own code), the live count, and instructions. The facilitator's
 * per-participant attendance list, with each participant's short code and
 * check-in link, lives on the authenticated workspace screen
 * (`Workspace.jsx`'s attendance card), never on this poster.
 *
 * ADDENDUM 2026-09-23 (qr-checkin.md §9, live browser pass): the spec's
 * §1 assumes each participant is texted/emailed their own code at exercise
 * start. That automatic distribution was assessed and NOT built
 * (`docs/bcms/phase-9-notes.md` §11, "T-0 distribution — assessed, not
 * built") — it needs per-recipient template variables threaded through the
 * EMNS alert dispatcher, and its own ADR. Today the only route by which a
 * code reaches a participant is the facilitator reading it off the
 * workspace attendance card and relaying it by voice or in person. The
 * poster and the code-entry hint below say so, not "SMS or email" — change
 * that wording back only once T-0 distribution actually ships.
 */
export default function CheckInPoster({ occurrence = {}, expected = 0, checked_in: checkedInInitial = 0, code_form_url: codeFormUrl, live_metrics_url: liveMetricsUrl }) {
    const [checkedIn, setCheckedIn] = useState(checkedInInitial);

    useEffect(() => {
        if (occurrence.ended || !liveMetricsUrl) return undefined;

        const id = setInterval(() => {
            fetch(liveMetricsUrl, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : null))
                .then((d) => d && setCheckedIn(d.attendance?.checked_in ?? checkedIn))
                .catch(() => {});
        }, 5000);

        return () => clearInterval(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [occurrence.ended, liveMetricsUrl]);

    return (
        <AppLayout title={`Check-in — ${occurrence.title ?? ''}`}>
            <Head title={`Check-in — ${occurrence.title ?? ''}`} />

            <PageHeader
                title={occurrence.title}
                subtitle="Check-in poster"
                actions={(
                    <div className="flex gap-2">
                        <button type="button" onClick={() => window.print()}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 print:hidden">
                            Download / print
                        </button>
                        <Link href={tryRoute('bcms.occurrences.workspace', occurrence.uuid) ?? '#'}
                            className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50 print:hidden">
                            Back to workspace
                        </Link>
                    </div>
                )}
            />

            <div className="mx-auto max-w-md rounded-lg border-2 border-slate-200 bg-white p-8 text-center">
                <p className="mb-6 rounded border-2 border-violet-300 bg-violet-50 p-3 text-lg font-bold uppercase tracking-wide text-violet-900">
                    THIS IS AN EXERCISE
                </p>

                <h1 className="mb-6 text-2xl font-semibold text-slate-900">{occurrence.title}</h1>

                {codeFormUrl && (
                    <div className="mx-auto w-fit">
                        <QrCode value={codeFormUrl} size={240} label={`QR code — scan to open the check-in page for ${occurrence.title}`} />
                    </div>
                )}

                <p className="mt-6 text-xl font-medium text-slate-800">
                    Scan the code above, or go to
                </p>
                {codeFormUrl && (
                    <p className="mt-1 break-all text-xl font-bold text-slate-900">{codeFormUrl}</p>
                )}
                <p className="mt-2 text-lg text-slate-700">
                    and enter the check-in code your facilitator gives you.
                </p>

                <p aria-live="polite" className="mt-8 text-lg font-medium text-slate-800">
                    {occurrence.ended
                        ? `This exercise has ended. Check-in is closed. (${checkedIn} of ${expected} checked in.)`
                        : expected === 0
                            ? '0 of 0 — check-in opens when participants are invited.'
                            : `${checkedIn} of ${expected} checked in`}
                </p>
            </div>
        </AppLayout>
    );
}
