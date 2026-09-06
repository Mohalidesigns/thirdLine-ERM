import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import { titleCase } from './format';

/**
 * The campaign dashboard (Phase 4.5: risk/campaigns/dashboard.blade.php).
 *
 * Figures come from App\Services\Campaigns\CampaignDashboardService and are
 * pinned by tests/Feature/Characterisation/CampaignDashboardFiguresTest, which
 * was written against the running Blade screen before the extraction.
 */

/**
 * Two segments, not one. Green is approved, blue is handed in and waiting on a
 * reviewer; the number stays the approved share, which is what completion_pct
 * means everywhere else in the product. A campaign whose unit has finished no
 * longer reads 0% and looks abandoned.
 */
export function ProgressBar({ progress, compact = false }) {
    const title = `${progress.completed} approved · ${progress.awaiting_review} awaiting review · ${progress.total} total`;

    return (
        <div className={compact ? 'flex items-center gap-2' : ''} title={compact ? title : undefined}>
            <div
                className={`${compact ? 'w-16 h-1.5' : 'w-full h-3'} bg-gray-100 rounded-full overflow-hidden flex`}
                role="progressbar"
                aria-valuenow={Math.round(progress.completed_pct)}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={title}
            >
                <div className="h-full bg-green-500 transition-all" style={{ width: `${progress.completed_pct}%` }} />
                <div className="h-full bg-blue-400 transition-all" style={{ width: `${progress.awaiting_review_pct}%` }} />
            </div>
            {compact && (
                <>
                    <span className="text-xs text-gray-500">{Math.round(progress.completed_pct)}%</span>
                    {progress.awaiting_review > 0 && (
                        <span className="text-[11px] text-blue-600">+{progress.awaiting_review} to review</span>
                    )}
                </>
            )}
        </div>
    );
}

export default function Dashboard({ stats = {}, campaigns = [], canCreate = false }) {
    return (
        <AuthenticatedLayout title="Assessment Campaigns">
            <Head title="Assessment Campaigns" />

            <PageHeader
                title="Assessment Campaign Dashboard"
                subtitle="Manage RCSA and targeted risk assessment campaigns"
                breadcrumbs={[{ label: 'Campaigns', href: route('risk.campaigns.index') }, { label: 'Dashboard' }]}
                actions={
                    canCreate && (
                        <Link href={route('risk.campaigns.create')} className="btn-primary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Campaign
                        </Link>
                    )
                }
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Active Campaigns" value={stats.activeCampaigns ?? 0} icon="campaign" color="info" subtitle="Open for response" />
                <KpiCard title="Total Campaigns" value={stats.totalCampaigns ?? 0} icon="folder" color="primary" />
                <KpiCard title="Pending Review" value={stats.pendingReview ?? 0} icon="rate_review" color="warning" subtitle="Submitted, not yet looked at" />
                <KpiCard
                    title="Avg Completion"
                    value={`${(stats.avgCompletion ?? 0).toFixed(1)}%`}
                    icon="donut_large"
                    color="success"
                    unavailable={(stats.activeCampaigns ?? 0) === 0}
                    unavailableLabel="Nothing running"
                />
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Recent Campaigns</h3>
                    <Link href={route('risk.campaigns.index')} className="text-xs text-[#1A365D] font-medium hover:underline">
                        All campaigns
                    </Link>
                </div>
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Assignments</th>
                                <th>Completion</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            {campaigns.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-center py-10">
                                        <span className="material-symbols-outlined text-3xl text-gray-300 mb-2 block">campaign</span>
                                        <p className="text-sm text-gray-500">No campaigns yet.</p>
                                    </td>
                                </tr>
                            )}
                            {campaigns.map((campaign) => (
                                <tr key={campaign.id}>
                                    <td className="font-mono text-xs">
                                        <Link href={campaign.url} className="text-[#1A365D] hover:underline">{campaign.code}</Link>
                                    </td>
                                    <td className="font-medium text-sm max-w-xs truncate" title={campaign.title}>{campaign.title}</td>
                                    <td><span className="badge bg-blue-50 text-blue-700">{titleCase(campaign.type)}</span></td>
                                    <td><StatusBadge status={campaign.status} /></td>
                                    <td className="text-sm">{campaign.assignmentsCount}</td>
                                    <td><ProgressBar progress={campaign.progress} compact /></td>
                                    <td className="text-xs text-gray-500">{campaign.createdAt ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
