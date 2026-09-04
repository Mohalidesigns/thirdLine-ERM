import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import { badgeClass } from '@/Pages/MyTasks/Index';

/** Workflow dashboard (migration Phase 3.7: risk/workflows/dashboard.blade.php). */
export default function Dashboard({ stats, recentInstances, workload, urls }) {
    return (
        <AuthenticatedLayout title="Workflows">
            <Head title="Workflow dashboard" />
            <PageHeader
                title="Workflow dashboard"
                actions={
                    <>
                        <Link href={urls.myTasks} className="btn-secondary text-sm inline-flex items-center gap-2"><span className="material-symbols-outlined text-lg">task_alt</span> My tasks</Link>
                        {/* The designer is a Blade/Livewire screen until Phase 6. */}
                        <a href={urls.newDefinition} className="btn-primary text-sm inline-flex items-center gap-2"><span className="material-symbols-outlined text-lg">add_circle</span> New workflow</a>
                    </>
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                <KpiCard title="Running" value={stats.running} icon="sync" color="info" />
                <KpiCard title="Completed today" value={stats.completed_today} icon="check_circle" color="success" />
                <KpiCard title="Waiting on me" value={stats.waiting_on_me} icon="pending_actions" color="warning" />
                <KpiCard title="Overdue tasks" value={stats.overdue} icon="schedule" color="danger" />
                <KpiCard title="Escalated" value={stats.escalated} icon="trending_up" color="warning" />
                <KpiCard title="Published" value={stats.published} icon="schema" color="primary" />
            </div>

            <div className="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
                <div className="px-5 py-4 border-b border-gray-100"><h3 className="text-sm font-semibold text-gray-900">Recent workflow activity</h3></div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead><tr><th>Workflow</th><th>Record</th><th>Waiting on</th><th>Status</th><th>Initiated by</th><th>Started</th></tr></thead>
                        <tbody>
                            {recentInstances.length === 0 && <tr><td colSpan={6} className="text-center py-8 text-gray-400">No workflows yet</td></tr>}
                            {recentInstances.map((i) => (
                                <tr key={i.id} className="cursor-pointer hover:bg-gray-50">
                                    <td className="font-medium"><Link href={i.url} className="hover:underline">{i.name}</Link></td>
                                    <td className="text-xs">{i.entity_type_label} #{i.entity_id}</td>
                                    <td className="text-xs">
                                        {i.waiting_on.length === 0 ? <span className="text-gray-400">—</span> : i.waiting_on.map((w, idx) => (
                                            <span key={idx} className="block">{w.name} — {w.who}{w.hours_overdue !== null && <span className="text-red-600 font-medium"> ({w.hours_overdue}h over)</span>}</span>
                                        ))}
                                    </td>
                                    <td><span className={`badge ${badgeClass(i.status.color)}`}>{i.status.label}</span></td>
                                    <td className="text-xs">{i.initiator}</td>
                                    <td className="text-xs text-gray-500">{i.started_human}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {workload.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm">
                    <div className="px-5 py-4 border-b border-gray-100"><h3 className="text-sm font-semibold text-gray-900">Open decisions by assignee</h3></div>
                    <table className="data-table">
                        <thead><tr><th>Assignee</th><th>Open</th><th>Overdue</th></tr></thead>
                        <tbody>
                            {workload.map((row, idx) => (
                                <tr key={idx}><td>{row.assignee}</td><td>{row.open}</td><td className={row.overdue > 0 ? 'text-red-600 font-medium' : 'text-gray-500'}>{row.overdue}</td></tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
