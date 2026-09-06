import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import SecondaryButton from '@/Components/SecondaryButton';
import Pagination from '@/Components/Pagination';

const dateTime = (value) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium' }) : '—';

const STATUS_TONES = {
    delivered: 'bg-green-100 text-green-800',
    pending: 'bg-blue-100 text-blue-800',
    failed: 'bg-amber-100 text-amber-800',
    abandoned: 'bg-red-100 text-red-800',
};

/**
 * The delivery log (migration Phase 6.7).
 *
 * THIS IS THE POINT OF THE WEBHOOK SCREEN. "Did you send it?" is the first
 * question in every integration incident, and it needs an answer with a status
 * code and a response body in it, not "the job ran".
 *
 * Response bodies are truncated by the server: a megabyte of body in the page
 * props answers that question no better than the first kilobyte.
 */
export default function Deliveries({ subscription, deliveries, filters, statuses }) {
    const { flash } = usePage().props;
    const [open, setOpen] = useState(null);

    const filter = (next) =>
        router.get(
            route('admin.webhooks.deliveries', subscription.id),
            { ...filters, ...next },
            {
                preserveState: true,
                replace: true,
            },
        );

    const replay = (delivery) => {
        if (!window.confirm('Send this delivery again?')) return;

        router.post(route('admin.webhooks.replay', delivery.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title={`${subscription.name} deliveries`}>
            <Head title={`${subscription.name} deliveries`} />

            <PageHeader
                title={`${subscription.name} — delivery log`}
                subtitle={subscription.url}
                breadcrumbs={[{ label: 'Webhooks', href: route('admin.webhooks.index') }, { label: 'Delivery log' }]}
                actions={
                    <Link href={route('admin.webhooks.index')}>
                        <SecondaryButton type="button">Back</SecondaryButton>
                    </Link>
                }
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}

            <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex flex-col sm:flex-row gap-3">
                <select
                    className="sm:w-56 border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                    value={filters.status ?? ''}
                    onChange={(e) => filter({ status: e.target.value })}
                >
                    <option value="">Every status</option>
                    {statuses.map((status) => (
                        <option key={status} value={status}>
                            {status}
                        </option>
                    ))}
                </select>
                <input
                    type="text"
                    placeholder="Filter by event"
                    className="flex-1 border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                    defaultValue={filters.event ?? ''}
                    onKeyDown={(e) => e.key === 'Enter' && filter({ event: e.target.value })}
                />
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <table className="data-table w-full">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Event</th>
                            <th>Status</th>
                            <th className="text-right">Attempt</th>
                            <th className="text-right">Response</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {deliveries.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-sm text-gray-500 text-center py-8">
                                    Nothing has been sent yet.
                                </td>
                            </tr>
                        )}
                        {deliveries.data.map((delivery) => (
                            <tr key={delivery.id}>
                                <td className="text-sm text-gray-600">{dateTime(delivery.created_at)}</td>
                                <td className="text-xs font-mono text-gray-700">{delivery.event}</td>
                                <td>
                                    <span
                                        className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
                                            STATUS_TONES[delivery.status] ?? 'bg-gray-100 text-gray-700'
                                        }`}
                                    >
                                        {delivery.status}
                                    </span>
                                </td>
                                <td className="text-sm text-gray-600 text-right">{delivery.attempt}</td>
                                <td className="text-sm text-gray-600 text-right">{delivery.response_status ?? '—'}</td>
                                <td className="text-right whitespace-nowrap">
                                    <button
                                        type="button"
                                        onClick={() => setOpen(open === delivery.id ? null : delivery.id)}
                                        className="text-xs text-[#1A365D] font-medium hover:opacity-80 mr-3"
                                    >
                                        {open === delivery.id ? 'Hide' : 'Detail'}
                                    </button>
                                    {delivery.can_replay && (
                                        <button
                                            type="button"
                                            onClick={() => replay(delivery)}
                                            className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                                        >
                                            Replay
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {deliveries.data
                            .filter((delivery) => open === delivery.id)
                            .map((delivery) => (
                                <tr key={`detail-${delivery.id}`}>
                                    <td colSpan={6} className="bg-gray-50">
                                        <div className="p-4 space-y-2">
                                            {delivery.error && (
                                                <p className="text-xs text-red-700">
                                                    <span className="font-semibold">Error:</span> {delivery.error}
                                                </p>
                                            )}
                                            {delivery.replayer && (
                                                <p className="text-xs text-gray-500">Replayed by {delivery.replayer}</p>
                                            )}
                                            <p className="text-xs text-gray-500">
                                                Delivered {dateTime(delivery.delivered_at)}
                                            </p>
                                            <pre className="text-xs bg-white border border-gray-200 rounded-lg p-3 overflow-x-auto whitespace-pre-wrap break-all">
                                                {delivery.response_body || '(no body)'}
                                            </pre>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 bg-white rounded-xl border border-gray-200">
                <Pagination links={deliveries.links} meta={deliveries.meta} />
            </div>
        </AuthenticatedLayout>
    );
}
