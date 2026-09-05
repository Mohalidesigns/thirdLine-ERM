import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import RatingBadge from '@/Components/RatingBadge';
import Figure from '@/Components/Quantification/Figure';
import ReportShell, { LibraryLink } from '@/Components/Reporting/ReportShell';
import { NOT_ASSESSED, naira, number, percent, trimmedPercent } from '@/Components/Quantification/figures';
import tryRoute from '@/lib/tryRoute';

/**
 * Board risk report (migration Phase 5.4).
 *
 * Figures from BoardReportService, pinned by
 * Characterisation/BoardReportCharacterisationTest.
 *
 * The capital tile is the one figure this phase changed: it was
 * `round((float) $icaap->car_actual, 1)`, and `car_actual` is nullable, so an
 * assessment on file without a typed ratio rendered "0%" — the number WP-08
 * called the one a screen must never invent. It comes through IcaapService
 * now, so the board pack and the ICAAP screen cannot disagree.
 *
 * The "Download" here assembles the full ordered board pack through
 * BoardPackAssembler, not just this page.
 */
export default function Board({
    criticalRisksForBoard,
    criticalRisks,
    appetiteUtilization,
    appetiteCategoriesWithTolerance,
    controlEffectiveness,
    riskProfileScore,
    profileChartData,
    appetiteChartData,
    executiveSummary,
    capitalAdequacyRatio,
    capitalAdequacyMinimum,
    boardActions,
}) {
    const sectionsUrl = tryRoute('risk.reports.board-pack-sections');

    const carMeets =
        capitalAdequacyRatio !== null && capitalAdequacyMinimum !== null && capitalAdequacyRatio >= capitalAdequacyMinimum;

    return (
        <AuthenticatedLayout title="Board Report">
            <Head title="Board Report" />

            <ReportShell
                title="Board Risk Report"
                subtitle="The pack the Board reads, assembled from the register, the appetite statements and the latest ICAAP"
                routeName="risk.reports.board"
                formats={['pdf']}
                actions={
                    <>
                        {sectionsUrl && (
                            <Link
                                href={sectionsUrl}
                                className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                            >
                                <span className="material-symbols-outlined text-lg">tune</span> Pack sections
                            </Link>
                        )}
                        <LibraryLink />
                    </>
                }
            >
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <KpiCard title="Critical Risks" value={number(criticalRisks)} icon="error" color="danger" />
                    <KpiCard
                        title="Appetite Utilization"
                        value={appetiteUtilization === null ? null : `${appetiteUtilization}%`}
                        unavailable={appetiteUtilization === null}
                        unavailableLabel="No tolerance declared"
                        icon="speed"
                        color="primary"
                        subtitle={
                            appetiteCategoriesWithTolerance > 0
                                ? `Across ${appetiteCategoriesWithTolerance} categor${appetiteCategoriesWithTolerance === 1 ? 'y' : 'ies'} with a tolerance`
                                : undefined
                        }
                    />
                    <KpiCard
                        title="Control Effectiveness"
                        value={percent(controlEffectiveness)}
                        unavailable={controlEffectiveness === null}
                        unavailableLabel="Nothing rated"
                        icon="verified_user"
                        color="success"
                    />
                    <KpiCard
                        title="Capital Adequacy"
                        value={capitalAdequacyRatio === null ? null : `${capitalAdequacyRatio}%`}
                        unavailable={capitalAdequacyRatio === null}
                        unavailableLabel={NOT_ASSESSED}
                        icon="account_balance"
                        color={carMeets ? 'success' : 'danger'}
                        subtitle={
                            capitalAdequacyMinimum !== null
                                ? `Regulatory minimum: ${trimmedPercent(capitalAdequacyMinimum)}%`
                                : undefined
                        }
                    />
                </div>

                <section className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Executive Summary</h3>
                    <p className="text-sm text-gray-700 leading-relaxed">{executiveSummary}</p>
                    <p className="text-xs text-gray-500 mt-3">
                        Risk profile:{' '}
                        <Figure value={riskProfileScore} formatted={riskProfileScore} absent="not scored" />
                    </p>
                </section>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Risk Profile by Category</h3>
                        <p className="text-xs text-gray-500 mb-4">Average inherent and residual score, on a five-point scale</p>

                        {profileChartData.labels.length === 0 ? (
                            <p className="text-sm text-gray-400 italic py-10 text-center">Nothing scored yet.</p>
                        ) : (
                            <div className="space-y-2">
                                {profileChartData.labels.map((label, index) => (
                                    <div key={label} className="flex items-center gap-3">
                                        <div className="w-40 text-xs text-gray-600 shrink-0">{label}</div>
                                        <div className="flex-1 bg-gray-100 rounded h-4 overflow-hidden relative">
                                            <div
                                                className="h-4 bg-[#1A365D]/70"
                                                style={{ width: `${Math.min(100, (profileChartData.inherent[index] / 5) * 100)}%` }}
                                            />
                                        </div>
                                        <div className="w-28 text-right text-xs">
                                            {profileChartData.inherent[index]} / {profileChartData.residual[index]}
                                        </div>
                                    </div>
                                ))}
                                <p className="text-[11px] text-gray-400 pt-1">Bar is inherent; the pair reads inherent / residual.</p>
                            </div>
                        )}
                    </section>

                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Appetite vs Current Position</h3>
                        <p className="text-xs text-gray-500 mb-4">
                            The appetite figure is the DECLARED upper tolerance, absent where the Board has not set
                            one — it was previously a flat literal 3 across every category, which looked like a
                            Board-approved limit.
                        </p>

                        {appetiteChartData.labels.length === 0 ? (
                            <p className="text-sm text-gray-400 italic py-10 text-center">Nothing scored yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="data-table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th className="text-right">Declared tolerance</th>
                                            <th className="text-right">Current position</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {appetiteChartData.labels.map((label, index) => {
                                            const appetite = appetiteChartData.appetite[index];
                                            const current = appetiteChartData.current[index];

                                            return (
                                                <tr key={label}>
                                                    <td className="text-xs">{label}</td>
                                                    <td className="text-right text-xs">
                                                        <Figure value={appetite} formatted={appetite} absent="not declared" />
                                                    </td>
                                                    <td
                                                        className={`text-right text-xs font-medium ${
                                                            appetite !== null && current > appetite ? 'text-red-600' : ''
                                                        }`}
                                                    >
                                                        {current}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Critical Risks for Board Attention</h3>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Risk code</th>
                                    <th>Title</th>
                                    <th>Category</th>
                                    <th>Rating</th>
                                    <th>Owner</th>
                                    <th>Treatment</th>
                                </tr>
                            </thead>
                            <tbody>
                                {criticalRisksForBoard.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="text-center py-8 text-gray-400">
                                            No critical risks currently require Board-level attention.
                                        </td>
                                    </tr>
                                ) : (
                                    criticalRisksForBoard.map((risk) => {
                                        const url = tryRoute('risk.register.show', risk.id);

                                        return (
                                            <tr key={risk.id}>
                                                <td className="font-medium text-[#1A365D]">
                                                    {url ? (
                                                        <Link href={url} className="hover:underline">
                                                            {risk.risk_code}
                                                        </Link>
                                                    ) : (
                                                        risk.risk_code
                                                    )}
                                                </td>
                                                <td className="text-xs">{risk.title}</td>
                                                <td className="text-xs">{risk.category ?? '—'}</td>
                                                <td>
                                                    <RatingBadge rating={(risk.inherent_rating ?? '').toLowerCase()} />
                                                </td>
                                                <td className="text-xs">{risk.owner ?? '—'}</td>
                                                <td>
                                                    <StatusBadge status={risk.derived_treatment_status} type="treatment" />
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Decisions and Actions for the Board</h3>
                        <p className="text-xs text-gray-500 mt-1">Overdue treatment plans and approvals awaiting a decision</p>
                    </header>

                    {boardActions.length === 0 ? (
                        <p className="px-5 py-8 text-sm text-gray-500 text-center">
                            Nothing is overdue and no approval is waiting.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-100">
                            {boardActions.map((action, index) => (
                                <li key={index} className="px-5 py-3">
                                    <div className="flex items-center gap-3">
                                        <span className="badge bg-red-100 text-red-700 text-[10px]">{action.priority}</span>
                                        <p className="text-sm font-medium text-gray-800 flex-1">{action.title}</p>
                                        <span className="text-xs text-gray-500">{action.due_date ?? '—'}</span>
                                    </div>
                                    <p className="text-xs text-gray-500 mt-1">{action.description}</p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </ReportShell>
        </AuthenticatedLayout>
    );
}
