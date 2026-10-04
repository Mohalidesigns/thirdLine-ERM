import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import tryRoute from '@thirdline/ui/lib/tryRoute';

// Same colour tokens StatusBadge uses for these lifecycle states — kept local
// because the label shown here is BiaAssessmentStatus::label(), not the raw
// enum value StatusBadge would format ("Returned for rework", not "Returned").
const STATUS_BADGE_CLASS = {
    draft: 'badge-status-draft',
    in_progress: 'badge-status-active',
    submitted: 'badge-status-pending',
    approved: 'badge-status-active',
    returned: 'badge-status-overdue',
};

/**
 * Every business impact assessment, in the order somebody works them.
 *
 * SORTED BY WHAT NEEDS DOING, NOT BY CODE. Returned first — those have already
 * failed review once and are a different conversation from the thirty-nine that
 * have simply not been started.
 */
export default function Index({ assessments, filters = {}, statuses = [], can = {} }) {
    const rows = assessments?.data ?? [];

    const filter = (key, value) => router.get(
        tryRoute('bcms.bia.index'),
        { ...filters, [key]: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    return (
        <AppLayout title="Business impact analysis">
            <Head title="Business impact analysis" />

            <PageHeader
                title="Business impact analysis"
                subtitle="What each process can survive, how quickly it must come back, and what it cannot run without."
                actions={can.manage_campaigns && (
                    <Link href={tryRoute('bcms.bia-campaigns.index')} className="btn-secondary text-sm">Campaigns</Link>
                )}
            />

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group flex-1 min-w-[220px]">
                        <label className="filter-label">Search</label>
                        <input aria-label="Search" className="filter-input" placeholder="Search process name or code"
                            defaultValue={filters.search ?? ''}
                            onKeyDown={(e) => { if (e.key === 'Enter') filter('search', e.target.value); }} />
                    </div>
                    <div className="filter-group min-w-[160px]">
                        <label className="filter-label">Status</label>
                        <select aria-label="Status" className="filter-select" value={filters.status ?? ''}
                            onChange={(e) => filter('status', e.target.value)}>
                            <option value="">Any status</option>
                            {statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                        </select>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Process</th>
                                <th>Unit</th>
                                <th>Tier</th>
                                <th>MTPD</th>
                                <th>RTO</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={6} className="text-center py-12">
                                    <div className="text-gray-400">
                                        <p className="text-sm font-medium">No assessments match</p>
                                        <p className="text-xs mt-1">A campaign creates one per process in scope.</p>
                                    </div>
                                </td></tr>
                            )}
                            {rows.map((a) => (
                                <tr key={a.id}>
                                    <td>
                                        <Link href={tryRoute('bcms.bia.show', a.uuid)} className="cell-title">{a.process}</Link>
                                        <p className="cell-subtitle font-mono">{a.code}</p>
                                        {a.is_critical_service && (
                                            <span className="mt-1 inline-block rounded bg-purple-50 px-1.5 py-0.5 text-[11px] text-purple-800">critical service</span>
                                        )}
                                        {a.ai_generated && (
                                            <span className="ml-1 inline-block rounded bg-sky-50 px-1.5 py-0.5 text-[11px] text-sky-800">AI draft</span>
                                        )}
                                    </td>
                                    <td className="cell-muted">{a.unit ?? '—'}</td>
                                    <td className="cell-muted">{a.tier ? `Tier ${a.tier}` : <span className="text-gray-400">not tiered</span>}</td>
                                    <td className="font-mono text-gray-700">{a.mtpd_hours === null ? <span className="text-gray-400">—</span> : `${a.mtpd_hours} h`}</td>
                                    <td className="font-mono text-gray-700">{a.rto_hours === null ? <span className="text-gray-400">—</span> : `${a.rto_hours} h`}</td>
                                    <td>
                                        <span className={`badge ${STATUS_BADGE_CLASS[a.status] ?? 'badge-status-draft'}`}>
                                            {a.status_label}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {assessments?.links && assessments.links.length > 3 && (
                    <div className="px-4 py-3 border-t border-gray-100">
                        <Pagination links={assessments.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
