import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DonutChart from '@thirdline/ui/Components/DonutChart';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import { severityTone, titleCase } from './format';

const CATEGORY_COLORS = ['#1A365D', '#2D7D46', '#D4AF37', '#C53030', '#553C9A'];

/** Migration Phase 4.3: risk/loss-events/rca.blade.php. */
export default function Rca({ counts = {}, categories = [], events = {} }) {
    const slices = categories.map((band, index) => ({
        name: band.label,
        value: band.value,
        color: CATEGORY_COLORS[index % CATEGORY_COLORS.length],
    }));

    return (
        <AuthenticatedLayout title="Root Cause Analysis">
            <Head title="Root Cause Analysis" />

            <PageHeader
                title="Root Cause Analysis"
                subtitle="Why the losses happened, and what was learned"
                breadcrumbs={[{ label: 'Loss Events', href: route('risk.loss-events.index') }, { label: 'Root Cause' }]}
            />

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Analyses Recorded" value={counts.total ?? 0} icon="psychology" color="primary" />
                <KpiCard title="Completed" value={counts.completed ?? 0} icon="check_circle" color="success" />
                <KpiCard title="In Progress" value={counts.inProgress ?? 0} icon="pending" color="warning" />
                {/* Not "pending analyses" — events with NO analysis at all,
                    which is why these four do not sum. */}
                <KpiCard title="Events Without One" value={counts.pending ?? 0} icon="error" color="danger" subtitle="No analysis recorded" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Root Causes by Category</h3>
                    <DonutChart data={slices} />
                </div>

                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Loss Events and Their Analyses</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Event</th>
                                    <th>Severity</th>
                                    <th>Root Cause</th>
                                    <th>Analysis</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(events.data ?? []).length === 0 && (
                                    <tr><td colSpan={6} className="text-center py-8 text-gray-400">No loss events recorded.</td></tr>
                                )}
                                {(events.data ?? []).map((event) => (
                                    <tr key={event.id}>
                                        <td className="text-xs font-mono">
                                            <Link href={event.url} className="text-[#1A365D] hover:underline">{event.reference}</Link>
                                        </td>
                                        <td className="text-sm max-w-xs truncate" title={event.title}>{event.title}</td>
                                        <td><span className={`badge ${severityTone(event.severity)}`}>{titleCase(event.severity)}</span></td>
                                        <td className="text-xs">{event.rootCause ? titleCase(event.rootCause) : '—'}</td>
                                        <td>
                                            {event.rcaStatus
                                                ? <span className="badge bg-gray-100 text-gray-600">{titleCase(event.rcaStatus)}</span>
                                                : <span className="text-xs text-red-600">Not started</span>}
                                        </td>
                                        <td className="text-xs text-gray-500">{event.dateOfLoss ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={events.links} meta={events.meta} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
