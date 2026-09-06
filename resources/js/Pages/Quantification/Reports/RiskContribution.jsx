import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import ReportShell from '@/Components/Quantification/ReportShell';
import { naira, number, percent } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * A row is either Naira or an ordinal total, and the two must never be
 * rendered the same way.
 *
 * WP-08. Putting a ₦ in front of a sum of 1-25 matrix scores is how "capital
 * by business unit" got onto this page in the first place, so the basis flag
 * the service sends decides the formatting rather than the reader's
 * assumption.
 */
const amount = (value, basis) => (basis === 'expected_loss' ? naira(value) : number(value, 2));

const columnHeading = (basis) => (basis === 'expected_loss' ? 'Expected Annual Loss' : 'Residual Score Total');

const share = (value) => (value === null || value === undefined ? '—' : percent(value));

/** Risk Contribution Analysis (migration Phase 5.2). */
export default function RiskContribution({ byType, byTypeBasis, byUnit, byUnitBasis, latestSim, hasData }) {
    const runUrl = latestSim ? tryRoute('risk.quantification.show-results', latestSim.id) : null;

    return (
        <AuthenticatedLayout title="Risk Contribution Analysis">
            <Head title="Risk Contribution Analysis" />

            <ReportShell
                title="Risk Contribution Analysis"
                subtitle="Where modelled loss and residual risk sit, by risk type and business unit"
                notice={hasData ? null : 'No active risks or simulations available yet.'}
            >
                {latestSim && byTypeBasis === 'expected_loss' && (
                    <div className="mb-4 text-xs text-gray-500">
                        Source: simulation{' '}
                        {runUrl ? (
                            <Link href={runUrl} className="text-[#1A365D] font-semibold hover:underline">
                                {latestSim.simulation_reference}
                            </Link>
                        ) : (
                            <span className="font-semibold">{latestSim.simulation_reference}</span>
                        )}{' '}
                        &middot;{' '}
                        {latestSim.completed_at
                            ? new Date(latestSim.completed_at).toLocaleDateString('en-GB', {
                                  day: '2-digit',
                                  month: 'short',
                                  year: 'numeric',
                              })
                            : '—'}
                    </div>
                )}

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">By Risk Type</h3>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>{byTypeBasis === 'expected_loss' ? 'Scenario' : 'Risk Category'}</th>
                                        <th className="text-right">{columnHeading(byTypeBasis)}</th>
                                        <th className="text-right">Share</th>
                                        <th>Distribution</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {byType.length === 0 ? (
                                        <tr>
                                            <td colSpan={4} className="text-center py-6 text-gray-400">No data available</td>
                                        </tr>
                                    ) : (
                                        byType.map((row) => (
                                            <tr key={row.label}>
                                                <td className="font-medium text-[#1A365D]">{row.label}</td>
                                                <td className="text-right">{amount(row.value, byTypeBasis)}</td>
                                                <td className="text-right">{share(row.share_pct)}</td>
                                                <td className="min-w-[160px]">
                                                    <div className="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                                                        <div
                                                            className="h-2 bg-[#1A365D]"
                                                            style={{ width: `${Math.min(100, row.share_pct ?? 0)}%` }}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {byTypeBasis === 'residual_score' && (
                            <p className="px-5 py-3 text-xs text-yellow-800 bg-yellow-50 border-t border-yellow-200 leading-relaxed">
                                No completed simulation exists, so these are totals of residual risk scores — ordinal
                                points on the 1-25 matrix, not Naira and not capital. They are shown to indicate where
                                residual risk is concentrated. Run a Monte Carlo simulation for a monetary figure.
                            </p>
                        )}
                    </section>

                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">By Business Unit</h3>
                        </header>
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Business Unit</th>
                                        <th className="text-right">Risks</th>
                                        <th className="text-right">{columnHeading(byUnitBasis)}</th>
                                        <th className="text-right">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {byUnit.length === 0 ? (
                                        <tr>
                                            <td colSpan={4} className="text-center py-6 text-gray-400">No data available</td>
                                        </tr>
                                    ) : (
                                        byUnit.map((row) => (
                                            <tr key={row.label}>
                                                <td className="font-medium text-[#1A365D]">{row.label}</td>
                                                <td className="text-right">{Number(row.risks)}</td>
                                                <td className="text-right">{amount(row.value, byUnitBasis)}</td>
                                                <td className="text-right">{share(row.share_pct)}</td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <p className="px-5 py-3 text-xs text-gray-600 bg-gray-50 border-t border-gray-200 leading-relaxed">
                            Residual score totals, not capital. The simulation engine does not produce a loss
                            distribution per business unit, so no monetary allocation to business units exists to
                            report.
                        </p>
                    </section>
                </div>

                <section className="mt-6 bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Notes</h3>
                    <div className="text-sm text-gray-600 leading-relaxed space-y-3">
                        <p>
                            <span className="font-semibold text-[#1A365D]">This is not a capital allocation.</span> Where
                            a completed Monte Carlo run exists, the by-risk-type figures are each scenario&apos;s{' '}
                            <span className="font-semibold">expected annual loss</span> and its share of total expected
                            annual loss, taken from the engine&apos;s <code className="text-xs">risk_contributions</code>.
                            Expected loss is a mean, not a tail measure: it says nothing about how a scenario
                            contributes to VaR or to economic capital, and it systematically under-reports exactly the
                            scenarios that matter most for capital — the ones with a small mean and a fat tail. A
                            genuine capital allocation needs a component-VaR (Euler) decomposition, which this engine
                            does not compute. Do not read the shares above as economic capital by risk type.
                        </p>
                        <p>
                            Where no simulation has run, the figures are totals of residual risk scores from the
                            register. Those are ordinal 1-25 matrix values: not additive in any strict sense, not
                            denominated in Naira, and labelled accordingly. They were previously emitted under the key{' '}
                            <code className="text-xs">capital</code> and shown in a column headed &quot;Capital /
                            Score&quot;, which invited every reader to treat a sum of matrix scores as a naira capital
                            figure.
                        </p>
                    </div>
                </section>
            </ReportShell>
        </AuthenticatedLayout>
    );
}
