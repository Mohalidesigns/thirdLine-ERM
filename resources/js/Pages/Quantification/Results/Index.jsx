import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import Pagination from '@thirdline/ui/Components/Pagination';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import FilterBar from '@thirdline/ui/Components/FilterBar';
import { naira, number } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Every Monte Carlo run (migration Phase 5.2).
 *
 * THE 99.5% COLUMN WAS THE 99.9% FIGURE. `getVar995Attribute()` reads
 * `var_99_9_kobo` because the engine stores no 99.5 column, so the Blade table
 * headed a column "VaR (99.5%)" and printed the 99.9 loss under it — a larger
 * number than the one it claimed to be, on a page read as the output of a
 * capital model. The heading now says what the figure is.
 *
 * A run with no aggregate result — queued, running, failed, cancelled — reports
 * "—" rather than ₦0. A zero VaR is a claim about the portfolio.
 */
export default function Index({ results, filters }) {
    const simulateUrl = tryRoute('risk.quantification.simulate');

    const money = (value) => naira(value) ?? <span className="text-gray-400">—</span>;

    return (
        <AuthenticatedLayout title="Simulation Results">
            <Head title="Simulation Results" />

            <PageHeader
                title="Simulation Results"
                subtitle="All Monte Carlo simulation runs and their results"
                actions={
                    simulateUrl && (
                        <Link href={simulateUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">play_arrow</span> New simulation
                        </Link>
                    )
                }
            />

            <FilterBar
                route={route('risk.quantification.results')}
                currentFilters={filters}
                filters={[
                    {
                        name: 'status',
                        type: 'select',
                        label: 'Status',
                        options: [
                            { value: 'queued', label: 'Queued' },
                            { value: 'running', label: 'Running' },
                            { value: 'completed', label: 'Completed' },
                            { value: 'failed', label: 'Failed' },
                            { value: 'cancelled', label: 'Cancelled' },
                        ],
                    },
                ]}
            />

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                {results.data.length === 0 ? (
                    <EmptyState
                        icon={<span className="material-symbols-outlined text-3xl text-gray-400">calculate</span>}
                        title="No simulation results yet"
                        description="Run a Monte Carlo simulation over this institution's scenarios to produce a loss distribution."
                        actionLabel={simulateUrl ? 'Run the first simulation' : undefined}
                        actionHref={simulateUrl ?? undefined}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Run</th>
                                    <th>Started</th>
                                    <th className="text-right">Scenarios</th>
                                    <th className="text-right">Iterations</th>
                                    <th className="text-right">Expected loss</th>
                                    <th className="text-right">VaR (95%)</th>
                                    <th className="text-right">VaR (99%)</th>
                                    <th className="text-right">VaR (99.9%)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {results.data.map((run) => {
                                    const url = tryRoute('risk.quantification.show-results', run.id);

                                    return (
                                        <tr key={run.id} className="hover:bg-blue-50/50">
                                            <td className="font-medium text-[#1A365D]">
                                                {url ? (
                                                    <Link href={url} className="hover:underline">
                                                        {run.simulation_reference}
                                                    </Link>
                                                ) : (
                                                    run.simulation_reference
                                                )}
                                            </td>
                                            <td className="text-xs text-gray-500">
                                                {run.created_at
                                                    ? new Date(run.created_at).toLocaleString('en-GB', {
                                                          day: '2-digit',
                                                          month: 'short',
                                                          year: 'numeric',
                                                          hour: '2-digit',
                                                          minute: '2-digit',
                                                      })
                                                    : '—'}
                                            </td>
                                            <td className="text-right text-xs">{run.scenario_count}</td>
                                            <td className="text-right text-xs">{number(run.iterations)}</td>
                                            <td className="text-right text-xs font-medium">{money(run.expected_loss)}</td>
                                            <td className="text-right text-xs font-medium">{money(run.var_95)}</td>
                                            <td className="text-right text-xs font-medium">{money(run.var_99)}</td>
                                            <td className="text-right text-xs font-medium">{money(run.var_99_9)}</td>
                                            <td>
                                                <StatusBadge status={run.status} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination links={results.links} />
            </div>
        </AuthenticatedLayout>
    );
}
