import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { useGridState } from '@thirdline/ui/Components/DataGrid/useGridState';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The live risk register (migration Phase 2) — see
 * App\Grids\Definitions\RisksGrid. The "as at" view of a closed period is a
 * separate page (Register/Historic, migration Phase 3.2): its scores exist
 * only in memory, so it cannot ride the SQL-backed grid.
 */
export default function Index({ total, ratingCounts, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const gridState = useGridState(grid);
    const currentRating = grid.state.filters?.rating || '';
    const createUrl = tryRoute('risk.register.create');

    const pill = (active) =>
        `px-3 py-1.5 text-xs font-medium rounded-md ${active ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100'}`;

    return (
        <AuthenticatedLayout title="Risk Register">
            <Head title="Risk Register" />

            <PageHeader
                title="Risk Register"
                subtitle={`${total} risks registered`}
                actions={
                    permissions.includes('risk.create') && createUrl && (
                        <Link href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Risk
                        </Link>
                    )
                }
            />

            {/* Quick-filter pills: counts come from the controller; each pill
                pre-filters the grid via its filters[rating] URL state. */}
            <div className="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit">
                <button type="button" onClick={() => gridState.update({ filters: { rating: '' } })} className={pill(currentRating === '')}>
                    All ({total})
                </button>
                {Object.entries(ratingCounts).map(([rating, count]) => (
                    <button
                        key={rating}
                        type="button"
                        onClick={() => gridState.update({ filters: { rating } })}
                        className={pill(currentRating === rating)}
                    >
                        {rating} ({count})
                    </button>
                ))}
            </div>

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
