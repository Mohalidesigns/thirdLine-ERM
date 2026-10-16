import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import Icon from '@thirdline/ui/Components/Icon';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import { formatDateTime } from '@thirdline/ui/utils';

// type -> [icon, tile classes, pill label]
const TYPES = {
    approval_request: ['rate_review', 'bg-blue-50 text-blue-600', 'Approval request'],
    approval_approved: ['check_circle', 'bg-green-50 text-green-600', 'Approved'],
    approval_rejected: ['cancel', 'bg-red-50 text-red-600', 'Rejected'],
};
const DEFAULT_TYPE = ['notifications', 'bg-gray-50 text-gray-600', 'Notification'];

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const seconds = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return days < 7 ? `${days}d ago` : '';
}

export default function Index({ notifications, unreadCount, filter }) {
    const rows = notifications?.data ?? [];
    const [marking, setMarking] = useState(false);

    const markAllRead = () =>
        router.post(route('notifications.read-all'), {}, { preserveScroll: true, onStart: () => setMarking(true), onFinish: () => setMarking(false) });

    const plural = unreadCount === 1 ? '' : 's';

    return (
        <AuthenticatedLayout title="Notifications">
            <Head title="Notifications" />

            <PageHeader
                title="Notifications"
                subtitle="Approvals, decisions, and system alerts directed to you."
                actions={
                    unreadCount > 0 && (
                        <button type="button" onClick={markAllRead} disabled={marking} aria-busy={marking} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <Icon name="done_all" className="w-4 h-4" strokeWidth={2} /> Mark all read
                        </button>
                    )
                }
            />

            {unreadCount > 0 && (
                <div className="mb-6 flex items-center gap-3 px-4 py-3 bg-blue-50 border border-blue-100 rounded-lg" role="status">
                    <div className="w-8 h-8 rounded-full bg-[var(--color-primary)] flex items-center justify-center" aria-hidden="true">
                        <span className="text-white text-sm font-bold">{unreadCount}</span>
                    </div>
                    <p className="text-sm text-[var(--color-primary)] font-medium">
                        You have {unreadCount} unread notification{plural}
                    </p>
                </div>
            )}

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group">
                        <span className="filter-label" id="notif-show">Show</span>
                        <div className="flex gap-2" role="group" aria-labelledby="notif-show">
                            {[
                                ['all', 'All', {}],
                                ['unread', `Unread${unreadCount > 0 ? ` (${unreadCount})` : ''}`, { filter: 'unread' }],
                            ].map(([key, label, params]) => (
                                <Link
                                    key={key}
                                    href={route('notifications.index', params)}
                                    aria-current={filter === key ? 'true' : undefined}
                                    className={`px-4 py-2.5 text-sm rounded-lg border transition-colors motion-reduce:transition-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 ${
                                        filter === key
                                            ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                                            : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                                    }`}
                                >
                                    {label}
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {rows.length === 0 ? (
                <div className="card text-center py-12 text-gray-500">
                    <Icon name="notifications_off" className="w-12 h-12 mx-auto mb-3 opacity-50" strokeWidth={1} />
                    <p className="text-sm font-medium">No notifications</p>
                    <p className="text-sm mt-1">
                        {filter === 'unread' ? (
                            <>
                                No unread notifications &mdash; you&rsquo;re all caught up.{' '}
                                <Link href={route('notifications.index')} className="font-medium text-[var(--color-primary)] underline hover:no-underline">
                                    Show all
                                </Link>
                            </>
                        ) : (
                            'You\u2019re all caught up'
                        )}
                    </p>
                </div>
            ) : (
                <div className="space-y-3">
                    {rows.map((n) => {
                        const [icon, tile, pill] = TYPES[n.type] ?? DEFAULT_TYPE;
                        const edge = n.is_unread ? `border-l-[3px] ${n.priority === 'high' ? 'border-l-red-600' : 'border-l-[var(--color-primary)]'}` : '';
                        const ago = timeAgo(n.created_at);
                        return (
                            // Plain anchor: notifications.read marks the row read and
                            // redirects to its action URL, which is usually a Blade page.
                            <a
                                key={n.id}
                                href={route('notifications.read', n.id)}
                                className={`card block transition-all motion-reduce:transition-none hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 ${edge}`}
                            >
                                <div className="px-4 py-3 flex items-start gap-3">
                                    <div className={`w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5 ${tile}`} aria-hidden="true">
                                        <Icon name={icon} className="w-[18px] h-[18px]" />
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-start justify-between gap-3">
                                            <p className={`text-sm ${n.is_unread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700'}`}>
                                                {n.subject}
                                                {n.is_unread && <span className="sr-only"> (Unread)</span>}
                                            </p>
                                            <div className="flex items-center gap-2 flex-shrink-0">
                                                {n.priority === 'high' && <span className="text-xs font-semibold bg-red-100 text-red-700 rounded-full px-2 py-0.5">Priority</span>}
                                                {n.is_unread && <span className="w-2 h-2 rounded-full bg-[var(--color-primary)]" aria-hidden="true" />}
                                            </div>
                                        </div>
                                        <p className="text-sm text-gray-500 mt-0.5 line-clamp-2">{n.body}</p>
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-xs text-gray-500">
                                            <span className={`inline-flex text-xs font-medium px-2 py-0.5 rounded-full ${tile}`}>{pill}</span>
                                            <time dateTime={n.created_at} title={formatDateTime(n.created_at)} className="whitespace-nowrap">
                                                {ago || formatDateTime(n.created_at)}
                                            </time>
                                            {n.metadata?.entity_type && (
                                                <span>
                                                    {n.metadata.entity_type} #{n.metadata.entity_id ?? '?'}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </a>
                        );
                    })}
                </div>
            )}

            {notifications?.links?.length > 3 && (
                <div className="mt-6 card">
                    <Pagination links={notifications.links} meta={{ from: notifications.from, to: notifications.to, total: notifications.total }} />
                </div>
            )}
        </AuthenticatedLayout>
    );
}
