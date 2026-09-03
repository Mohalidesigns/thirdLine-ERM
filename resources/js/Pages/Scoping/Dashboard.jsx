import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import KpiCard from '@/Components/KpiCard';
import DonutChart from '@/Components/DonutChart';
import EntityTree from '@/Components/EntityTree';
import StatusBadge from '@/Components/StatusBadge';

const PALETTE = ['#1A365D', '#2D7D46', '#DD6B20', '#3182CE', '#D4AF37', '#ED8936', '#48BB78', '#9F7AEA'];

function scoreClass(score) {
    if (score >= 4) return 'text-red-600';
    if (score >= 3) return 'text-orange-600';
    if (score >= 2) return 'text-yellow-600';
    return 'text-green-600';
}

function CountPill({ value, tone }) {
    if (!value) return <span className="text-gray-400">0</span>;

    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}>{value}</span>;
}

/**
 * The entity dashboard (migration Phase 3.1: risk/scoping/dashboard.blade.php).
 * Every figure arrives computed by App\Services\Scoping\EntityService; the
 * type distribution is drawn by the house SVG donut, not Chart.js.
 */
export default function Dashboard({ kpis, tree = [], heatmap = [], typeDistribution = [], recentActivity = [] }) {
    const { auth } = usePage().props;
    const canCreate = (auth?.permissions ?? []).includes('entity.create');

    const distribution = typeDistribution.map((slice, idx) => ({ name: slice.name, value: slice.value, color: PALETTE[idx % PALETTE.length] }));

    return (
        <AuthenticatedLayout title="Entity Dashboard">
            <Head title="Entity Dashboard" />

            <PageHeader
                title="Entity Dashboard"
                subtitle="Entity & scope management across the organizational hierarchy"
                breadcrumbs={[{ label: 'Scoping' }, { label: 'Entity Dashboard' }]}
                actions={
                    <>
                        <Link href={route('risk.scoping.index')} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">list</span> Entity Register
                        </Link>
                        {canCreate && (
                            <Link href={route('risk.scoping.create')} className="btn-primary inline-flex items-center gap-2 text-sm">
                                <span className="material-symbols-outlined text-lg">add_circle</span> New Entity
                            </Link>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                <KpiCard title="Total Entities" value={kpis.totalEntities} icon="account_tree" color="primary" subtitle="Across all levels" />
                <KpiCard title="Active Risk Owners" value={kpis.activeOwners} icon="people" color="success" subtitle="Assigned to entities" />
                <KpiCard
                    title="Exceeding Appetite"
                    value={kpis.exceedingAppetite}
                    icon="trending_up"
                    color="danger"
                    subtitle="Entities above tolerance"
                    unavailable={kpis.exceedingAppetite === null || kpis.exceedingAppetite === undefined}
                    unavailableLabel="Not computed"
                />
                <KpiCard title="Pending Assessments" value={kpis.pendingAssessments} icon="assignment" color="warning" subtitle="Risks without assessment" />
            </div>

            <div className="card mb-6">
                <div className="card-header flex items-center justify-between">
                    <h2 className="text-base font-bold text-[#1A365D]">Organizational Hierarchy Tree</h2>
                </div>
                <div className="card-body">
                    {tree.length > 0 ? (
                        <EntityTree nodes={tree} expandDepth={9} />
                    ) : (
                        <div className="py-12 text-center">
                            <span className="material-symbols-outlined mb-2 block text-4xl text-gray-300">account_tree</span>
                            <p className="text-sm text-gray-500">No entities found. Create your first entity to build the hierarchy.</p>
                            {canCreate && (
                                <Link href={route('risk.scoping.create')} className="mt-2 inline-block text-sm font-medium text-[#1A365D] hover:underline">Create Entity</Link>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <div className="card mb-6">
                <div className="card-header">
                    <h2 className="text-base font-bold text-[#1A365D]">Entity Risk Heatmap</h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Entity Name</th>
                                <th className="px-4 py-2">Type</th>
                                <th className="px-4 py-2">Total Risks</th>
                                <th className="px-4 py-2">Critical</th>
                                <th className="px-4 py-2">High</th>
                                <th className="px-4 py-2">Risk Score</th>
                                <th className="px-4 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {heatmap.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-8 text-center text-sm text-gray-500">No entity risk data available yet.</td>
                                </tr>
                            )}
                            {heatmap.map((row) => (
                                <tr key={row.id} className="hover:bg-blue-50/50">
                                    <td className="px-4 py-2 font-medium text-[#1A365D]">
                                        <Link href={route('risk.scoping.show', row.id)} className="hover:underline">{row.name}</Link>
                                    </td>
                                    <td className="px-4 py-2">
                                        <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">{row.type ?? '—'}</span>
                                    </td>
                                    <td className="px-4 py-2 font-semibold">{row.risk_total}</td>
                                    <td className="px-4 py-2"><CountPill value={row.critical_count} tone="bg-red-100 text-red-700" /></td>
                                    <td className="px-4 py-2"><CountPill value={row.high_count} tone="bg-orange-100 text-orange-700" /></td>
                                    <td className="px-4 py-2"><span className={`font-bold ${scoreClass(row.risk_score)}`}>{row.risk_score}/5</span></td>
                                    <td className="px-4 py-2"><StatusBadge status={row.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="card">
                    <div className="card-header"><h3 className="text-base font-bold text-[#1A365D]">Entity Type Distribution</h3></div>
                    <div className="card-body"><DonutChart data={distribution} /></div>
                </div>

                <div className="card">
                    <div className="card-header"><h3 className="text-base font-bold text-[#1A365D]">Recent Entity Activity</h3></div>
                    <div className="card-body space-y-3">
                        {recentActivity.length === 0 && <p className="py-4 text-center text-sm text-gray-500">No recent activity.</p>}
                        {recentActivity.map((item, idx) => (
                            <div key={item.id} className={`flex items-start gap-3 pb-3 ${idx < recentActivity.length - 1 ? 'border-b border-gray-200' : ''}`}>
                                <span className="material-symbols-outlined mt-0.5 text-[18px] text-[#1A365D]">edit</span>
                                <div className="flex-1">
                                    <p className="text-sm font-semibold text-gray-800">
                                        <Link href={route('risk.scoping.show', item.id)} className="hover:underline">{item.name}</Link>
                                    </p>
                                    <p className="text-xs text-gray-500">{item.type} — Updated {item.updated_human}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
