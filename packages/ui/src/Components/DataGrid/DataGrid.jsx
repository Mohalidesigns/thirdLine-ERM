import { useEffect, useState } from 'react';
import GridBulkBar from './GridBulkBar';
import GridTable from './GridTable';
import GridToolbar from './GridToolbar';
import { useGridState } from './useGridState';

/**
 * The shared data grid (migration Phase 2): the React face of
 * App\Presenters\GridPresenter. Everything entity-specific arrives in the
 * `grid` prop; this component owns search, filters, sort, paging, the
 * column chooser, saved views, row selection, bulk actions, inline edit and
 * the export links — the feature list of the Livewire component it replaces.
 *
 *   <DataGrid grid={grid} />              // page prop named `grid`
 *   <DataGrid grid={risks} prop="risks" />  // any other prop name
 */
export default function DataGrid({ grid, prop = 'grid' }) {
    const state = useGridState(grid, prop);
    const [selected, setSelected] = useState([]);
    const [selectingAll, setSelectingAll] = useState(false);
    const [rows, setRows] = useState(grid.rows.data);

    useEffect(() => {
        setRows(grid.rows.data);
    }, [grid.rows.data]);

    // Any change of page, search, filters or sort drops the selection, as
    // the Livewire grid did — a selection is of what you can see.
    useEffect(() => {
        setSelected([]);
        setSelectingAll(false);
    }, [grid.state.search, grid.state.filters, grid.state.sort, grid.state.dir, grid.rows.meta.current_page]);

    const toggleRow = (id) => {
        setSelectingAll(false);
        setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const togglePage = (ids) => {
        setSelectingAll(false);
        setSelected((prev) => {
            const all = ids.every((id) => prev.includes(id));
            return all ? prev.filter((id) => !ids.includes(id)) : Array.from(new Set([...prev, ...ids]));
        });
    };

    const cellSaved = (id, key, cell) => {
        setRows((prev) => prev.map((row) => (row.id === id ? { ...row, cells: { ...row.cells, [key]: cell } } : row)));
    };

    const meta = grid.rows.meta;
    const view = { ...grid, rows: { ...grid.rows, data: rows } };

    return (
        <div className="space-y-3" data-testid={`data-grid-${grid.name}`}>
            <GridToolbar grid={grid} state={state} selectedCount={selectingAll ? meta.total : selected.length} />

            <GridBulkBar
                grid={grid}
                state={state}
                selected={selected}
                selectingAll={selectingAll}
                onSelectAll={() => setSelectingAll(true)}
                onClear={() => {
                    setSelected([]);
                    setSelectingAll(false);
                }}
            />

            <GridTable
                grid={view}
                state={state}
                selected={selected}
                selectingAll={selectingAll}
                onToggleRow={toggleRow}
                onTogglePage={togglePage}
                onCellSaved={cellSaved}
            />

            {meta.last_page > 1 ? (
                <div className="flex items-center justify-between">
                    <span className="text-xs text-gray-500">
                        Showing {meta.from}–{meta.to} of {meta.total}
                    </span>
                    <nav className="flex items-center gap-1" aria-label="Pagination">
                        {grid.rows.links.map((link, i) => (
                            <button
                                key={i}
                                type="button"
                                disabled={!link.url || link.active}
                                onClick={() => state.goTo(link.url)}
                                className={`px-3 py-1.5 text-sm rounded-md transition-colors ${
                                    link.active ? 'bg-[var(--color-primary)] text-white font-semibold' : link.url ? 'text-gray-600 hover:bg-gray-100' : 'text-gray-300 cursor-not-allowed'
                                }`}
                            >
                                {link.label.replace(/&laquo;/g, '«').replace(/&raquo;/g, '»').replace(/<[^>]*>/g, '')}
                            </button>
                        ))}
                    </nav>
                </div>
            ) : (
                meta.total > 0 && (
                    <span className="text-xs text-gray-500">
                        {meta.total} {meta.total === 1 ? 'record' : 'records'}
                    </span>
                )
            )}
        </div>
    );
}
