import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The resilience board pack — `docs/bcms/screens/board-pack-preview.md`.
 *
 * NO PERSISTED RECORD, NO DRAFT/SIGN-OFF LIFECYCLE (ADR 0021 §4 — there is no
 * `bcms_board_packs` table). Every visit recomputes the preview fresh from
 * stored, terminal rows; the export is a snapshot, not an edit to a prior one.
 */
export default function BoardPack({ year, preview, can = {} }) {
    const changeYear = (y) => router.get(tryRoute('bcms.reports.board-pack'), { year: y });

    // `bcms.reports.board-pack.export` is a GET route (no model to bind, no
    // request body needed) — a plain navigation, not a form post. The PDF/
    // PPTX response's `Content-Disposition: attachment` header is what turns
    // this into a download rather than a page load.
    const exportUrl = (format) => tryRoute('bcms.reports.board-pack.export', { year, format });

    const noActivity = !preview.posture_summary?.sentences?.length
        && (preview.maturity_trend ?? []).length === 0
        && preview.exercise_completion?.planned === null
        && preview.plan_currency === null
        && (preview.top_rto_gaps ?? []).length === 0
        && (preview.open_nonconformities ?? []).length === 0
        && preview.incident_summary?.count === 0
        && preview.management_review === null;

    return (
        <AppLayout title="Board pack">
            <Head title="Board pack" />

            <PageHeader
                title="Board pack"
                subtitle={`${year} · maturity trend, exercise completion, the incident summary and the management review are ${year}'s own stored records. Plan currency, top RTO gaps, open nonconformities and the resilience KRI table are live figures, as at ${preview.as_at ? new Date(preview.as_at).toLocaleString() : 'the moment this page loaded'}.`}
                actions={
                    <div className="flex items-center gap-2">
                        <select className="form-select text-sm" aria-label="Year" value={year} onChange={(e) => changeYear(e.target.value)}>
                            {Array.from({ length: 6 }, (_, i) => new Date().getFullYear() - i).map((y) => (
                                <option key={y} value={y}>{y}</option>
                            ))}
                        </select>
                        {can.export && (
                            <>
                                <a href={exportUrl('pdf')} className="btn-secondary text-sm">Export as PDF</a>
                                <a href={exportUrl('pptx')} className="btn-secondary text-sm">Export as PowerPoint</a>
                            </>
                        )}
                    </div>
                }
            />

            {noActivity ? (
                <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    No BCMS records exist for {year}. Select a year with activity, or this is simply too early to
                    produce a board pack.
                </p>
            ) : (
                <div className="space-y-6">
                    <Section title="Resilience posture summary">
                        {preview.posture_summary?.sentences?.length > 0 ? (
                            <p className="text-sm text-gray-700">{preview.posture_summary.sentences.join(' ')}</p>
                        ) : (
                            <p className="text-sm text-gray-500">No figures are available yet to summarise.</p>
                        )}
                    </Section>

                    <Section title="Maturity trend">
                        {(preview.maturity_trend ?? []).length === 0 ? (
                            <p className="text-sm text-gray-500">
                                No maturity assessment has been run in {year}.{' '}
                                <Link href={tryRoute('bcms.programme.index')} className="underline">Re-assess in Programme governance</Link>.
                            </p>
                        ) : (
                            <p className="text-sm text-gray-700">
                                {preview.maturity_trend.map((m) => `${m.assessed_at}: ${m.overall_score ?? '—'}`).join(', ')}
                            </p>
                        )}
                    </Section>

                    <Section title="Exercise programme completion">
                        {preview.exercise_completion?.planned === null ? (
                            <p className="text-sm text-gray-500">No approved exercise programme for {year}.</p>
                        ) : (
                            <p className="text-sm text-gray-700">
                                {preview.exercise_completion.completed} of {preview.exercise_completion.planned} planned exercises completed.
                            </p>
                        )}
                    </Section>

                    <Section title="Plan currency">
                        {preview.plan_currency === null ? (
                            <p className="text-sm text-gray-500">No plans on record, so currency is undefined.</p>
                        ) : (
                            <p className="text-sm text-gray-700">{preview.plan_currency}% of approved plans are inside their review cycle.</p>
                        )}
                    </Section>

                    <Section title="Top RTO gaps">
                        {(preview.top_rto_gaps ?? []).length === 0 ? (
                            <p className="text-sm text-gray-500">No process currently has a strategy shortfall against its required RTO.</p>
                        ) : (
                            <ul className="list-disc space-y-1 pl-5 text-sm text-gray-700">
                                {preview.top_rto_gaps.map((g, i) => (
                                    <li key={i}>{g.name}: {shortfallPhrase(g.shortfall_hours)}</li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="Open nonconformities">
                        {(preview.open_nonconformities ?? []).length === 0 ? (
                            <p className="text-sm text-gray-500">No nonconformity is currently open.</p>
                        ) : (
                            <ul className="list-disc space-y-1 pl-5 text-sm text-gray-700">
                                {preview.open_nonconformities.map((n, i) => (
                                    <li key={i}>{n.reference} — {n.description} ({n.iso_clause_ref})</li>
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title="Incident summary">
                        <p className="text-sm text-gray-700">
                            {preview.incident_summary?.count === 0
                                ? `No incident was declared in ${year}.`
                                : `${preview.incident_summary.count} incident(s) declared in ${year}.`}
                        </p>
                    </Section>

                    <Section title="Resilience KRI dashboard">
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <caption className="sr-only">Resilience KRI dashboard</caption>
                                <thead><tr><th scope="col">Code</th><th scope="col">Name</th><th scope="col">Target</th><th scope="col">Current</th><th scope="col">Last measured</th></tr></thead>
                                <tbody>
                                    {(preview.kris ?? []).map((k) => (
                                        <tr key={k.kri_code}>
                                            <td className="font-mono text-xs">{k.kri_code}</td>
                                            <td className="text-sm">{k.name}</td>
                                            <td className="text-xs text-gray-600">{k.target} {k.unit}</td>
                                            <td className="text-sm">{k.linked ? (k.current_value ?? 'No measurement') : 'Not linked'}</td>
                                            <td className="text-xs text-gray-500">{k.last_measured_at ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Section>

                    <Section title="Management-review inputs">
                        {preview.management_review === null ? (
                            <p className="text-sm text-gray-500">
                                No management review has been approved for {year}. Clause 9.3 requires an annual
                                review; none is on record yet.
                            </p>
                        ) : (
                            <p className="text-sm text-gray-700">
                                {preview.management_review.title}, held {preview.management_review.held_on}, approved by {preview.management_review.approved_by}.{' '}
                                <Link href={tryRoute('bcms.reviews.show', preview.management_review.uuid)} className="underline">Open the full record</Link>
                            </p>
                        )}
                    </Section>

                    <p className="text-xs text-gray-500">
                        This pack maps directly to the sections above; the exported document carries the same order
                        and an index page.
                    </p>
                </div>
            )}
        </AppLayout>
    );
}

// Live-browser follow-up to B2: "shortfall none recorded hours" read as a
// stray trailing unit; "no shortfall recorded" carries no unit, and a real
// figure is pluralised ("1 hour" vs "N hours").
function shortfallPhrase(hours) {
    if (hours === null || hours === undefined) {
        return 'no shortfall recorded';
    }

    return `shortfall ${hours} ${Number(hours) === 1 ? 'hour' : 'hours'}`;
}

function Section({ title, children }) {
    return (
        <section className="rounded-lg border border-gray-200 bg-white p-6">
            <h2 className="text-sm font-semibold text-gray-900">{title}</h2>
            <div className="mt-3">{children}</div>
        </section>
    );
}
