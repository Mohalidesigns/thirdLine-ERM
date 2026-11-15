import { Head, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import { formatDateTime } from '@thirdline/ui/utils';

const EVENT_STYLE = {
    login: { color: 'text-emerald-700', bg: 'bg-emerald-50', border: 'border-emerald-200' },
    logout: { color: 'text-gray-700', bg: 'bg-gray-100', border: 'border-gray-200' },
    login_failed: { color: 'text-red-700', bg: 'bg-red-50', border: 'border-red-200' },
    access_denied: { color: 'text-rose-700', bg: 'bg-rose-50', border: 'border-rose-200' },
    password_reset: { color: 'text-blue-700', bg: 'bg-blue-50', border: 'border-blue-200' },
    created: { color: 'text-emerald-700', bg: 'bg-emerald-50', border: 'border-emerald-200' },
    updated: { color: 'text-amber-700', bg: 'bg-amber-50', border: 'border-amber-200' },
    deleted: { color: 'text-red-700', bg: 'bg-red-50', border: 'border-red-200' },
};

function eventStyle(event) {
    return EVENT_STYLE[event] || { color: 'text-slate-700', bg: 'bg-slate-50', border: 'border-slate-200' };
}

function Initials({ name, className = '' }) {
    const initials = (name || '?').split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase();
    return (
        <div className={`w-8 h-8 rounded-full bg-[var(--color-primary)] text-white flex items-center justify-center text-[11px] font-semibold flex-shrink-0 ${className}`}>
            {initials}
        </div>
    );
}

function DetailPanel({ log, onClose }) {
    if (!log) return null;
    const s = eventStyle(log.event);
    return (
        <div className="fixed inset-0 z-50 flex" onClick={onClose}>
            <div className="absolute inset-0 bg-black/40 backdrop-blur-xs transition-opacity" />
            <aside
                className="ml-auto relative w-full max-w-lg bg-white shadow-2xl h-full overflow-y-auto"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="px-5 py-4 border-b border-gray-200 flex items-center justify-between sticky top-0 bg-white z-10">
                    <div>
                        <span className={`inline-flex text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded border ${s.bg} ${s.color} ${s.border}`}>
                            {log.event_label}
                        </span>
                        <h3 className="mt-1 text-base font-semibold text-gray-900">{log.description || log.event_label}</h3>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-gray-400 hover:text-gray-700 text-2xl leading-none p-1 rounded-md hover:bg-gray-100"
                    >
                        &times;
                    </button>
                </div>
                <dl className="px-5 py-4 text-sm space-y-3">
                    <div className="flex gap-4">
                        <dt className="w-28 text-gray-500 font-medium">When</dt>
                        <dd className="text-gray-900">{formatDateTime(log.occurred_at)}</dd>
                    </div>
                    <div className="flex gap-4">
                        <dt className="w-28 text-gray-500 font-medium">Actor</dt>
                        <dd className="text-gray-900">
                            {log.user?.name || 'System'}
                            {log.user?.email && <span className="block text-xs text-gray-500">{log.user.email}</span>}
                        </dd>
                    </div>
                    <div className="flex gap-4">
                        <dt className="w-28 text-gray-500 font-medium">Subject</dt>
                        <dd className="text-gray-900">{log.subject_type ? `${log.subject_type}${log.subject_label ? ' — ' + log.subject_label : ''}` : '—'}</dd>
                    </div>
                    <div className="flex gap-4">
                        <dt className="w-28 text-gray-500 font-medium">Route</dt>
                        <dd className="font-mono text-xs text-gray-700 break-all">{log.method ? `${log.method} ` : ''}{log.route_name || log.url || '—'}</dd>
                    </div>
                    <div className="flex gap-4">
                        <dt className="w-28 text-gray-500 font-medium">IP address</dt>
                        <dd className="font-mono text-xs text-gray-700">{log.ip_address || '—'}</dd>
                    </div>
                    {log.user_agent && (
                        <div className="flex gap-4">
                            <dt className="w-28 text-gray-500 font-medium">User agent</dt>
                            <dd className="text-xs text-gray-600 break-all">{log.user_agent}</dd>
                        </div>
                    )}
                </dl>
                {log.properties && Object.keys(log.properties).length > 0 && (
                    <div className="px-5 pb-6">
                        <p className="text-xs uppercase tracking-wide font-semibold text-gray-500 mb-2">Properties</p>
                        <pre className="text-[11px] bg-gray-50 border border-gray-200 rounded-lg p-3 overflow-x-auto text-gray-700 font-mono">
                            {JSON.stringify(log.properties, null, 2)}
                        </pre>
                    </div>
                )}
            </aside>
        </div>
    );
}

export default function Index({ logs = { data: [], links: [] }, filters = {}, options = { events: [], users: [], model_types: [] } }) {
    const [local, setLocal] = useState({
        search: filters.search || '',
        event: filters.event || '',
        user_id: filters.user_id || '',
        auditable_type: filters.auditable_type || '',
        from: filters.from || '',
        to: filters.to || '',
    });
    const [selected, setSelected] = useState(null);

    const applyFilters = (next = local) => {
        const params = {};
        Object.entries(next).forEach(([k, v]) => { if (v) params[k] = v; });
        router.get(route('admin.activity-log'), params, { preserveState: true, preserveScroll: true });
    };

    const clearAll = () => {
        setLocal({ search: '', event: '', user_id: '', auditable_type: '', from: '', to: '' });
        router.get(route('admin.activity-log'), {}, { preserveState: true });
    };

    const hasFilters = useMemo(() => Object.values(local).some(v => !!v), [local]);

    const onKeyDownSearch = (e) => { if (e.key === 'Enter') applyFilters(); };

    return (
        <AuthenticatedLayout title="Activity Log">
            <Head title="Activity Log" />

            <PageHeader
                title="Activity Log"
                subtitle="Every user action on the application — logins, CRUD, workflow transitions"
                breadcrumbs={[
                    { label: 'Administration', href: route('admin.settings') },
                    { label: 'Activity Log' },
                ]}
            />

            {/* Filters */}
            <div className="card p-4 mb-4">
                <div className="grid grid-cols-1 md:grid-cols-6 gap-3">
                    <div className="md:col-span-2">
                        <label className="filter-label">Search</label>
                        <input
                            type="text"
                            value={local.search}
                            onChange={(e) => setLocal({ ...local, search: e.target.value })}
                            onKeyDown={onKeyDownSearch}
                            placeholder="Description, user, subject, URL…"
                            className="filter-input w-full"
                        />
                    </div>
                    <div>
                        <label className="filter-label">Event</label>
                        <select
                            value={local.event}
                            onChange={(e) => { const n = { ...local, event: e.target.value }; setLocal(n); applyFilters(n); }}
                            className="filter-select w-full"
                        >
                            <option value="">All events</option>
                            {options.events?.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="filter-label">User</label>
                        <select
                            value={local.user_id}
                            onChange={(e) => { const n = { ...local, user_id: e.target.value }; setLocal(n); applyFilters(n); }}
                            className="filter-select w-full"
                        >
                            <option value="">All users</option>
                            {options.users?.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="filter-label">Subject type</label>
                        <select
                            value={local.auditable_type}
                            onChange={(e) => { const n = { ...local, auditable_type: e.target.value }; setLocal(n); applyFilters(n); }}
                            className="filter-select w-full"
                        >
                            <option value="">All types</option>
                            {options.model_types?.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </select>
                    </div>
                    <div className="md:col-span-1 grid grid-cols-2 gap-2">
                        <div>
                            <label className="filter-label">From</label>
                            <input type="date" value={local.from} onChange={(e) => setLocal({ ...local, from: e.target.value })} className="filter-input w-full" />
                        </div>
                        <div>
                            <label className="filter-label">To</label>
                            <input type="date" value={local.to} onChange={(e) => setLocal({ ...local, to: e.target.value })} className="filter-input w-full" />
                        </div>
                    </div>
                </div>
                <div className="mt-3 flex justify-end gap-2">
                    {hasFilters && (
                        <button type="button" onClick={clearAll} className="btn-secondary text-sm">Clear</button>
                    )}
                    <button type="button" onClick={() => applyFilters()} className="btn-primary text-sm">Apply</button>
                </div>
            </div>

            {/* Timeline */}
            <div className="card overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="text-left px-4 py-2.5 font-semibold text-gray-600 uppercase text-xs tracking-wide">When</th>
                                <th className="text-left px-4 py-2.5 font-semibold text-gray-600 uppercase text-xs tracking-wide">User</th>
                                <th className="text-left px-4 py-2.5 font-semibold text-gray-600 uppercase text-xs tracking-wide">Event</th>
                                <th className="text-left px-4 py-2.5 font-semibold text-gray-600 uppercase text-xs tracking-wide">Subject</th>
                                <th className="text-left px-4 py-2.5 font-semibold text-gray-600 uppercase text-xs tracking-wide">Description</th>
                                <th className="text-left px-4 py-2.5 font-semibold text-gray-600 uppercase text-xs tracking-wide">IP</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {logs.data && logs.data.length > 0 ? logs.data.map(log => {
                                const s = eventStyle(log.event);
                                return (
                                    <tr key={log.id} className="hover:bg-gray-50/80 cursor-pointer transition-colors" onClick={() => setSelected(log)}>
                                        <td className="px-4 py-2.5 whitespace-nowrap text-gray-600 text-xs">{formatDateTime(log.occurred_at)}</td>
                                        <td className="px-4 py-2.5 whitespace-nowrap">
                                            <div className="flex items-center gap-2">
                                                <Initials name={log.user?.name} />
                                                <div>
                                                    <div className="text-gray-900 text-sm font-medium">{log.user?.name || 'System'}</div>
                                                    {log.user?.email && <div className="text-xs text-gray-500">{log.user.email}</div>}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-2.5 whitespace-nowrap">
                                            <span className={`inline-flex text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded border ${s.bg} ${s.color} ${s.border}`}>
                                                {log.event_label}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-gray-700">
                                            {log.subject_type ? (
                                                <div>
                                                    <div className="text-xs text-gray-500 font-medium">{log.subject_type}</div>
                                                    <div className="text-sm truncate max-w-xs">{log.subject_label || '—'}</div>
                                                </div>
                                            ) : <span className="text-gray-400">—</span>}
                                        </td>
                                        <td className="px-4 py-2.5 text-gray-700 truncate max-w-sm">{log.description || '—'}</td>
                                        <td className="px-4 py-2.5 whitespace-nowrap font-mono text-xs text-gray-600">{log.ip_address || '—'}</td>
                                    </tr>
                                );
                            }) : (
                                <tr>
                                    <td colSpan={6} className="text-center py-16 text-gray-500 text-sm">
                                        No activity recorded yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                {logs.links && logs.data.length > 0 && (
                    <div className="border-t border-gray-100 px-4 py-3">
                        <Pagination links={logs.links} meta={logs} />
                    </div>
                )}
            </div>

            <DetailPanel log={selected} onClose={() => setSelected(null)} />
        </AuthenticatedLayout>
    );
}
