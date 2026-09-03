import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DataGrid from '@/Components/DataGrid/DataGrid';
import PageHeader from '@/Components/PageHeader';
import { useGridState } from '@/Components/DataGrid/useGridState';
import tryRoute from '@/lib/tryRoute';

/**
 * The live risk register (migration Phase 2) — see
 * App\Grids\Definitions\RisksGrid. The "as at" view of a closed period stays
 * Blade (resources/views/risk/register/historic.blade.php): its scores exist
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
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add_circle</span> New Risk
                        </a>
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
