import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import ReportShell, { LibraryLink } from '@thirdline/ui/Components/ReportShell';
import { number, percent } from '@/Components/Quantification/figures';

/**
 * CBN regulatory compliance report (migration Phase 5.4).
 *
 * The pillar list and every score in it come from the controller, which scores
 * only the three pillars this product measures and marks the other five NOT
 * ASSESSED with the reason. That block used to hold its own eight-entry list
 * with a numeric literal on each line (90, 85, 78, 82, 75, 88, 70, 65), and
 * the "derived" values were barely better — Business Continuity was a constant
 * 70 for every tenant on the platform, and the product stores no business
 * continuity data at all. Eight full progress bars on a report headed "CBN
 * Regulatory Compliance" is precisely the screenshot a bank should not be able
 * to produce from an empty system.
 *
 * An unassessed pillar draws a DASHED OUTLINE rather than a bar. A zero-length
 * bar would read as a score of nought.
 */
export default function Regulatory({
    overallCompliance,
    pendingReturns,
    overdueItems,
    cbnDirectives,
    ormsPillars,
    regulatoryReturns,
    directives,
    year,
    quarter,
}) {
    const scoreColour = (score, prefix) =>
        score >= 90 ? `${prefix}-green-${prefix === 'bg' ? '500' : '600'}` : score >= 70 ? `${prefix}-yellow-${prefix === 'bg' ? '500' : '600'}` : `${prefix}-red-${prefix === 'bg' ? '500' : '600'}`;

    return (
        <AuthenticatedLayout title="Regulatory Compliance Report">
            <Head title="Regulatory Compliance Report" />

            <ReportShell
                title="CBN Regulatory Compliance Report"
                subtitle={`ORMS compliance status, regulatory returns, and CBN directive tracking · Q${quarter} ${year}`}
                routeName="risk.reports.regulatory"
                actions={<LibraryLink />}
            >
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    {/* Null, not 0%, when none of the three underlying measures has any data behind it. */}
                    <KpiCard
                        title="Overall Compliance"
                        value={percent(overallCompliance)}
                        unavailable={overallCompliance === null}
                        unavailableLabel="Nothing measured"
                        icon="verified"
                        color={overallCompliance === null ? 'info' : overallCompliance >= 90 ? 'success' : overallCompliance >= 70 ? 'warning' : 'danger'}
                    />
                    <KpiCard title="Pending Returns" value={number(pendingReturns)} icon="description" color="warning" subtitle="Regulatory filings" />
                    <KpiCard title="Overdue Items" value={number(overdueItems)} icon="error" color="danger" />
                    <KpiCard title="CBN Directives" value={number(cbnDirectives)} icon="gavel" color="info" subtitle="Active directives" />
                </div>

                <section className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">CBN ORMS Framework Compliance</h3>

                    <div className="space-y-4">
                        {ormsPillars.map(([area, score, basis]) => (
                            <div key={area} className="flex items-center gap-4">
                                <div className="w-60 text-xs font-medium text-gray-700 shrink-0">
                                    {area}
                                    <span className="block text-[11px] font-normal text-gray-400 leading-tight mt-0.5">{basis}</span>
                                </div>
                                <div className="flex-1">
                                    {score === null ? (
                                        <div className="w-full border border-dashed border-gray-300 rounded-full h-3" />
                                    ) : (
                                        <div className="w-full bg-gray-200 rounded-full h-3">
                                            <div
                                                className={`h-3 rounded-full ${scoreColour(score, 'bg')}`}
                                                style={{ width: `${score}%` }}
                                            />
                                        </div>
                                    )}
                                </div>
                                {score === null ? (
                                    <span className="text-[11px] italic w-28 text-right text-gray-400">Not assessed</span>
                                ) : (
                                    <span className={`text-xs font-bold w-28 text-right ${scoreColour(score, 'text')}`}>{score}%</span>
                                )}
                            </div>
                        ))}
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Regulatory Returns Schedule</h3>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Return</th><th>Regulator</th><th>Frequency</th><th>Due date</th><th>Status</th><th>Filed by</th>
                                </tr>
                            </thead>
                            <tbody>
                                {regulatoryReturns.length === 0 ? (
                                    <tr><td colSpan={6} className="text-center py-8 text-gray-400">No regulatory returns scheduled</td></tr>
                                ) : (
                                    regulatoryReturns.map((ret, index) => (
                                        <tr key={index} className={ret.status === 'overdue' ? 'border-l-4 border-l-red-500' : ''}>
                                            <td className="text-xs font-medium text-[#1A365D]">{ret.name ?? '—'}</td>
                                            <td className="text-xs">{ret.regulator ?? 'CBN'}</td>
                                            <td className="text-xs">{ret.frequency ?? '—'}</td>
                                            <td className={`text-xs ${ret.status === 'overdue' ? 'text-red-600 font-semibold' : 'text-gray-500'}`}>
                                                {ret.due_date ?? '—'}
                                            </td>
                                            <td><StatusBadge status={ret.status ?? 'pending'} /></td>
                                            <td className="text-xs">{ret.filed_by ?? '—'}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Active CBN Directives &amp; Circulars</h3>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th><th>Title</th><th>Date issued</th><th>Compliance deadline</th><th>Status</th><th>Impact</th>
                                </tr>
                            </thead>
                            <tbody>
                                {directives.length === 0 ? (
                                    <tr><td colSpan={6} className="text-center py-8 text-gray-400">No active directives</td></tr>
                                ) : (
                                    directives.map((dir, index) => (
                                        <tr key={index}>
                                            <td className="text-xs font-medium text-[#1A365D]">{dir.reference ?? '—'}</td>
                                            <td className="text-xs">{dir.title ?? '—'}</td>
                                            <td className="text-xs text-gray-500">{dir.issued_date ?? '—'}</td>
                                            <td className="text-xs">{dir.deadline ?? '—'}</td>
                                            <td><StatusBadge status={dir.status ?? 'pending'} /></td>
                                            {/* A circular with no impact_level recorded is Unrated, not Medium. */}
                                            <td><RatingBadge rating={(dir.impact ?? 'unrated').toLowerCase()} /></td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </ReportShell>
        </AuthenticatedLayout>
    );
}
