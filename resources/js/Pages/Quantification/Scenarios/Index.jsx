import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import Pagination from '@thirdline/ui/Components/Pagination';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import FilterBar from '@thirdline/ui/Components/FilterBar';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import { naira, number } from '@/Components/Quantification/figures';

/**
 * The scenario register (migration Phase 5.2).
 *
 * WHAT THIS TABLE USED TO SHOW. The Blade version read `risk_category`,
 * `distribution_type`, `mean`, `std_dev`, `frequency_per_year` and
 * `last_run_at` off the model. NOT ONE of those is a column on
 * `quantification_scenarios` — they are the CREATE FORM's field names — so
 * every scenario on every tenant listed as category "-", distribution "-",
 * mean ₦0, std dev ₦0, "-/year" and "Never", behind `?? 0` and `?? '-'` that
 * kept it all silent.
 *
 * These are the parameters a Monte Carlo run draws on to produce the VaR that
 * becomes a capital add-on, so ₦0 is the same class of claim as 0% CAR. The
 * mapping between the form's vocabulary and the table's columns now lives in
 * ScenarioService, which is where the write side has always done it.
 */
export default function Index({ scenarios, categories, filters }) {
    const createUrl = tryRoute('risk.quantification.create-scenario');
    const libraryUrl = tryRoute('risk.quantification.library');

    return (
        <AuthenticatedLayout title="Risk Scenarios">
            <Head title="Risk Scenarios" />

            <PageHeader
                title="Risk Scenarios"
                subtitle="Define loss distribution scenarios for Monte Carlo simulation"
                actions={
                    <>
                        {createUrl && (
                            <Link href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">add_circle</span> New Scenario
                            </Link>
                        )}
                        {libraryUrl && (
                            <Link href={libraryUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">library_books</span> Scenario Library
                            </Link>
                        )}
                    </>
                }
            />

            <FilterBar
                route={route('risk.quantification.scenarios')}
                currentFilters={filters}
                searchPlaceholder="Reference or name"
                filters={[
                    { name: 'search', type: 'search' },
                    {
                        name: 'risk_category',
                        type: 'select',
                        label: 'Risk category',
                        options: categories.map((c) => ({ value: c, label: c })),
                    },
                    {
                        name: 'status',
                        type: 'select',
                        label: 'Status',
                        options: [
                            { value: 'draft', label: 'Draft' },
                            { value: 'active', label: 'Active' },
                            { value: 'archived', label: 'Archived' },
                        ],
                    },
                ]}
            />

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                {scenarios.data.length === 0 ? (
                    <EmptyState
                        icon={<span className="material-symbols-outlined text-3xl text-gray-400">category</span>}
                        title="No scenarios defined"
                        description="A scenario is one loss event with a frequency and a severity. Create one, or import a calibrated template from the library."
                        actionLabel={createUrl ? 'Create your first scenario' : undefined}
                        actionHref={createUrl ?? undefined}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Scenario</th>
                                    <th>Risk category</th>
                                    <th>Severity distribution</th>
                                    <th className="text-right">Mean loss per event</th>
                                    <th className="text-right">Std deviation</th>
                                    <th className="text-right">Frequency</th>
                                    <th className="text-right">Expected annual loss</th>
                                    <th>Status</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {scenarios.data.map((scenario) => {
                                    const showUrl = tryRoute('risk.quantification.show-scenario', scenario.id);
                                    const editUrl = tryRoute('risk.quantification.edit-scenario', scenario.id);

                                    return (
                                        <tr key={scenario.id} className="hover:bg-blue-50/50">
                                            <td className="text-xs font-medium text-[#1A365D]">{scenario.scenario_reference}</td>
                                            <td className="font-medium text-[#1A365D]">
                                                {showUrl ? (
                                                    <Link href={showUrl} className="hover:underline">
                                                        {scenario.name}
                                                    </Link>
                                                ) : (
                                                    scenario.name
                                                )}
                                            </td>
                                            <td className="text-xs">{scenario.risk_category ?? '—'}</td>
                                            <td>
                                                <span className="badge bg-blue-100 text-blue-700">
                                                    {scenario.distribution_type ?? '—'}
                                                </span>
                                            </td>
                                            <td className="text-right text-xs">{naira(scenario.mean) ?? '—'}</td>
                                            <td className="text-right text-xs">{naira(scenario.std_dev) ?? '—'}</td>
                                            <td className="text-right text-xs">
                                                {scenario.frequency_per_year === null
                                                    ? '—'
                                                    : `${number(scenario.frequency_per_year, 2)}/year`}
                                            </td>
                                            <td className="text-right text-xs">{naira(scenario.expected_annual_loss) ?? '—'}</td>
                                            <td>
                                                <StatusBadge status={scenario.status ?? 'active'} />
                                            </td>
                                            <td>
                                                <div className="flex items-center gap-1">
                                                    {showUrl && (
                                                        <Link href={showUrl} className="p-1 hover:bg-gray-100 rounded" title="View">
                                                            <span className="material-symbols-outlined text-gray-400 text-lg">visibility</span>
                                                        </Link>
                                                    )}
                                                    {editUrl && (
                                                        <Link href={editUrl} className="p-1 hover:bg-gray-100 rounded" title="Edit">
                                                            <span className="material-symbols-outlined text-gray-400 text-lg">edit</span>
                                                        </Link>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination links={scenarios.links} />
            </div>
        </AuthenticatedLayout>
    );
}
