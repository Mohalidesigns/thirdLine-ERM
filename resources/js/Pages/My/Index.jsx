import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';

/**
 * /my — the personal work queue (WP-08 TASK 5), the first page ported to
 * Inertia. Everything shown is what App\Services\MyResponsibilitiesService
 * computed: the page invents no item, no count and no estimate.
 */

const BUCKETS = [
    { key: 'overdue', label: 'Overdue', tone: 'text-red-700 bg-red-50 border-red-200', icon: 'warning' },
    { key: 'today', label: 'Due today', tone: 'text-amber-700 bg-amber-50 border-amber-200', icon: 'today' },
    { key: 'this_week', label: 'This week', tone: 'text-blue-700 bg-blue-50 border-blue-200', icon: 'date_range' },
    { key: 'later', label: 'Later / no deadline', tone: 'text-gray-600 bg-gray-50 border-gray-200', icon: 'schedule' },
];

const TYPE_ICONS = {
    task: 'approval',
    treatment_overdue: 'construction',
    risk_review_due: 'policy',
    control_test_due: 'fact_check',
    kri_reading_due: 'monitoring',
    breach: 'notification_important',
    delegation_in: 'move_to_inbox',
    delegation_out: 'outbox',
};

function formatDue(dateStr) {
    if (!dateStr) return null;
    try {
        return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    } catch {
        return dateStr;
    }
}

function tryRoute(name) {
    try {
        return route(name);
    } catch {
        return null;
    }
}

export default function Index({ queue }) {
    const total = queue?.total_items ?? 0;
    const minutes = queue?.total_minutes ?? 0;
    const buckets = queue?.buckets ?? {};
    const tasksUrl = tryRoute('risk.my-tasks.index');

    return (
        <AuthenticatedLayout title="My Responsibilities">
            <Head title="My Responsibilities" />

            <div className="mx-auto max-w-4xl">
                <div className="mb-4 flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 shadow-sm">
                    <div>
                        <p className="text-sm font-semibold text-gray-800">
                            {total} {total === 1 ? 'item' : 'items'} on your list
                        </p>
                        {total > 0 && (
                            <p className="text-xs text-gray-500">
                                Estimated effort ~{minutes} min — most items complete in under 3 minutes.
                            </p>
                        )}
                    </div>
                    {tasksUrl && (
                        <Link href={tasksUrl} className="text-xs font-medium text-[var(--color-primary)] hover:underline">
                            Full task inbox →
                        </Link>
                    )}
                </div>

                {total === 0 && (
                    <div className="rounded-lg border border-dashed border-gray-300 bg-white">
                        <EmptyState
                            icon={<span className="material-symbols-outlined text-4xl text-emerald-400">task_alt</span>}
                            title="Nothing on your list"
                            description="You owe the risk process nothing today. That is the report."
                        />
                    </div>
                )}

                {BUCKETS.map((bucket) => {
                    const items = buckets[bucket.key] ?? [];
                    if (items.length === 0) return null;

                    return (
                        <section key={bucket.key} className="mb-4">
                            <h2 className={`mb-2 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold ${bucket.tone}`}>
                                <span className="material-symbols-outlined text-[15px] leading-none">{bucket.icon}</span>
                                {bucket.label} · {items.length}
                            </h2>
                            <ul className="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                                {items.map((item, idx) => (
                                    <li key={`${item.type}-${item.id ?? idx}`}>
                                        <a href={item.url} className="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50">
                                            <span className="material-symbols-outlined shrink-0 rounded-md bg-gray-100 p-1.5 text-[18px] leading-none text-gray-500">
                                                {TYPE_ICONS[item.type] ?? 'assignment'}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium text-gray-800">{item.title}</span>
                                                {item.subtitle && (
                                                    <span className="block truncate text-xs text-gray-400">{item.subtitle}</span>
                                                )}
                                            </span>
                                            {item.badge && (
                                                <span className="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">{item.badge}</span>
                                            )}
                                            {item.due_at && (
                                                <span className="shrink-0 text-xs tabular-nums text-gray-400">{formatDue(item.due_at)}</span>
                                            )}
                                            <span className="shrink-0 rounded bg-gray-50 px-1.5 py-0.5 text-[10px] text-gray-400" title="Estimated effort">
                                                ~{item.minutes} min
                                            </span>
                                            <span className="material-symbols-outlined shrink-0 text-[18px] text-gray-300">chevron_right</span>
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}
            </div>
        </AuthenticatedLayout>
    );
}
