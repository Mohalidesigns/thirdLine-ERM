import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import Pagination from '@thirdline/ui/Components/Pagination';
import { formatDateTime } from '@thirdline/ui/utils';

const ICONS = {
    approval_request: ['rate_review', 'bg-blue-100 text-blue-600'],
    approval_approved: ['check_circle', 'bg-green-100 text-green-600'],
    approval_rejected: ['cancel', 'bg-red-100 text-red-600'],
};

const PRIORITY_BORDER = { high: 'border-l-red-500', low: 'border-l-gray-200' };

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

    const markAllRead = () => router.post(route('notifications.read-all'), {}, { preserveScroll: true });

    return (
        <AuthenticatedLayout title="Notifications">
            <Head title="Notifications" />

            <div className="max-w-4xl mx-auto">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-[var(--color-primary)]">Notifications</h1>
                        <p className="text-sm text-gray-500 mt-1">Approvals, decisions, and system alerts directed to you.</p>
                    </div>
                    {unreadCount > 0 && (
                        <button type="button" onClick={markAllRead} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-base">done_all</span> Mark all read
                        </button>
                    )}
                </div>

                <div className="flex items-center gap-1 border-b border-gray-200 mb-4">
                    {[
                        ['all', 'All', {}],
                        ['unread', `Unread${unreadCount > 0 ? ` (${unreadCount})` : ''}`, { filter: 'unread' }],
                    ].map(([key, label, params]) => (
                        <Link
                            key={key}
                            href={route('notifications.index', params)}
                            className={`px-4 py-2 text-sm font-medium border-b-2 ${
                                filter === key ? 'border-[var(--color-primary)] text-[var(--color-primary)]' : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            {label}
                        </Link>
                    ))}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100">
                    {rows.length === 0 && (
                        <div className="text-center py-16 text-gray-400">
                            <span className="material-symbols-outlined text-4xl block mb-2">notifications_off</span>
                            <p className="text-sm">No notifications yet</p>
                        </div>
                    )}
                    {rows.map((n) => {
                        const [icon, cls] = ICONS[n.type] ?? ['notifications', 'bg-gray-100 text-gray-600'];
                        const border = n.is_unread ? `${PRIORITY_BORDER[n.priority] ?? 'border-l-blue-500'} bg-blue-50/30` : 'border-l-transparent';
                        return (
                            // Plain anchor: notifications.read marks the row read and
                            // redirects to its action URL, which is usually a Blade page.
                            <a key={n.id} href={route('notifications.read', n.id)} className={`flex gap-4 p-4 hover:bg-gray-50 border-l-4 ${border}`}>
                                <div className={`w-10 h-10 rounded-full ${cls} flex items-center justify-center flex-shrink-0`}>
                                    <span className="material-symbols-outlined text-lg">{icon}</span>
                                </div>
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="text-sm font-semibold text-gray-900">{n.subject}</p>
                                        <div className="flex items-center gap-2 flex-shrink-0">
                                            {n.priority === 'high' && <span className="text-[10px] font-semibold bg-red-100 text-red-700 rounded-full px-2 py-0.5">Priority</span>}
                                            {n.is_unread && <span className="w-2 h-2 bg-blue-500 rounded-full" />}
                                        </div>
                                    </div>
                                    <p className="text-sm text-gray-600 mt-1 whitespace-pre-line">{n.body}</p>
                                    <div className="flex items-center gap-3 mt-2 text-xs text-gray-400">
                                        <span>{formatDateTime(n.created_at)}</span>
                                        {timeAgo(n.created_at) && <span>· {timeAgo(n.created_at)}</span>}
                                        {n.metadata?.entity_type && <span>· {n.metadata.entity_type} #{n.metadata.entity_id ?? '?'}</span>}
                                    </div>
                                </div>
                            </a>
                        );
                    })}
                </div>

                {notifications?.links?.length > 3 && (
                    <div className="mt-4 card">
                        <Pagination links={notifications.links} meta={{ from: notifications.from, to: notifications.to, total: notifications.total }} />
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
