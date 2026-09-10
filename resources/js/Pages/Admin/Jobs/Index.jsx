import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';

const dateTime = (value) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const STATUS_TONES = {
    queued: 'bg-blue-100 text-blue-800',
    running: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-700',
};

const STATUSES = ['queued', 'running', 'completed', 'failed', 'cancelled'];

/**
 * Background jobs (migration Phase 6.7).
 *
 * NOT HORIZON. Horizon is for whoever runs the platform and shows queues,
 * throughput and payloads; this is for the person who pressed a button and
 * wants to know whether their board pack is ready. Different audience,
 * different permission, different vocabulary.
 *
 * Without `admin.queues` you see the jobs you started. A job label names the
 * record it is about — "Board pack, Q2 2026" — so a full list would be a list
 * of what everybody in the institution is working on.
 */
export default function Index({ runs, filters, canSeeAll, active }) {
    const { flash } = usePage().props;

    const filter = (status) =>
        router.get(route('admin.jobs.index'), { status }, { preserveState: true, replace: true });

    const cancel = (run) => {
        if (!window.confirm('Ask this job to stop? It stops at its next checkpoint.')) return;

        router.post(route('admin.jobs.cancel', run.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="Background Jobs">
            <Head title="Background Jobs" />

            <PageHeader
                title="Background jobs"
                subtitle={
                    canSeeAll
                        ? `${active} running or queued across the institution`
                        : `${active} of yours running or queued`
                }
                breadcrumbs={[{ label: 'Administration' }, { label: 'Jobs' }]}
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4">
                <select
                    className="sm:w-56 border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                    value={filters.status ?? ''}
                    onChange={(e) => filter(e.target.value)}
                >
                    <option value="">Every status</option>
                    {STATUSES.map((status) => (
                        <option key={status} value={status}>
                            {status}
                        </option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <table className="data-table w-full">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Status</th>
                            <th className="w-48">Progress</th>
                            <th>Started</th>
                            <th>Finished</th>
                            {canSeeAll && <th>Started by</th>}
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {runs.data.length === 0 && (
                            <tr>
                                <td colSpan={canSeeAll ? 7 : 6} className="text-sm text-gray-500 text-center py-8">
                                    Nothing here.
                                </td>
                            </tr>
                        )}
                        {runs.data.map((run) => (
                            <tr key={run.id}>
                                <td>
                                    <p className="text-sm font-medium text-gray-900">{run.label}</p>
                                    {run.error && <p className="text-xs text-red-600">{run.error}</p>}
                                    {run.cancel_requested_at && !run.is_finished && (
                                        <p className="text-xs text-amber-700">
                                            Stop requested {dateTime(run.cancel_requested_at)}
                                        </p>
                                    )}
                                </td>
                                <td>
                                    <span
                                        className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
                                            STATUS_TONES[run.status] ?? 'bg-gray-100 text-gray-700'
                                        }`}
                                    >
                                        {run.status}
                                    </span>
                                </td>
                                <td>
                                    <div className="w-40 bg-gray-100 rounded-full h-2 overflow-hidden">
                                        <div
                                            className="h-2 bg-[#1A365D] rounded-full"
                                            style={{ width: `${Math.min(100, Math.max(0, run.progress ?? 0))}%` }}
                                        />
                                    </div>
                                    <span className="text-xs text-gray-500">{run.progress ?? 0}%</span>
                                </td>
                                <td className="text-xs text-gray-600">{dateTime(run.started_at)}</td>
                                <td className="text-xs text-gray-600">{dateTime(run.finished_at)}</td>
                                {canSeeAll && <td className="text-xs text-gray-600">{run.creator ?? '—'}</td>}
                                <td className="text-right">
                                    {run.can_cancel && (
                                        <button
                                            type="button"
                                            onClick={() => cancel(run)}
                                            className="text-xs text-red-600 font-medium hover:opacity-80"
                                        >
                                            Stop
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <Pagination links={runs.links} meta={runs.meta} />
            </div>
        </AuthenticatedLayout>
    );
}
