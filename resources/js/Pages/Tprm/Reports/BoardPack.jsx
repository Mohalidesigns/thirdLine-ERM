import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * One board pack — FR-RPT-05.
 *
 * THE NARRATIVE COMES FIRST AND CARRIES ITS PROVENANCE. FR-RPT-05 asks for a
 * drafted narrative "the CRO edits before sign-off", so the editor is on the
 * page rather than behind a modal, and the line under the heading says whether
 * a person, a model or the product produced what is in the box.
 *
 * A SIGNED-OFF PACK IS READ-ONLY AND THE PAGE SAYS WHY. Not disabled controls
 * with no explanation: the committee approved these numbers, and the way to
 * report a later position is a new period.
 *
 * FIGURES RENDER FROM THE SNAPSHOT. Nothing on this page recomputes anything,
 * which is why a pack from three quarters ago still shows what was tabled.
 */
export default function BoardPack({ pack = {}, can = {} }) {
    const figures = pack.figures ?? {};
    const [editing, setEditing] = useState(false);

    const { data, setData, put, processing } = useForm({ narrative: pack.narrative ?? '' });

    const portfolio = figures.portfolio ?? {};
    const findings = figures.findings ?? {};
    const exit = figures.exit_readiness ?? {};
    const assessments = figures.overdue_assessments ?? {};
    const evidence = figures.expiring_evidence ?? {};
    const incidents = figures.incidents ?? {};
    const concentration = figures.concentration ?? {};

    const saveNarrative = (event) => {
        event.preventDefault();
        put(route('tprm.reports.board-packs.narrative', pack.uuid), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const transition = (to) => {
        router.post(route('tprm.reports.board-packs.transition', pack.uuid), { to }, { preserveScroll: true });
    };

    return (
        <AppLayout title={`Board pack — ${pack.period_label}`}>
            <Head title={`Board pack — ${pack.period_label}`} />

            <PageHeader
                title={`Board and Risk Committee pack — ${pack.period_label}`}
                subtitle={`Position as at ${pack.as_at}. ${pack.status_label}.`}
            />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                {can.prepare && (
                    <a className="btn-secondary" href={route('tprm.reports.board-packs.export', pack.uuid)}>
                        Branded PDF
                    </a>
                )}
                {pack.editable && can.prepare && pack.status === 'draft' && (
                    <button type="button" className="btn-secondary" onClick={() => transition('in_review')}>
                        Submit for review
                    </button>
                )}
                {pack.editable && can.sign_off && (
                    <button type="button" className="btn-primary" onClick={() => transition('signed_off')}>
                        Sign off
                    </button>
                )}
                <span className="text-xs text-gray-500">
                    Scoring engine {pack.engine_version ?? 'not recorded'}. Prepared by{' '}
                    {pack.prepared_by ?? 'nobody'} {pack.prepared_at ? `on ${pack.prepared_at}` : ''}.
                </span>
            </div>

            {!pack.editable && (
                <div className="mb-6 rounded border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <strong>Signed off by {pack.signed_off_by}</strong>
                    {pack.signed_off_at ? ` on ${pack.signed_off_at}` : ''}. These figures are frozen. To report a
                    later position, prepare a new period rather than recomputing this one — the committee&rsquo;s
                    minutes cite these numbers.
                </div>
            )}

            {/* ------------------------------------------------- narrative */}
            <section className="card mb-6 p-4">
                <div className="mb-2 flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-800">Assessment</h2>
                        <p className="mt-1 text-xs text-gray-500">{pack.narrative_provenance}</p>
                    </div>
                    {pack.editable && can.prepare && !editing && (
                        <button type="button" className="btn-secondary" onClick={() => setEditing(true)}>
                            Edit
                        </button>
                    )}
                </div>

                {editing ? (
                    <form onSubmit={saveNarrative}>
                        <textarea
                            className="w-full rounded border-gray-300 text-sm"
                            rows={14}
                            value={data.narrative}
                            onChange={(event) => setData('narrative', event.target.value)}
                        />
                        <div className="mt-2 flex items-center gap-2">
                            <button type="submit" className="btn-primary" disabled={processing}>
                                Save as my own
                            </button>
                            <button type="button" className="btn-secondary" onClick={() => setEditing(false)}>
                                Cancel
                            </button>
                            <span className="text-xs text-gray-500">
                                Saving marks this narrative as edited by you, and it survives a later refresh of
                                the figures.
                            </span>
                        </div>
                    </form>
                ) : (
                    <div className="space-y-3 text-sm text-gray-800">
                        {(pack.narrative ?? '').split(/\n\s*\n/).filter(Boolean).map((paragraph, index) => (
                            <p key={index}>{paragraph}</p>
                        ))}
                        {!pack.narrative && (
                            <p className="text-gray-500">No narrative has been drafted for this pack.</p>
                        )}
                    </div>
                )}
            </section>

            {/* --------------------------------------------------- figures */}
            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile
                    label="Live engagements"
                    value={portfolio.total ?? 0}
                    hint={`${portfolio.supports_critical_function ?? 0} support a critical function`}
                />
                <Tile
                    label="Mean residual"
                    value={portfolio.mean_residual === null || portfolio.mean_residual === undefined ? 'Not computed' : portfolio.mean_residual}
                    hint={`across ${portfolio.scored ?? 0} scored — ${portfolio.unscored ?? 0} excluded`}
                />
                <Tile
                    label="Require an exit plan, have none"
                    value={exit.no_plan ?? 0}
                    tone={exit.no_plan ? 'critical' : null}
                    hint={`of ${exit.require_a_plan ?? 0} that require one`}
                />
                <Tile
                    label="Open findings"
                    value={findings.open ?? 0}
                    tone={findings.overdue ? 'warn' : null}
                    hint={`${findings.overdue ?? 0} past their remediation date`}
                />
            </div>

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile
                    label="Overdue assessments"
                    value={assessments.overdue ?? 0}
                    tone={assessments.critical_or_high_overdue ? 'critical' : null}
                    hint={`${assessments.critical_or_high_overdue ?? 0} Critical or High`}
                />
                <Tile
                    label="Evidence already expired"
                    value={evidence.already_expired ?? 0}
                    tone={evidence.already_expired ? 'critical' : null}
                    hint={`${evidence.expiring ?? 0} expiring ${
                        evidence.horizon_days === undefined || evidence.horizon_days === null
                            ? 'within the reporting horizon'
                            : `within ${evidence.horizon_days} days`
                    }`}
                />
                <Tile
                    label="Incidents, 12 months"
                    value={incidents.count ?? 0}
                    hint={`${incidents.personal_data ?? 0} involved personal data`}
                />
                <Tile
                    label="Concentration (HHI)"
                    value={concentration.hhi === null || concentration.hhi === undefined ? 'Not computed' : Math.round(concentration.hhi)}
                    hint={concentration.band ?? 'unclassified'}
                />
            </div>

            <Section title="Top exposures">
                <Table
                    headers={['Engagement', 'Provider', 'Service', 'Tier', 'Residual', 'Band']}
                    rows={(figures.top_exposures ?? []).map((row) => [
                        row.reference, row.provider, row.service, row.tier, row.residual_score, row.residual_band,
                    ])}
                    empty="No engagement carries a residual score."
                />
            </Section>

            <Section title="Critical function dependency">
                <Table
                    headers={['Function', 'Criticality', 'RTO (hrs)', 'Providers', 'Depends on']}
                    rows={(figures.critical_functions ?? []).map((row) => [
                        `${row.function_code} ${row.function}${row.single_provider ? ' — single provider' : ''}`,
                        row.criticality,
                        row.rto_hours,
                        row.provider_count,
                        row.providers.map((p) => p.provider).join(', '),
                    ])}
                    empty="No critical or important function is linked to an engagement."
                />
            </Section>

            <Section title="Oldest open findings">
                <Table
                    headers={['Reference', 'Finding', 'Provider', 'Severity', 'Age (days)', 'Target']}
                    rows={(findings.oldest ?? []).map((row) => [
                        row.reference, row.title, row.provider, row.severity, row.age_days, row.target_date,
                    ])}
                    empty="No findings are open."
                />
            </Section>
        </AppLayout>
    );
}

function Section({ title, children }) {
    return (
        <section className="mb-6">
            <h2 className="mb-2 text-sm font-semibold text-gray-800">{title}</h2>
            {children}
        </section>
    );
}

function Table({ headers, rows, empty }) {
    return (
        <div className="card overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50">
                    <tr>
                        {headers.map((header) => (
                            <th key={header} scope="col" className="whitespace-nowrap px-4 py-2 text-left font-medium text-gray-600">
                                {header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {rows.map((row, index) => (
                        <tr key={index}>
                            {row.map((cell, cellIndex) => (
                                <td key={cellIndex} className="px-4 py-2 align-top">
                                    {cell === null || cell === undefined || cell === ''
                                        ? <span className="text-gray-400">—</span>
                                        : String(cell)}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={headers.length} className="px-4 py-8 text-center text-sm text-gray-500">
                                {empty}
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

function Tile({ label, value, hint, tone }) {
    const toneClass = tone === 'critical' ? 'text-red-600' : tone === 'warn' ? 'text-amber-600' : 'text-gray-900';

    return (
        <div className="card p-4">
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${toneClass}`}>{value}</div>
            {hint && <div className="mt-1 text-xs text-gray-500">{hint}</div>}
        </div>
    );
}
