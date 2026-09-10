import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { useGridState } from '@thirdline/ui/Components/DataGrid/useGridState';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const STATUS_TABS = [
    ['in_progress', 'In Progress'],
    ['completed', 'Completed'],
    ['overdue', 'Overdue'],
    ['not_started', 'Not Started'],
    ['on_hold', 'On Hold'],
];

/**
 * Treatment plans (migration Phase 2) — see
 * App\Grids\Definitions\TreatmentPlansGrid. "Overdue" is honoured by the
 * grid's status filter (target date past while the plan is still open).
 */
export default function Index({ total, lastUpdated, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const gridState = useGridState(grid);
    const currentStatus = grid.state.filters?.status || '';
    const createUrl = tryRoute('risk.treatments.create');

    const tab = (active) =>
        `px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${active ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100'}`;

    return (
        <AuthenticatedLayout title="Treatment Plans">
            <Head title="Treatment Plans" />

            <PageHeader
                title="Treatment Plans"
                subtitle={`${total} plans registered · Last updated: ${lastUpdated}`}
                actions={
                    permissions.includes('treatment.create') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Plan
                        </a>
                    )
                }
            />

            <div className="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit flex-wrap">
                <button type="button" onClick={() => gridState.update({ filters: { status: '' } })} className={tab(currentStatus === '')}>
                    All
                </button>
                {STATUS_TABS.map(([value, label]) => (
                    <button key={value} type="button" onClick={() => gridState.update({ filters: { status: value } })} className={tab(currentStatus === value)}>
                        {label}
                    </button>
                ))}
            </div>

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
