import Countdown from '@/Components/Countdown';
import SeverityBadge from '@/Components/SeverityBadge';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    DataTable,
    FilterBar,
    PageHeader,
    Pagination,
    StatCard,
    StatusBadge,
} from '@thirdline/ui';
import { formatDate } from '@thirdline/ui/utils';

export default function Index({
    incidents,
    filters = {},
    statuses = [],
    severities = [],
    types = [],
    entities = [],
    stats = {},
    lossByBaselEventType = [],
}) {
    const { auth } = usePage().props;
    const canCreate = auth?.permissions?.includes('incident.create') || auth?.permissions?.includes('*');

    const columns = [
        {
            field: 'incident_ref',
            label: 'Ref',
            render: (row) => (
                <Link
                    href={route('risk.incidents.show', row.id)}
                    className="font-mono text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline"
                    onClick={(e) => e.stopPropagation()}
                >
                    {row.incident_ref}
                </Link>
            ),
        },
        {
            field: 'title',
            label: 'Incident',
            render: (row) => (
                <div className="max-w-sm">
                    <Link
                        href={route('risk.incidents.show', row.id)}
                        className="font-medium text-gray-900 hover:text-blue-600 hover:underline"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {row.title}
                    </Link>
                    <p className="text-xs capitalize text-gray-500">
                        {row.incident_type?.replace('_', ' ')}
                        {row.entity ? ` · ${row.entity.name}` : ''}
                        {row.near_miss ? ' · near miss' : ''}
                    </p>
                </div>
            ),
        },
        { field: 'severity', label: 'Severity', render: (row) => <SeverityBadge severity={row.severity} /> },
        {
            field: 'occurred_at',
            label: 'Occurred',
            render: (row) => <span className="text-xs text-gray-600">{formatDate(row.occurred_at)}</span>,
        },
        {
            field: 'net_loss',
            label: 'Net loss',
            render: (row) =>
                row.net_loss?.formatted && row.net_loss.amount !== 0 ? (
                    <span className="tabular-nums font-medium text-gray-900">{row.net_loss.formatted}</span>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        {
            field: 'notification_due_at',
            label: 'Notification',
            render: (row) =>
                row.outstanding_notifications_count > 0 ? (
                    <Countdown dueAt={row.notification_due_at} compact />
                ) : row.is_reportable ? (
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                        Settled
                    </span>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        {
            field: 'open_actions_count',
            label: 'Actions',
            render: (row) =>
                row.open_actions_count > 0 ? (
                    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                        {row.open_actions_count} open
                    </span>
                ) : (
                    <span className="text-gray-400">{row.actions_count ?? 0}</span>
                ),
        },
        { field: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
        {
            field: 'view',
            label: '',
            render: (row) => (
                <Link
                    href={route('risk.incidents.show', row.id)}
                    className="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800"
                    onClick={(e) => e.stopPropagation()}
                >
                    View <span className="material-symbols-outlined text-sm">arrow_forward</span>
                </Link>
            ),
        },
    ];

    return (
        <AppLayout title="Incident Register">
            <Head title="Incident Register" />

            <PageHeader
                title="Incident Register"
                subtitle="Operational, security, fraud and data incidents — with Basel loss capture and live regulatory countdowns"
                actions={
                    canCreate && (
                        <Link href={route('risk.incidents.create')} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add</span> Report an Incident
                        </Link>
                    )
                }
            />

            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">error</span>}
                    title="Open"
                    value={stats.open ?? 0}
                    color="blue"
                />
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">speed</span>}
                    title="Near misses"
                    value={stats.near_misses ?? 0}
                    color="teal"
                    subtitle="leading indicator"
                />
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">schedule</span>}
                    title="Notifications due"
                    value={stats.notification_due ?? 0}
                    color="amber"
                />
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">warning</span>}
                    title="Windows missed"
                    value={stats.notification_breached ?? 0}
                    color="red"
                />
            </div>

            {lossByBaselEventType.length > 0 && (
                <div className="card mb-6">
                    <div className="card-header font-semibold text-gray-800">Net loss by Basel event type</div>
                    <div className="card-body p-4">
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>Basel event type</th>
                                        <th>Incidents</th>
                                        <th>Net loss</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lossByBaselEventType.map((row, index) => (
                                        <tr key={index}>
                                            <td className="font-medium text-gray-800">{row.basel_event_type}</td>
                                            <td className="tabular-nums">{row.incidents}</td>
                                            <td className="tabular-nums font-semibold text-gray-900">{row.net_loss?.formatted}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-2 text-xs text-gray-400">
                            Near misses are excluded. Currencies are reported separately and never summed.
                        </p>
                    </div>
                </div>
            )}

            <div className="mb-4">
                <FilterBar
                    route={route('risk.incidents.index')}
                    currentFilters={filters}
                    searchPlaceholder="Search incidents…"
                    filters={[
                        { name: 'search', type: 'search', label: 'Search' },
                        { name: 'status', type: 'select', label: 'Status', options: statuses },
                        { name: 'severity', type: 'select', label: 'Severity', options: severities },
                        { name: 'incident_type', type: 'select', label: 'Type', options: types },
                        {
                            name: 'entity_id',
                            type: 'select',
                            label: 'Entity',
                            options: entities.map((e) => ({ value: e.id, label: e.name })),
                        },
                        { name: 'near_miss_only', type: 'checkbox', label: 'Near misses only' },
                        { name: 'reportable_only', type: 'checkbox', label: 'Reportable only' },
                    ]}
                />
            </div>

            <div className="card">
                <DataTable
                    columns={columns}
                    data={incidents.data}
                    emptyMessage="No incidents match the filters."
                    onRowClick={(row) => router.visit(route('risk.incidents.show', row.id))}
                />
                <Pagination paginator={incidents} />
            </div>
        </AppLayout>
    );
}
