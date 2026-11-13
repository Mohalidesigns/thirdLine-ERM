import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import Icon from '@thirdline/ui/Components/Icon';
import PageHeader from '@thirdline/ui/Components/PageHeader';

export default function ActionTrackerIndex({
    stats,
    actions,
    filters,
    owners,
    kinds,
    horizons,
    statuses,
    urls,
}) {
    const [search, setSearch] = useState(filters?.search || '');
    const [kind, setKind] = useState(filters?.kind || '');
    const [status, setStatus] = useState(filters?.status || '');
    const [ownerId, setOwnerId] = useState(filters?.owner_id || '');
    const [horizon, setHorizon] = useState(filters?.horizon || '');

    const applyFilters = (newFilters = {}) => {
        const query = {
            search: search || undefined,
            kind: kind || undefined,
            status: status || undefined,
            owner_id: ownerId || undefined,
            horizon: horizon || undefined,
            ...newFilters,
        };

        // Remove undefined keys
        Object.keys(query).forEach((key) => {
            if (query[key] === undefined || query[key] === '') {
                delete query[key];
            }
        });

        router.get(urls.index, query, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        applyFilters();
    };

    const resetFilters = () => {
        setSearch('');
        setKind('');
        setStatus('');
        setOwnerId('');
        setHorizon('');
        router.get(urls.index, {}, { preserveScroll: true });
    };

    const getStatusBadge = (s) => {
        switch (s) {
            case 'completed':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Completed</span>;
            case 'in_progress':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">In Progress</span>;
            case 'overdue':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Overdue</span>;
            case 'not_started':
            default:
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">Not Started</span>;
        }
    };

    const getKindBadge = (item) => {
        if (item.kind === 'immediate') {
            return (
                <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-800 border border-amber-200">
                    Immediate: {item.time_horizon_label || item.time_horizon || 'Plan'}
                </span>
            );
        }
        return (
            <span className="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-blue-50 text-blue-800 border border-blue-200">
                Risk & Resolution Gap
            </span>
        );
    };

    const items = actions?.data || [];
    const meta = actions?.meta || { total: 0, current_page: 1, last_page: 1 };

    return (
        <AppLayout title="Scenario Action Tracker">
            <Head title="Scenario Action Tracker" />

            <PageHeader
                title="Scenario Remediation Action Tracker"
                breadcrumbs={[
                    { label: 'Scenario modelling', href: urls.scenarios },
                    { label: 'Action Tracker' },
                ]}
                subtitle={`${stats.total} total scenario actions across all stress test scenarios`}
                actions={
                    <Link href={urls.scenarios} className="btn-secondary text-sm flex items-center gap-1.5">
                        <Icon name="arrow-left" className="h-4 w-4" />
                        Back to Scenarios
                    </Link>
                }
            />

            <div className="space-y-4">
                {/* KPI Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Actions</div>
                        <div className="mt-1 text-2xl font-bold text-gray-900">{stats.total}</div>
                    </div>
                    <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Not Started</div>
                        <div className="mt-1 text-2xl font-bold text-gray-600">{stats.not_started}</div>
                    </div>
                    <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">In Progress</div>
                        <div className="mt-1 text-2xl font-bold text-blue-600">{stats.in_progress}</div>
                    </div>
                    <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Completed</div>
                        <div className="mt-1 text-2xl font-bold text-green-600">{stats.completed}</div>
                    </div>
                    <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Overdue</div>
                        <div className="mt-1 text-2xl font-bold text-red-600">{stats.overdue}</div>
                    </div>
                    <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                        <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Promoted to Issues</div>
                        <div className="mt-1 text-2xl font-bold text-purple-600">{stats.promoted_issues}</div>
                    </div>
                </div>

                {/* Filter and Search Bar */}
                <div className="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                    <form onSubmit={handleSearchSubmit} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
                        <div className="lg:col-span-2">
                            <label className="block text-[11px] font-semibold text-gray-600 uppercase mb-1">
                                Search Action / Gap / Scenario
                            </label>
                            <input
                                type="text"
                                placeholder="Search actions or scenarios..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full text-xs rounded-md border-gray-300 focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                            />
                        </div>

                        <div>
                            <label className="block text-[11px] font-semibold text-gray-600 uppercase mb-1">
                                Kind
                            </label>
                            <select
                                value={kind}
                                onChange={(e) => {
                                    setKind(e.target.value);
                                    applyFilters({ kind: e.target.value });
                                }}
                                className="w-full text-xs rounded-md border-gray-300 focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                            >
                                {(kinds || []).map((k) => (
                                    <option key={k.value} value={k.value}>{k.label}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-[11px] font-semibold text-gray-600 uppercase mb-1">
                                Status
                            </label>
                            <select
                                value={status}
                                onChange={(e) => {
                                    setStatus(e.target.value);
                                    applyFilters({ status: e.target.value });
                                }}
                                className="w-full text-xs rounded-md border-gray-300 focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                            >
                                {(statuses || []).map((s) => (
                                    <option key={s.value} value={s.value}>{s.label}</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-[11px] font-semibold text-gray-600 uppercase mb-1">
                                Owner
                            </label>
                            <select
                                value={ownerId}
                                onChange={(e) => {
                                    setOwnerId(e.target.value);
                                    applyFilters({ owner_id: e.target.value });
                                }}
                                className="w-full text-xs rounded-md border-gray-300 focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                            >
                                <option value="">All Owners</option>
                                {(owners || []).map((o) => (
                                    <option key={o.id} value={o.id}>{o.name}</option>
                                ))}
                            </select>
                        </div>

                        <div className="flex items-end gap-2">
                            <button
                                type="submit"
                                className="btn-primary text-xs py-2 px-3 flex-1"
                            >
                                Filter
                            </button>
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="btn-secondary text-xs py-2 px-3"
                                title="Reset filters"
                            >
                                Reset
                            </button>
                        </div>
                    </form>
                </div>

                {/* Actions Table */}
                <div className="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                    {items.length === 0 ? (
                        <div className="p-12 text-center text-sm text-gray-500">
                            No scenario actions found matching your criteria.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                                <thead className="bg-gray-50 text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">Scenario</th>
                                        <th scope="col" className="px-4 py-3">Classification</th>
                                        <th scope="col" className="px-4 py-3">Risk Focus / Action</th>
                                        <th scope="col" className="px-4 py-3">Owner / BU</th>
                                        <th scope="col" className="px-4 py-3">Target Due</th>
                                        <th scope="col" className="px-4 py-3">Status</th>
                                        <th scope="col" className="px-4 py-3">Enterprise Issue</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {items.map((act) => (
                                        <tr key={act.id} className="hover:bg-gray-50/50">
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {act.scenario_url ? (
                                                    <Link
                                                        href={act.scenario_url}
                                                        className="font-mono text-xs font-bold text-[var(--color-primary)] hover:underline block"
                                                    >
                                                        {act.scenario_reference}
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono text-xs font-bold text-gray-900 block">
                                                        {act.scenario_reference}
                                                    </span>
                                                )}
                                                <span className="text-xs text-gray-500 truncate block max-w-xs" title={act.scenario_title}>
                                                    {act.scenario_title}
                                                </span>
                                            </td>

                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {getKindBadge(act)}
                                            </td>

                                            <td className="px-4 py-3 max-w-md">
                                                <div className="font-semibold text-xs text-gray-900">{act.risk_area}</div>
                                                <div className="text-xs text-gray-600 line-clamp-2 mt-0.5">{act.action_plan}</div>
                                                {act.identified_gap && (
                                                    <div className="text-[11px] text-red-600 line-clamp-1 mt-0.5">
                                                        Gap: {act.identified_gap}
                                                    </div>
                                                )}
                                            </td>

                                            <td className="px-4 py-3 whitespace-nowrap text-xs text-gray-600">
                                                <div className="font-medium text-gray-900">{act.owner_name || 'Unassigned'}</div>
                                                <div className="text-gray-400">{act.business_unit_name || act.department || '—'}</div>
                                            </td>

                                            <td className="px-4 py-3 whitespace-nowrap text-xs text-gray-600">
                                                {act.due_date || 'No date'}
                                            </td>

                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {getStatusBadge(act.status)}
                                            </td>

                                            <td className="px-4 py-3 whitespace-nowrap text-xs">
                                                {act.issue_reference ? (
                                                    <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-purple-100 text-purple-800">
                                                        #{act.issue_reference}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400 text-xs italic">Unpromoted</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* Pagination */}
                    {meta.last_page > 1 && (
                        <div className="px-4 py-3 bg-gray-50 border-t border-gray-200 flex items-center justify-between text-xs text-gray-600">
                            <div>
                                Page {meta.current_page} of {meta.last_page} ({meta.total} actions)
                            </div>
                            <div className="flex items-center gap-1">
                                {(actions?.links || []).map((link, idx) => (
                                    <button
                                        key={idx}
                                        type="button"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url, {}, { preserveScroll: true, preserveState: true })}
                                        className={`px-2.5 py-1 rounded border text-xs ${
                                            link.active
                                                ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                                                : link.url
                                                    ? 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100'
                                                    : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

