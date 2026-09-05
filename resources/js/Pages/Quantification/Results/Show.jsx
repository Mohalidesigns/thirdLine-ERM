import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import SeriesChart from '@/Components/Quantification/SeriesChart';
import useJobProgress from '@/hooks/useJobProgress';
import { NOT_ASSESSED, naira, number, percent } from '@/Components/Quantification/figures';
import tryRoute from '@/lib/tryRoute';

/** Naira compacted so six tiles fit without overflowing. */
function compactNaira(amount) {
    if (amount === null || amount === undefined) return null;

    const magnitude = Math.abs(Number(amount));
    if (magnitude >= 1e9) return `₦${(amount / 1e9).toFixed(2)}B`;
    if (magnitude >= 1e6) return `₦${(amount / 1e6).toFixed(2)}M`;
    if (magnitude >= 1e3) return `₦${(amount / 1e3).toFixed(1)}K`;

    return naira(amount);
}

const RUNNING = ['queued', 'running'];

/**
 * One simulation run (migration Phase 5.2).
 *
 * A run in flight polls its JobRun and offers to stop — the cancel path that,
 * until this phase, wrote to columns SimulationRun refused to fill and so
 * requested nothing of anybody.
 *
 * The "VaR (99.5%)" tile is now labelled 99.9%, which is the column the engine
 * stores; see SimulationService::toListRow().
 */
export default function Show({ result, percentiles, expectedShortfall, maxLoss, contributions, histogramData, contribChartData, cdfData }) {
    const isRunning = RUNNING.includes(result.status);
    const { progress } = useJobProgress(result.job_run_id, { enabled: isRunning });

    const pct = progress?.percent ?? result.progress ?? 0;

    const cancel = () => {
        if (!window.confirm('Ask this simulation to stop? A cancelled run keeps no results — a partial loss distribution is not a smaller answer.')) {
            return;
        }

        router.post(route('risk.quantification.cancel-simulation', result.id), {}, { preserveScroll: true });
    };

    const resultsUrl = tryRoute('risk.quantification.results');

    return (
        <AuthenticatedLayout title={result.simulation_reference}>
            <Head title={result.simulation_reference} />

            <PageHeader
                title={result.simulation_reference}
                subtitle={`${number(result.iterations)} iterations · ${result.scenario_count} scenarios · seed ${result.random_seed ?? 'not recorded'}`}
                actions={
                    <>
                        {isRunning && (
                            <button type="button" onClick={cancel} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">stop_circle</span> Request cancellation
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="btn-secondary text-sm inline-flex items-center gap-2 print:hidden"
                        >
                            <span className="material-symbols-outlined text-lg">print</span> Print
                        </button>
                        {resultsUrl && (
                            <Link href={resultsUrl} className="btn-secondary text-sm">
                                Back
                            </Link>
                        )}
                    </>
                }
            />

            <div className="flex items-center gap-2 mb-6">
                <StatusBadge status={result.status} />
                {result.cancel_requested_at && (
                    <span className="text-xs text-yellow-700">
                        Cancellation requested — the run stops at its next checkpoint.
                    </span>
                )}
            </div>

            {isRunning && (
                <div className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                    <div className="flex items-center justify-between mb-2">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Simulation in progress</h3>
                        <span className="text-sm text-gray-500">{pct}%</span>
                    </div>
                    <div className="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                        <div className="h-2 bg-[#1A365D] transition-all" style={{ width: `${pct}%` }} />
                    </div>
                    <p className="text-xs text-gray-500 mt-3">
                        Results appear here when the run finishes. Nothing is written until it completes — a cancelled
                        run keeps no partial distribution.
                    </p>
                </div>
            )}

            {result.status === 'failed' && (
                <div className="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 text-sm text-red-800">
                    <span className="font-semibold">This run failed.</span> {result.error_message ?? 'No error was recorded.'}
                </div>
            )}

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
                <KpiCard
                    title="Expected Loss"
                    value={compactNaira(result.expected_loss)}
                    unavailable={result.expected_loss === null}
                    icon="payments"
                    color="primary"
                />
                <KpiCard title="VaR (95%)" value={compactNaira(result.var_95)} unavailable={result.var_95 === null} icon="trending_up" color="warning" />
                <KpiCard title="VaR (99%)" value={compactNaira(result.var_99)} unavailable={result.var_99 === null} icon="trending_up" color="warning" />
                {/*
                    Headed 99.9%, which is the column MonteCarloService writes. The Blade
                    tile said 99.5% and showed this same figure — a larger loss than the
                    one it claimed to be.
                */}
                <KpiCard title="VaR (99.9%)" value={compactNaira(result.var_99_9)} unavailable={result.var_99_9 === null} icon="priority_high" color="danger" />
                {/*
                    Null, not ₦0, when no tail mean was stored: runs completed before
                    expected shortfall was computed have nothing to show, and a zero
                    here would read as a zero-loss tail.
                */}
                <KpiCard
                    title="Expected Shortfall"
                    value={compactNaira(expectedShortfall)}
                    unavailable={expectedShortfall === null}
                    unavailableLabel="Not computed for this run"
                    icon="warning"
                    color="danger"
                    subtitle="Mean loss beyond VaR 95"
                />
                <KpiCard
                    title="Max Simulated Loss"
                    value={compactNaira(maxLoss)}
                    unavailable={maxLoss === null || maxLoss === 0}
                    unavailableLabel={NOT_ASSESSED}
                    icon="error"
                    color="danger"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Loss Distribution by Percentile</h3>
                    {histogramData.labels.length > 0 ? (
                        <SeriesChart
                            labels={histogramData.labels}
                            values={histogramData.values}
                            ariaLabel="Loss distribution by percentile"
                            valueLabel={(value) => compactNaira(value) ?? ''}
                        />
                    ) : (
                        <p className="text-sm text-gray-400 italic py-8 text-center">
                            This run stored no percentile distribution.
                        </p>
                    )}
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Scenario Contributions</h3>
                    {contribChartData.labels.length > 0 ? (
                        <SeriesChart
                            labels={contribChartData.labels}
                            values={contribChartData.values}
                            ariaLabel="Each scenario's share of expected annual loss"
                            valueLabel={(value) => `${value.toFixed(0)}%`}
                        />
                    ) : (
                        <p className="text-sm text-gray-400 italic py-8 text-center">No scenario breakdown was stored.</p>
                    )}
                </section>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Cumulative Distribution</h3>
                    {cdfData.labels.length > 0 ? (
                        <SeriesChart
                            labels={cdfData.labels}
                            values={cdfData.values}
                            variant="line"
                            height={260}
                            ariaLabel="Cumulative loss distribution"
                            valueLabel={(value) => `${(value * 100).toFixed(0)}%`}
                        />
                    ) : (
                        <p className="text-sm text-gray-400 italic py-8 text-center">
                            This run stored no percentile distribution.
                        </p>
                    )}
                </section>

                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Percentile Table</h3>
                    </header>
                    {Object.keys(percentiles ?? {}).length === 0 ? (
                        <p className="px-5 py-8 text-sm text-gray-400 italic text-center">Nothing stored for this run.</p>
                    ) : (
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Percentile</th>
                                    <th className="text-right">Loss amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {Object.entries(percentiles).map(([label, value]) => (
                                    <tr key={label} className={['95%', '99%', '99.9%'].includes(label) ? 'bg-yellow-50 font-semibold' : ''}>
                                        <td>{label}</td>
                                        <td className="text-right">{naira(value)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
            </div>

            <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <header className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Scenario Contributions</h3>
                    <p className="text-xs text-gray-500 mt-1">
                        Each scenario&apos;s share of total EXPECTED ANNUAL LOSS — a mean, not a tail measure. It is not
                        a component-VaR allocation and says nothing about how a scenario contributes to capital.
                    </p>
                </header>
                {contributions.length === 0 ? (
                    <p className="px-5 py-8 text-sm text-gray-400 text-center">No scenario breakdown available</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Scenario</th>
                                    <th>Category</th>
                                    <th className="text-right">Expected loss</th>
                                    <th>Contribution</th>
                                    <th className="text-right">VaR (95%)</th>
                                </tr>
                            </thead>
                            <tbody>
                                {contributions.map((contribution, index) => (
                                    <tr key={index}>
                                        <td className="font-medium text-[#1A365D]">{contribution.scenario_name ?? '—'}</td>
                                        <td className="text-xs">{contribution.category ?? '—'}</td>
                                        <td className="text-right text-xs font-medium">{naira(contribution.expected_loss)}</td>
                                        <td>
                                            <div className="flex items-center gap-2">
                                                <div className="w-16 bg-gray-200 rounded-full h-1.5">
                                                    <div
                                                        className="h-1.5 rounded-full bg-[#1A365D]"
                                                        style={{ width: `${Math.min(100, contribution.contribution_pct ?? 0)}%` }}
                                                    />
                                                </div>
                                                <span className="text-xs">{percent(contribution.contribution_pct ?? 0)}</span>
                                            </div>
                                        </td>
                                        <td className="text-right text-xs font-medium">{naira(contribution.var_95)}</td>
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
