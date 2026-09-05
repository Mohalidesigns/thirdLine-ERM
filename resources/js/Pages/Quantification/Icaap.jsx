import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import Figure from '@/Components/Quantification/Figure';
import { NOT_ASSESSED, NOT_RECORDED, naira, percent, trimmedPercent } from '@/Components/Quantification/figures';
import tryRoute from '@/lib/tryRoute';
import { Link } from '@inertiajs/react';

/**
 * ICAAP — capital adequacy, the pillars and the stress reconciliation
 * (migration Phase 5.2).
 *
 * Every figure comes from IcaapService and is pinned by
 * Characterisation/IcaapCharacterisationTest. The rules the page has to render
 * faithfully, all of which replaced fabricated numbers an August 2026 audit
 * found:
 *
 *   - an absent input stays ABSENT — 0% CAR is a specific, catastrophic claim;
 *   - Pillar 2A is reported exactly as stored, with no decomposition;
 *   - available capital is null until EVERY deduction is known, because a
 *     waterfall with a missing bar is not a smaller waterfall;
 *   - the preparer's own CAR is reconciled against the computed one rather
 *     than one quietly winning.
 */
export default function Icaap({
    assessment,
    hasAssessment,
    minimumCar,
    organizationMinimumCar,
    internationalMinimumCar,
    conservationBuffer,
    conservationBufferAmount,
    totalCapital,
    totalRwa,
    cet1Capital,
    tier1Capital,
    tier2Capital,
    carComputed,
    carReported,
    carVariance,
    carVarianceMaterial,
    cet1Ratio,
    tier1Ratio,
    pillar1Requirement,
    pillar2aCredit,
    pillar2aMarket,
    pillar2aOperational,
    pillar2aOther,
    totalPillar2a,
    pillar2bStressBuffer,
    stressSimulation,
    stressRows,
    stressHeadline,
    stressAggregateMissing,
    availableCapital,
    waterfallData,
    waterfallMissing,
}) {
    const money = (value, absent = NOT_RECORDED) => (
        <Figure value={value} formatted={naira(value)} absent={absent} />
    );

    const ratio = (value) => <Figure value={value} formatted={percent(value)} absent={NOT_ASSESSED} />;

    const runUrl = stressSimulation ? tryRoute('risk.quantification.show-results', stressSimulation.id) : null;

    return (
        <AuthenticatedLayout title="ICAAP">
            <Head title="ICAAP" />

            <PageHeader
                title="Internal Capital Adequacy Assessment"
                subtitle={
                    hasAssessment
                        ? `Period ${assessment.period} · prepared ${new Date(assessment.created_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}`
                        : 'No assessment on record'
                }
                actions={
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="btn-secondary text-sm inline-flex items-center gap-2 print:hidden"
                    >
                        <span className="material-symbols-outlined text-lg">print</span> Print
                    </button>
                }
            />

            {!hasAssessment && (
                <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 text-sm text-yellow-800">
                    No ICAAP assessment has been recorded for this organisation. Every figure below is sourced from an
                    assessment, so the screen stays blank rather than showing zeroes.
                </div>
            )}

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    title="Capital Adequacy Ratio"
                    value={percent(carComputed)}
                    unavailable={carComputed === null}
                    icon="shield"
                    color={carComputed !== null && carComputed >= minimumCar ? 'success' : 'danger'}
                    subtitle={`CBN minimum: ${trimmedPercent(minimumCar)}%`}
                />
                <KpiCard
                    title="Total Qualifying Capital"
                    value={naira(totalCapital)}
                    unavailable={totalCapital === null}
                    unavailableLabel={NOT_RECORDED}
                    icon="account_balance"
                    color="primary"
                />
                <KpiCard
                    title="CET1 Ratio"
                    value={percent(cet1Ratio)}
                    unavailable={cet1Ratio === null}
                    icon="verified_user"
                    color="success"
                />
                <KpiCard
                    title="Available Capital"
                    value={naira(availableCapital)}
                    unavailable={availableCapital === null}
                    unavailableLabel="Incomplete waterfall"
                    icon="savings"
                    color={availableCapital !== null && availableCapital >= 0 ? 'success' : 'danger'}
                    subtitle="After every deduction"
                />
            </div>

            {carVarianceMaterial && (
                <div className="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 text-sm text-red-800">
                    <span className="font-semibold">CAR variance.</span> The ratio computed from stored capital and RWA (
                    {percent(carComputed)}) differs from the CAR recorded on the assessment ({percent(carReported)}) by{' '}
                    {Math.abs(carVariance).toFixed(2)} percentage points. Both are shown; neither is overwritten.
                </div>
            )}

            {organizationMinimumCar !== null && organizationMinimumCar !== minimumCar && (
                <div className="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6 text-sm text-gray-700">
                    This assessment was prepared against a minimum CAR of {trimmedPercent(minimumCar)}%, while the
                    organisation now stands at {trimmedPercent(organizationMinimumCar)}%. An assessment is reconciled
                    against the minimum it was prepared under, so both are shown. A bank on international authorisation
                    or designated a D-SIB is measured against {trimmedPercent(internationalMinimumCar)}%.
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Capital Structure</h3>
                    </header>
                    <table className="data-table">
                        <thead>
                            <tr><th>Component</th><th className="text-right">Amount</th><th className="text-right">Ratio to RWA</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>CET1 capital</td><td className="text-right">{money(cet1Capital)}</td><td className="text-right">{ratio(cet1Ratio)}</td></tr>
                            <tr><td>Tier 1 capital</td><td className="text-right">{money(tier1Capital)}</td><td className="text-right">{ratio(tier1Ratio)}</td></tr>
                            <tr><td>Tier 2 capital</td><td className="text-right">{money(tier2Capital)}</td><td className="text-right">—</td></tr>
                            <tr className="font-semibold bg-gray-50">
                                <td>Total qualifying capital</td>
                                <td className="text-right">{money(totalCapital)}</td>
                                <td className="text-right">{ratio(carComputed)}</td>
                            </tr>
                            <tr><td>Total risk-weighted assets</td><td className="text-right">{money(totalRwa)}</td><td className="text-right">—</td></tr>
                        </tbody>
                    </table>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Capital Demand</h3>
                        <p className="text-xs text-gray-500 mt-1">Pillar 2A is reported exactly as stored; nothing is apportioned</p>
                    </header>
                    <table className="data-table">
                        <thead>
                            <tr><th>Component</th><th className="text-right">Capital charge</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Pillar 1 requirement</td><td className="text-right">{money(pillar1Requirement, NOT_ASSESSED)}</td></tr>
                            <tr><td>Pillar 2A — Credit risk</td><td className="text-right">{money(pillar2aCredit)}</td></tr>
                            <tr><td>Pillar 2A — Market risk</td><td className="text-right">{money(pillar2aMarket)}</td></tr>
                            <tr><td>Pillar 2A — Operational risk</td><td className="text-right">{money(pillar2aOperational)}</td></tr>
                            <tr><td>Pillar 2A — Other</td><td className="text-right">{money(pillar2aOther)}</td></tr>
                            <tr className="font-semibold bg-gray-50"><td>Total Pillar 2A</td><td className="text-right">{money(totalPillar2a)}</td></tr>
                            <tr><td>Pillar 2B — Stress buffer</td><td className="text-right">{money(pillar2bStressBuffer)}</td></tr>
                            <tr>
                                <td>Conservation buffer ({trimmedPercent(conservationBuffer)}% of RWA)</td>
                                <td className="text-right">{money(conservationBufferAmount, NOT_ASSESSED)}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <section className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Capital Waterfall</h3>
                <p className="text-xs text-gray-500 mb-4">
                    Total qualifying capital less every requirement. A bar the assessment does not carry is left as a
                    gap rather than drawn as a zero, and available capital is withheld until all of them are known.
                </p>

                <div className="space-y-2">
                    {waterfallData.labels.map((label, index) => {
                        const value = waterfallData.values[index];
                        const magnitudes = waterfallData.values.filter((v) => v !== null).map(Math.abs);
                        const scale = magnitudes.length > 0 ? Math.max(...magnitudes) : 0;

                        return (
                            <div key={label} className="flex items-center gap-3">
                                <div className="w-56 text-xs text-gray-600 shrink-0">{label}</div>
                                <div className="flex-1 bg-gray-100 rounded h-5 overflow-hidden">
                                    {value !== null && scale > 0 && (
                                        <div
                                            className={`h-5 ${value >= 0 ? 'bg-[#1A365D]' : 'bg-red-500'}`}
                                            style={{ width: `${(Math.abs(value) / scale) * 100}%` }}
                                        />
                                    )}
                                </div>
                                <div className="w-44 text-right text-xs font-medium shrink-0">
                                    {money(value, NOT_ASSESSED)}
                                </div>
                            </div>
                        );
                    })}
                </div>

                {waterfallMissing.length > 0 && (
                    <p className="text-xs text-yellow-800 bg-yellow-50 border border-yellow-200 rounded-lg p-3 mt-4">
                        Available capital is withheld because {waterfallMissing.join(', ')}{' '}
                        {waterfallMissing.length === 1 ? 'is' : 'are'} not on the assessment. A waterfall with a missing
                        bar is not a smaller waterfall.
                    </p>
                )}
            </section>

            <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <header className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Stress Reconciliation</h3>
                    <p className="text-xs text-gray-500 mt-1">
                        {stressSimulation ? (
                            <>
                                Source:{' '}
                                {runUrl ? (
                                    <Link href={runUrl} className="text-[#1A365D] font-semibold hover:underline">
                                        {stressSimulation.simulation_reference}
                                    </Link>
                                ) : (
                                    <span className="font-semibold">{stressSimulation.simulation_reference}</span>
                                )}
                                {stressHeadline && <> &middot; headline at {trimmedPercent(stressHeadline.confidence)}% confidence</>}
                            </>
                        ) : (
                            'No simulation is bound to this assessment as its stress run.'
                        )}
                    </p>
                </header>

                {stressAggregateMissing && (
                    <p className="px-5 py-3 text-xs text-yellow-800 bg-yellow-50 border-b border-yellow-200">
                        The bound run has no aggregate loss distribution, so no capital impact can be derived from it.
                    </p>
                )}

                {stressRows.length === 0 ? (
                    <p className="px-5 py-8 text-sm text-gray-500 text-center">
                        Bind a completed simulation to this assessment to report capital impact under stress. This
                        screen does not fall back to the most recent run — that fallback is how an unrelated
                        single-scenario calibration once reached a board as a macroeconomic stress test.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Confidence</th>
                                    <th className="text-right">Capital impact</th>
                                    <th className="text-right">Capital after</th>
                                    <th className="text-right">CAR after</th>
                                    <th className="text-right">Shortfall</th>
                                    <th>Verdict</th>
                                </tr>
                            </thead>
                            <tbody>
                                {stressRows.map((row) => (
                                    <tr key={row.confidence}>
                                        <td className="font-medium text-[#1A365D]">{trimmedPercent(row.confidence)}%</td>
                                        <td className="text-right text-red-600">-{naira(row.capital_impact)}</td>
                                        <td className="text-right">{money(row.capital_after, NOT_ASSESSED)}</td>
                                        <td
                                            className={`text-right ${
                                                row.car_after === null ? '' : row.meets_minimum ? 'text-green-600' : 'text-red-600'
                                            }`}
                                        >
                                            {ratio(row.car_after)}
                                        </td>
                                        <td className="text-right">
                                            {row.shortfall === null ? (
                                                <span className="text-gray-400 italic">{NOT_ASSESSED}</span>
                                            ) : row.shortfall > 0 ? (
                                                <span className="text-red-600 font-medium">{naira(row.shortfall)}</span>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td>
                                            {row.meets_minimum === null ? (
                                                <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-gray-100 text-gray-600">Not assessable</span>
                                            ) : row.meets_minimum ? (
                                                <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-green-100 text-green-700">Pass</span>
                                            ) : (
                                                <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-red-100 text-red-700">Breach</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
