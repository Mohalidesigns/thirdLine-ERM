import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import { naira, severityTone, titleCase } from './format';

/**
 * Loss events awaiting a decision (migration Phase 4.3:
 * risk/loss-events/approvals.blade.php).
 *
 * The decision itself is taken on the event's own page, where the reviewer can
 * see what they are approving — this screen is the queue, and its severity
 * filter is now reachable, which it was not: the Blade page rendered no filter
 * control at all while the controller supported one.
 */
export default function Approvals({ pending = {}, filters = {}, severities = [] }) {
    const filter = (severity) =>
        router.get(route('risk.loss-events.approvals'), severity ? { severity } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    return (
        <AuthenticatedLayout title="Loss Event Approvals">
            <Head title="Loss Event Approvals" />

            <PageHeader
                title="Loss Events Pending Approval"
                subtitle={`${pending.meta?.total ?? 0} awaiting a decision`}
                breadcrumbs={[{ label: 'Loss Events', href: route('risk.loss-events.index') }, { label: 'Approvals' }]}
                actions={
                    <select
                        value={filters.severity ?? ''}
                        onChange={(e) => filter(e.target.value)}
                        className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700"
                    >
                        <option value="">All severities</option>
                        {severities.map((severity) => (
                            <option key={severity} value={severity}>{titleCase(severity)}</option>
                        ))}
                    </select>
                }
            />

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Event</th>
                                <th>Business Unit</th>
                                <th>Reported By</th>
                                <th>Gross Loss</th>
                                <th>Severity</th>
                                <th>Date</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {(pending.data ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-center py-12">
                                        <span className="material-symbols-outlined text-4xl text-green-300 mb-2 block">task_alt</span>
                                        <p className="text-sm text-gray-500">Nothing is waiting for a decision.</p>
                                    </td>
                                </tr>
                            )}
                            {(pending.data ?? []).map((event) => (
                                <tr key={event.id} className="hover:bg-blue-50/50">
                                    <td className="text-xs font-mono">
                                        <Link href={event.url} className="text-[#1A365D] hover:underline">{event.reference}</Link>
                                    </td>
                                    <td className="text-sm font-medium max-w-xs truncate" title={event.title}>{event.title}</td>
                                    <td className="text-xs">{event.businessUnit ?? '—'}</td>
                                    <td className="text-xs">{event.reporter ?? '—'}</td>
                                    <td className="text-xs tabular-nums">{naira(event.grossLoss)}</td>
                                    <td><span className={`badge ${severityTone(event.severity)}`}>{titleCase(event.severity)}</span></td>
                                    <td className="text-xs text-gray-500">{event.dateOfLoss ?? '—'}</td>
                                    <td className="text-right">
                                        <Link href={`${event.url}?tab=approvals`} className="text-xs font-medium text-[#1A365D] hover:underline">
                                            {event.canDecide ? 'Review' : 'Open'}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination links={pending.links} meta={pending.meta} />
            </div>
        </AuthenticatedLayout>
    );
}
