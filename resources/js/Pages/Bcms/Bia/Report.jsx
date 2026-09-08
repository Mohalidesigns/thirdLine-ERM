import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The BIA report — the clause 8.2.2 artefact an examiner asks for.
 *
 * COVERAGE IS AT THE TOP AND THE GAPS ARE IN THE REPORT. A report over eleven
 * approved assessments out of fifty processes is a report about eleven
 * processes, and a reader who is not told that will circulate it as the whole
 * picture. The gap list is part of the artefact, not a footnote.
 */
export default function Report({
    rows = [], gaps = [], coverage, spof_register: spofRegister = [], shared = [],
    campaigns = [], selected, can = {},
}) {
    return (
        <AppLayout title="BIA report">
            <Head title="BIA report" />

            <PageHeader
                title="Business impact analysis report"
                subtitle="Ranked by criticality, then by how quickly the process must come back."
                actions={
                    <div className="flex gap-2">
                        <select className="rounded border-gray-300 text-sm" value={selected ?? ''}
                            onChange={(e) => router.get(tryRoute('bcms.bia-report.index'), { campaign: e.target.value || undefined })}>
                            <option value="">All approved assessments</option>
                            {campaigns.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        {can.export && (
                            <a className="btn-secondary text-sm"
                                href={tryRoute('bcms.bia-report.export', selected ? { campaign: selected } : {})}>
                                Export
                            </a>
                        )}
                    </div>
                }
            />

            <div className={`mb-6 rounded-lg border p-4 ${gaps.length > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}>
                {coverage.rate === null ? (
                    <p className="text-sm text-gray-600">There are no processes in scope, so there is no coverage to report.</p>
                ) : (
                    <p className="text-sm text-gray-900">
                        <span className="font-semibold">{coverage.approved} of {coverage.in_scope} processes ({coverage.rate}%)</span>{' '}
                        have an approved business impact assessment.
                        {gaps.length > 0 && ' The rest are listed below and are not represented in the table — a report that omits them is a report about a subset.'}
                    </p>
                )}
            </div>

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th className="px-4 py-3">Process</th>
                            <th className="px-4 py-3">Tier</th>
                            <th className="px-4 py-3">MTPD</th>
                            <th className="px-4 py-3">RTO</th>
                            <th className="px-4 py-3">RPO</th>
                            <th className="px-4 py-3">Dependencies</th>
                            <th className="px-4 py-3">Approved</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.length === 0 && (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-gray-500">
                                No approved assessments yet. The report shows approved ones only — a draft is not evidence.
                            </td></tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td className="px-4 py-3">
                                    <span className="text-gray-900">{r.name}</span>
                                    <span className="block font-mono text-xs text-gray-500">{r.code} · {r.unit ?? '—'}</span>
                                    {r.is_critical_service && (
                                        <span className="mt-1 inline-block rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-800">critical service</span>
                                    )}
                                </td>
                                <td className="px-4 py-3">{r.tier ? `Tier ${r.tier}` : '—'}</td>
                                <td className="px-4 py-3 font-mono">{r.mtpd_hours === null ? '—' : `${r.mtpd_hours} h`}</td>
                                <td className="px-4 py-3 font-mono">{r.rto_hours === null ? '—' : `${r.rto_hours} h`}</td>
                                <td className="px-4 py-3 font-mono">{r.rpo_minutes === null ? '—' : `${r.rpo_minutes} m`}</td>
                                <td className="px-4 py-3">
                                    {r.dependency_count}
                                    {r.spof_count > 0 && (
                                        <span className="ml-1 rounded bg-red-50 px-1.5 py-0.5 text-[11px] text-red-800">{r.spof_count} SPOF</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-xs text-gray-500">{r.approved_at}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {gaps.length > 0 && (
                <section className="mt-6 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-gray-900">Processes with no approved BIA ({gaps.length})</h2>
                    <ul className="mt-3 grid grid-cols-1 gap-1 text-sm sm:grid-cols-2">
                        {gaps.map((g) => (
                            <li key={g.id} className="text-gray-700">
                                <span className="font-mono text-xs text-gray-500">{g.code}</span> {g.name}
                                <span className="ml-1 text-xs text-gray-400">— {g.status}</span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {spofRegister.length > 0 && (
                <section className="mt-6 rounded-lg border border-gray-200 bg-white p-5">
                    <h2 className="text-sm font-semibold text-gray-900">Single points of failure ({spofRegister.length})</h2>
                    <ul className="mt-3 divide-y divide-gray-100 text-sm">
                        {spofRegister.map((s) => (
                            <li key={`${s.type}:${s.id}`} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <Link href={tryRoute('bcms.dependencies.impact-of', [s.type, s.id])} className="text-gray-900 underline">
                                    {s.name}
                                </Link>
                                <span className="text-xs text-gray-500">
                                    {s.type_label} · {s.criticality} · {s.process_count} process(es)
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AppLayout>
    );
}
