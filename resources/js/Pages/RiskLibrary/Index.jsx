import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import { useGridState } from '@thirdline/ui/Components/DataGrid/useGridState';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import BulkApplySlideOver from '@/Components/RiskLibrary/BulkApplySlideOver';

/**
 * Master Risk Library Index (ADR 0028 Phase 1, risk-library-index.md).
 *
 * Uses the shared DataGrid + useGridState backed by App\Grids\Definitions\RiskLibraryGrid,
 * paired with status quick-filter pills, cascading Basel L1/L2 filters, examiner-ready
 * CSV/PDF export links, and the Bulk Apply slide-over.
 */
export default function Index({
    counts = { active: 0, retired: 0, total: 0 },
    grid,
    filterOptions = { categories: [], baselL1: [], baselL2: [] },
    can = {},
}) {
    const gridState = useGridState(grid);
    const activeFilters = grid?.state?.filters ?? {};
    const currentStatus = activeFilters.status || '';
    const currentCategory = activeFilters.category_id || '';
    const currentBaselL1 = activeFilters.basel_l1 || '';
    const currentBaselL2 = activeFilters.basel_l2 || '';

    const [searchInput, setSearchInput] = useState(grid?.state?.search ?? '');
    const [bulkApplyOpen, setBulkApplyOpen] = useState(false);
    const [bulkApplyItem, setBulkApplyItem] = useState(null);
    const isFirstRender = useRef(true);

    // Sync local search input if URL state changes externally (e.g. Clear filters)
    useEffect(() => {
        setSearchInput(grid?.state?.search ?? '');
    }, [grid?.state?.search]);

    // Debounced search update (350 ms)
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        const serverSearch = grid?.state?.search ?? '';
        if (searchInput === serverSearch) return;

        const timer = setTimeout(() => {
            gridState.update({ search: searchInput });
        }, 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchInput]);

    const filteredBaselL2 = useMemo(() => {
        if (!currentBaselL1) return [];
        return (filterOptions.baselL2 ?? []).filter((opt) => opt.l1 === currentBaselL1);
    }, [filterOptions.baselL2, currentBaselL1]);

    const hasActiveFilters = Boolean(
        currentStatus || currentCategory || currentBaselL1 || currentBaselL2 || (grid?.state?.search ?? ''),
    );

    const exportQueryString = useMemo(() => {
        const params = new URLSearchParams();
        if (currentStatus) params.set('filters[status]', currentStatus);
        if (currentCategory) params.set('filters[category_id]', currentCategory);
        if (currentBaselL1) params.set('filters[basel_l1]', currentBaselL1);
        if (currentBaselL2) params.set('filters[basel_l2]', currentBaselL2);
        if (grid?.state?.search) params.set('search', grid.state.search);
        const qs = params.toString();
        return qs ? `?${qs}` : '';
    }, [currentStatus, currentCategory, currentBaselL1, currentBaselL2, grid?.state?.search]);

    const createUrl = tryRoute('risk.library.create');
    const exportCsvUrl = tryRoute('risk.library.export.csv');
    const exportPdfUrl = tryRoute('risk.library.export.pdf');

    const pill = (active) =>
        `px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${
            active ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100'
        }`;

    const meta = grid?.rows?.meta ?? {};
    const totalCount = counts.total ?? 0;
    const activeCount = counts.active ?? 0;
    const retiredCount = counts.retired ?? 0;

    return (
        <AuthenticatedLayout title="Risk Library">
            <Head title="Risk Library" />

            <PageHeader
                title="Risk Library"
                subtitle={`${activeCount} active definitions · ${retiredCount} retired`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {can.export && exportCsvUrl && (
                            <a
                                href={`${exportCsvUrl}${exportQueryString}`}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">download</span>
                                Export CSV
                            </a>
                        )}
                        {can.export && exportPdfUrl && (
                            <a
                                href={`${exportPdfUrl}${exportQueryString}`}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">picture_as_pdf</span>
                                Export PDF
                            </a>
                        )}
                        {can.bulkApply && activeCount > 0 && (
                            <button
                                type="button"
                                onClick={() => {
                                    setBulkApplyItem(null);
                                    setBulkApplyOpen(true);
                                }}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">library_add</span>
                                Bulk Apply
                            </button>
                        )}
                        {can.create && createUrl && (
                            <Link
                                href={createUrl}
                                className="btn-primary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">add_circle</span>
                                New Library Risk
                            </Link>
                        )}
                    </div>
                }
            />

            {totalCount === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 p-8">
                    <EmptyState
                        icon="menu_book"
                        title="No risks in the library yet"
                        description={
                            can.create
                                ? 'Build your master risk catalogue to reuse consistent risk definitions across entities and processes.'
                                : 'Contact your Risk Manager to add library definitions.'
                        }
                        actionLabel={can.create && createUrl ? 'New Library Risk' : null}
                        actionHref={can.create ? createUrl : null}
                    />
                </div>
            ) : (
                <>
                    {/* Status Quick-Filter Pills */}
                    <div
                        role="group"
                        aria-label="Filter by status"
                        className="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit"
                    >
                        <button
                            type="button"
                            aria-pressed={currentStatus === 'active'}
                            onClick={() => gridState.update({ filters: { status: 'active' } })}
                            className={pill(currentStatus === 'active')}
                        >
                            Active ({activeCount})
                        </button>
                        <button
                            type="button"
                            aria-pressed={currentStatus === 'retired'}
                            onClick={() => gridState.update({ filters: { status: 'retired' } })}
                            className={pill(currentStatus === 'retired')}
                        >
                            Retired ({retiredCount})
                        </button>
                        <button
                            type="button"
                            aria-pressed={currentStatus === ''}
                            onClick={() => gridState.update({ filters: { status: '' } })}
                            className={pill(currentStatus === '')}
                        >
                            All ({totalCount})
                        </button>
                    </div>

                    {/* Cascading Taxonomy & Basel Filter Bar */}
                    <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div>
                                <label
                                    htmlFor="rl-search"
                                    className="block text-xs font-medium text-gray-600 mb-1"
                                >
                                    Search
                                </label>
                                <input
                                    id="rl-search"
                                    type="search"
                                    value={searchInput}
                                    onChange={(e) => setSearchInput(e.target.value)}
                                    placeholder="Code, title, or description…"
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="rl-category"
                                    className="block text-xs font-medium text-gray-600 mb-1"
                                >
                                    Category
                                </label>
                                <select
                                    id="rl-category"
                                    value={currentCategory}
                                    onChange={(e) =>
                                        gridState.update({ filters: { category_id: e.target.value } })
                                    }
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">All Categories</option>
                                    {(filterOptions.categories ?? []).map((cat) => (
                                        <option key={cat.id} value={cat.id}>
                                            {cat.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label
                                    htmlFor="rl-basel-l1"
                                    className="block text-xs font-medium text-gray-600 mb-1"
                                >
                                    Basel L1 Event Type
                                </label>
                                <select
                                    id="rl-basel-l1"
                                    value={currentBaselL1}
                                    onChange={(e) =>
                                        gridState.update({
                                            filters: {
                                                basel_l1: e.target.value,
                                                basel_l2: '',
                                            },
                                        })
                                    }
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">All Basel L1</option>
                                    {(filterOptions.baselL1 ?? []).map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label
                                    htmlFor="rl-basel-l2"
                                    className="block text-xs font-medium text-gray-600 mb-1"
                                >
                                    Basel L2 Event Type
                                </label>
                                <select
                                    id="rl-basel-l2"
                                    value={currentBaselL2}
                                    disabled={!currentBaselL1}
                                    onChange={(e) =>
                                        gridState.update({ filters: { basel_l2: e.target.value } })
                                    }
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white disabled:bg-gray-100 disabled:text-gray-400 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">
                                        {currentBaselL1 ? 'All Basel L2' : 'Select Basel L1 first'}
                                    </option>
                                    {filteredBaselL2.map((opt) => (
                                        <option key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {hasActiveFilters && (
                            <div className="mt-3 flex justify-end">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSearchInput('');
                                        gridState.clear();
                                    }}
                                    className="text-xs font-medium text-[#1A365D] hover:underline"
                                >
                                    Clear filters
                                </button>
                            </div>
                        )}
                    </div>

                    {/* Screen-reader live region */}
                    <p className="sr-only" aria-live="polite">
                        {meta.total > 0
                            ? `Showing ${meta.from ?? 0}–${meta.to ?? 0} of ${meta.total} library items`
                            : 'No library items match the current filters.'}
                    </p>

                    {/* DataGrid with loading state */}
                    <div
                        aria-busy={gridState.loading ? 'true' : undefined}
                        className={gridState.loading ? 'opacity-60 pointer-events-none transition-opacity' : ''}
                    >
                        <DataGrid grid={grid} />
                    </div>
                </>
            )}

            <BulkApplySlideOver
                open={bulkApplyOpen}
                onClose={() => setBulkApplyOpen(false)}
                initialItem={bulkApplyItem}
                canCreateLibraryItem={Boolean(can.create)}
            />
        </AuthenticatedLayout>
    );
}
