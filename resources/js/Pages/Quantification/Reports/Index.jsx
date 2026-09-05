import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import Pagination from '@/Components/Pagination';
import tryRoute from '@/lib/tryRoute';
import { number } from '@/Components/Quantification/figures';

/**
 * The report library (migration Phase 5.2).
 *
 * The six reports a preparer can open, and the completed runs behind them. The
 * Blade version's "PDF" button opened the report in a new window and called
 * print() on a timer; each report page now carries its own print action, so
 * the card links straight through.
 */
const REPORTS = [
    {
        title: 'ICAAP Report',
        description:
            'Full Internal Capital Adequacy Assessment Process report including stress testing and capital planning',
        icon: 'assessment',
        route: 'risk.quantification.icaap',
    },
    {
        title: 'Capital Adequacy Summary',
        description: 'Summary of capital position with Pillar 1 and Pillar 2 breakdown for CBN submission',
        icon: 'account_balance',
        route: 'risk.quantification.reports.capital-adequacy',
    },
    {
        title: 'Simulation Results Report',
        description: 'Detailed Monte Carlo simulation output with VaR, Expected Shortfall, and risk contributions',
        icon: 'calculate',
        route: 'risk.quantification.results',
    },
    {
        title: 'Stress Testing Report',
        description: 'Capital impact of the stress simulation bound to the latest ICAAP assessment, by confidence level',
        icon: 'crisis_alert',
        route: 'risk.quantification.reports.stress-testing',
    },
    {
        title: 'Risk Contribution Analysis',
        description: 'Expected annual loss by scenario, and residual risk score by business unit',
        icon: 'pie_chart',
        route: 'risk.quantification.reports.risk-contribution',
    },
    {
        title: 'Regulatory Compliance Pack',
        description: 'Combined regulatory reporting package for CBN ORMS compliance',
        icon: 'gavel',
        route: 'risk.quantification.reports.regulatory-pack',
    },
];

export default function Index({ completedSimulations }) {
    const date = (value) =>
        value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

    return (
        <AuthenticatedLayout title="Quantification Reports">
            <Head title="Quantification Reports" />

            <PageHeader
                title="Quantification Reports"
                subtitle="Generate and download risk quantification reports for regulatory submission"
            />

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {REPORTS.map((report) => {
                    const url = tryRoute(report.route);

                    return (
                        <div key={report.title} className="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-lg transition-shadow">
                            <div className="flex items-center gap-3 mb-3">
                                <div className="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
                                    <span className="material-symbols-outlined text-[#1A365D]">{report.icon}</span>
                                </div>
                                <h3 className="text-sm font-semibold text-[#1A365D]">{report.title}</h3>
                            </div>
                            <p className="text-xs text-gray-500 mb-4">{report.description}</p>
                            {url && (
                                <Link
                                    href={url}
                                    className="w-full px-3 py-2 bg-[#1A365D] text-white rounded-lg text-xs font-medium hover:bg-[#2D4A7A] text-center flex items-center justify-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-sm">visibility</span> Open
                                </Link>
                            )}
                        </div>
                    );
                })}
            </div>

            <section className="mt-6 bg-white rounded-xl border border-gray-200 overflow-hidden">
                <header className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Completed Simulations</h3>
                    <p className="text-xs text-gray-500 mt-1">The runs these reports can be produced from</p>
                </header>

                {completedSimulations.data.length === 0 ? (
                    <EmptyState
                        icon={<span className="material-symbols-outlined text-3xl text-gray-400">calculate</span>}
                        title="No completed simulations"
                        description="Run a Monte Carlo simulation over this institution's scenarios to produce a loss distribution these reports can read."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th className="text-right">Iterations</th>
                                    <th>Seed</th>
                                    <th>Completed</th>
                                </tr>
                            </thead>
                            <tbody>
                                {completedSimulations.data.map((simulation) => {
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
                                            <td className="text-right">{number(simulation.iterations)}</td>
                                            <td className="text-xs text-gray-500">{simulation.random_seed ?? 'not recorded'}</td>
                                            <td className="text-xs text-gray-500">{date(simulation.completed_at)}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination links={completedSimulations.links} />
            </section>
        </AuthenticatedLayout>
    );
}
