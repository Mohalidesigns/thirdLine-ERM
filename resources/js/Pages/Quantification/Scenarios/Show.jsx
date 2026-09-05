import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import StatusBadge from '@/Components/StatusBadge';
import SeriesChart from '@/Components/Quantification/SeriesChart';
import { NOT_RECORDED, naira, number } from '@/Components/Quantification/figures';
import tryRoute from '@/lib/tryRoute';

/**
 * One scenario (migration Phase 5.2).
 *
 * THE FIGURES ON THIS PAGE WERE ALL ZERO. The Blade version's four tiles and
 * its parameter panel read `mean`, `std_dev`, `frequency_per_year`,
 * `distribution_type`, `min_loss` and `max_loss` — the CREATE FORM's field
 * names, none of them columns on `quantification_scenarios` — so every
 * scenario showed Mean Loss ₦0, Std Deviation ₦0, Frequency 0/year and
 * Distribution "-", behind `?? 0` that kept it quiet. The page drew a
 * correctly-parameterised log-normal curve directly beside a panel claiming
 * its mean was zero.
 *
 * The values now come from ScenarioService::toFormValues(), the inverse of the
 * mapping the write path uses.
 */
export default function Show({ scenario, values, simulations, distributionVisualization }) {
    const simulateUrl = tryRoute('risk.quantification.simulate');
    const editUrl = tryRoute('risk.quantification.edit-scenario', scenario.id);
    const scenariosUrl = tryRoute('risk.quantification.scenarios');

    const row = (label, value) => (
        <div className="flex justify-between border-b border-gray-100 pb-2 last:border-0">
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="text-xs font-medium">{value ?? <span className="text-gray-400 italic">{NOT_RECORDED}</span>}</dd>
        </div>
    );

    return (
        <AuthenticatedLayout title={scenario.name}>
            <Head title={scenario.name} />

            <PageHeader
                title={scenario.name}
                subtitle={scenario.description}
                actions={
                    <>
                        {editUrl && (
                            <Link href={editUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                        {simulateUrl && (
                            <Link href={simulateUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">play_arrow</span> Run simulation
                            </Link>
                        )}
                        {scenariosUrl && (
                            <Link href={scenariosUrl} className="btn-secondary text-sm">
                                Back
                            </Link>
                        )}
                    </>
                }
            />

            <div className="flex items-center gap-2 mb-6">
                <span className="text-xs font-medium text-[#1A365D]">{scenario.scenario_reference}</span>
                <StatusBadge status={scenario.status ?? 'active'} />
                <span className="badge bg-blue-100 text-blue-700">{values.distribution_type ?? '—'}</span>
            </div>

            {/*
                WP-08. Scenarios created before the distribution list was restricted may
                still be typed pareto / weibull / normal / poisson / beta. The engine has
                only ever had a log-normal severity draw, so those scenarios were, and
                still are, simulated as log-normal. Saying so here is the honest reading
                of the badge above.
            */}
            {values.distribution_type && values.distribution_type !== 'lognormal' && (
                <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6 text-sm text-yellow-900">
                    <span className="font-semibold">Simulated as log-normal.</span> This scenario is recorded with a{' '}
                    <span className="font-semibold">{values.distribution_type}</span> severity distribution, but the
                    simulation engine implements only a log-normal severity draw, so every result produced for it is
                    log-normal. Re-calibrate it as log-normal, or wait for engine support, before relying on its tail.
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    title="Mean Loss per Event"
                    value={naira(values.mean)}
                    unavailable={values.mean === null}
                    unavailableLabel={NOT_RECORDED}
                    icon="payments"
                    color="primary"
                />
                <KpiCard
                    title="Std Deviation"
                    value={naira(values.std_dev)}
                    unavailable={values.std_dev === null}
                    unavailableLabel={NOT_RECORDED}
                    icon="analytics"
                    color="warning"
                />
                <KpiCard
                    title="Frequency"
                    value={values.frequency_per_year === null ? null : `${number(values.frequency_per_year, 2)}/year`}
                    unavailable={values.frequency_per_year === null}
                    unavailableLabel={NOT_RECORDED}
                    icon="event_repeat"
                    color="info"
                />
                <KpiCard
                    title="Expected Annual Loss"
                    value={naira(values.expected_annual_loss)}
                    unavailable={values.expected_annual_loss === null}
                    unavailableLabel={NOT_RECORDED}
                    icon="functions"
                    color="primary"
                    subtitle="Mean per event × frequency"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <section className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Severity Distribution</h3>
                    <p className="text-xs text-gray-500 mb-4">
                        The density the engine will draw from, from this scenario&apos;s stored log-normal parameters.
                    </p>
                    {distributionVisualization.labels.length > 0 ? (
                        <SeriesChart
                            labels={distributionVisualization.labels}
                            values={distributionVisualization.values}
                            ariaLabel="Severity probability density"
                            valueLabel={(value) => value.toFixed(3)}
                        />
                    ) : (
                        <p className="text-sm text-gray-400 italic py-8 text-center">
                            This scenario has no severity parameters to plot.
                        </p>
                    )}
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Scenario Parameters</h3>
                    <dl className="space-y-3">
                        {row('Reference', scenario.scenario_reference)}
                        {row('CBN risk category', values.risk_category)}
                        {row('Severity distribution', values.distribution_type)}
                        {row('Mean loss per event', naira(values.mean))}
                        {row('Standard deviation', naira(values.std_dev))}
                        {row('Minimum loss', naira(values.min_loss))}
                        {row('Maximum loss', naira(values.max_loss))}
                        {row(
                            'Frequency',
                            values.frequency_per_year === null ? null : `${number(values.frequency_per_year, 2)} events/year`,
                        )}
                    </dl>
                </section>
            </div>

            <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <header className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Simulations Including This Scenario</h3>
                </header>
                {simulations.length === 0 ? (
                    <p className="px-5 py-8 text-sm text-gray-500 text-center">
                        No simulation has been run over this scenario yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Status</th>
                                    <th className="text-right">Iterations</th>
                                    <th>Completed</th>
                                </tr>
                            </thead>
                            <tbody>
                                {simulations.map((simulation) => {
                                    const url = tryRoute('risk.quantification.show-results', simulation.id);

                                    return (
                                        <tr key={simulation.id}>
                                            <td className="font-medium text-[#1A365D]">
                                                {url ? (
                                                    <Link href={url} className="hover:underline">
                                                        {simulation.simulation_reference}
                                                    </Link>
                                                ) : (
                                                    simulation.simulation_reference
                                                )}
                                            </td>
                                            <td>
                                                <StatusBadge status={simulation.status} />
                                            </td>
                                            <td className="text-right text-xs">{number(simulation.iterations)}</td>
                                            <td className="text-xs text-gray-500">
                                                {simulation.completed_at
                                                    ? new Date(simulation.completed_at).toLocaleDateString('en-GB', {
                                                          day: '2-digit',
                                                          month: 'short',
                                                          year: 'numeric',
                                                      })
                                                    : '—'}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </AuthenticatedLayout>
    );
}
