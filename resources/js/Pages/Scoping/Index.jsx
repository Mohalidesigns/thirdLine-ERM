import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataGrid from '@/Components/DataGrid/DataGrid';
import PageHeader from '@/Components/PageHeader';
import { useGridState } from '@/Components/DataGrid/useGridState';

function tryRoute(name) {
    try {
        return route(name);
    } catch {
        return null;
    }
}

/**
 * The entity register — the pilot grid flip (migration Phase 2). Search,
 * filters, sorting, saved views and export live inside the shared grid;
 * this page adds the header, the actions and the quick-filter pills.
 */
export default function Index({ entityTypes, totalCount, grid }) {
    const { auth } = usePage().props;
    const permissions = auth?.permissions ?? [];
    const gridState = useGridState(grid);
    const activeType = grid.state.filters?.entity_type_id || '';

    const createUrl = tryRoute('risk.scoping.create');
    const dashboardUrl = tryRoute('risk.scoping.dashboard');

    return (
        <AuthenticatedLayout title="Entity Register">
            <Head title="Entity Register" />

            <PageHeader
                title="Entity Register"
                subtitle={`${totalCount} total entities across the organization`}
                actions={
                    <>
                        {permissions.includes('entity.create') && createUrl && (
                            // Inertia links since Phase 3.1 ported the create and dashboard pages.
                            <Link href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">add_circle</span> New Entity
                            </Link>
                        )}
                        {dashboardUrl && (
                            <Link href={dashboardUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">dashboard</span> Dashboard
                            </Link>
                        )}
                    </>
                }
            />

            <div className="flex gap-2 flex-wrap mb-4">
                <button
                    type="button"
                    onClick={() => gridState.update({ filters: { entity_type_id: '' } })}
                    className={`px-3 py-1 rounded-full text-xs font-semibold ${activeType === '' ? 'bg-[var(--color-primary)] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`}
                >
                    All ({totalCount})
                </button>
                {entityTypes.filter((t) => t.count > 0).map((type) => (
                    <button
                        key={type.id}
                        type="button"
                        onClick={() => gridState.update({ filters: { entity_type_id: String(type.id) } })}
                        className={`px-3 py-1 rounded-full text-xs font-semibold ${String(activeType) === String(type.id) ? 'bg-[var(--color-primary)] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`}
                    >
                        {type.name} ({type.count})
                    </button>
                ))}
            </div>

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
