import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Menu from './Menu';

const btn = 'px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-1.5 bg-white';

export default function GridToolbar({ grid, state, selectedCount }) {
    const [term, setTerm] = useState(grid.state.search || '');
    const [viewName, setViewName] = useState('');
    const timer = useRef(null);
    const first = useRef(true);

    useEffect(() => {
        setTerm(grid.state.search || '');
    }, [grid.state.search]);

    // 350 ms debounce, matching the Livewire grid; skipped on mount.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return undefined;
        }
        if (term === (grid.state.search || '')) return undefined;
        clearTimeout(timer.current);
        timer.current = setTimeout(() => state.update({ search: term }), 350);
        return () => clearTimeout(timer.current);
    }, [term]); // eslint-disable-line react-hooks/exhaustive-deps

    const activeFilters = Object.values(grid.state.filters || {}).filter((v) => v !== '' && v != null).length;
    const dirty = (grid.state.search || '') !== '' || activeFilters > 0;
    const currentView = grid.views.find((v) => v.id === grid.state.viewId);

    const saveView = (asDefault, close) => {
        const name = viewName.trim();
        if (!name) return;
        router.post(grid.urls.views, { name, as_default: asDefault, ...state.query() }, {
            preserveScroll: true,
            onSuccess: () => {
                setViewName('');
                close();
            },
        });
    };

    const deleteView = (id) => {
        router.delete(grid.urls.view.replace('__view__', id), { preserveScroll: true });
    };

    const exportUrl = (base) => {
        if (selectedCount === 0) return base;
        return base; // selection is appended by GridBulkBar's export links
    };

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-3 flex flex-wrap items-center gap-2" data-testid="grid-toolbar">
            <div className="relative flex-1 min-w-[200px]">
                <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-lg" aria-hidden="true">search</span>
                <label className="sr-only" htmlFor={`grid-search-${grid.name}`}>Search</label>
                <input
                    id={`grid-search-${grid.name}`}
                    type="search"
                    value={term}
                    onChange={(e) => setTerm(e.target.value)}
                    placeholder="Search…"
                    className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:border-[var(--color-primary)]"
                />
            </div>

            {grid.filters.map((filter) => (
                <div key={filter.key}>
                    <label className="sr-only" htmlFor={`grid-filter-${filter.key}`}>{filter.label}</label>
                    <select
                        id={`grid-filter-${filter.key}`}
                        value={filter.value || ''}
                        onChange={(e) => state.update({ filters: { [filter.key]: e.target.value } })}
                        className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700 bg-white"
                    >
                        <option value="">{filter.label}</option>
                        {filter.options.map((o) => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>
                </div>
            ))}

            {dirty && (
                <button type="button" onClick={state.clear} className="text-xs text-[var(--color-primary)] font-medium hover:underline">
                    Clear
                </button>
            )}

            <div className="ml-auto flex items-center gap-2">
                {/* Saved views */}
                <Menu
                    width="w-64"
                    button={({ toggle, open }) => (
                        <button type="button" onClick={toggle} aria-expanded={open} className={btn}>
                            <span className="material-symbols-outlined text-lg" aria-hidden="true">bookmark</span>
                            <span className="hidden lg:inline">{currentView?.name ?? 'Views'}</span>
                        </button>
                    )}
                >
                    {({ close }) => (
                        <div className="space-y-1">
                            {grid.views.length === 0 && <p className="px-2 py-1.5 text-xs text-gray-400">No saved views yet.</p>}
                            {grid.views.map((view) => (
                                <div key={view.id} className={`flex items-center gap-1 rounded-lg px-2 py-1.5 hover:bg-gray-50 ${grid.state.viewId === view.id ? 'bg-blue-50/60' : ''}`}>
                                    <button type="button" onClick={() => { state.applyView(view.id); close(); }} className="flex-1 text-left text-sm text-gray-700">
                                        {view.name}
                                        {view.isDefault && <span className="text-[10px] text-gray-400 uppercase ml-1">default</span>}
                                    </button>
                                    <button type="button" onClick={() => deleteView(view.id)} className="p-0.5 rounded hover:bg-gray-100" aria-label={`Delete view ${view.name}`}>
                                        <span className="material-symbols-outlined text-gray-400 text-base" aria-hidden="true">delete</span>
                                    </button>
                                </div>
                            ))}
                            <div className="border-t border-gray-100 pt-2 mt-1 space-y-1.5">
                                <label className="sr-only" htmlFor={`grid-view-name-${grid.name}`}>View name</label>
                                <input
                                    id={`grid-view-name-${grid.name}`}
                                    type="text"
                                    value={viewName}
                                    onChange={(e) => setViewName(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && saveView(false, close)}
                                    placeholder="Save current as…"
                                    className="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-xs"
                                />
                                <div className="flex gap-1.5">
                                    <button type="button" onClick={() => saveView(false, close)} className="flex-1 px-2 py-1.5 bg-[var(--color-primary)] text-white rounded-lg text-xs font-medium hover:opacity-90">Save</button>
                                    <button type="button" onClick={() => saveView(true, close)} className="flex-1 px-2 py-1.5 border border-gray-300 rounded-lg text-xs text-gray-700 hover:bg-gray-50">Save as default</button>
                                </div>
                            </div>
                        </div>
                    )}
                </Menu>

                {/* Column chooser */}
                <Menu
                    width="w-56"
                    button={({ toggle, open }) => (
                        <button type="button" onClick={toggle} aria-expanded={open} className={btn}>
                            <span className="material-symbols-outlined text-lg" aria-hidden="true">view_column</span>
                            <span className="hidden lg:inline">Columns</span>
                        </button>
                    )}
                >
                    <div className="max-h-80 overflow-y-auto">
                        {grid.columns.map((column) => {
                            const visible = grid.state.columns.includes(column.key);
                            const last = visible && grid.state.columns.length === 1;
                            return (
                                <label key={column.key} className="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={visible}
                                        disabled={last}
                                        onChange={() => {
                                            const next = visible
                                                ? grid.state.columns.filter((k) => k !== column.key)
                                                : grid.columns.map((c) => c.key).filter((k) => k === column.key || grid.state.columns.includes(k));
                                            state.update({ columns: next }, { resetPage: false });
                                        }}
                                        className="form-checkbox rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]/30"
                                    />
                                    <span className="text-sm text-gray-700">{column.label}</span>
                                </label>
                            );
                        })}
                    </div>
                </Menu>

                {/* Export — plain anchors: these are file downloads */}
                <Menu
                    width="w-44"
                    button={({ toggle, open }) => (
                        <button type="button" onClick={toggle} aria-expanded={open} className={btn}>
                            <span className="material-symbols-outlined text-lg" aria-hidden="true">download</span>
                            <span className="hidden lg:inline">Export</span>
                        </button>
                    )}
                >
                    <a href={exportUrl(grid.urls.csv)} className="block w-full text-left px-3 py-2 text-sm text-gray-700 rounded-lg hover:bg-gray-50">CSV</a>
                    <a href={exportUrl(grid.urls.xlsx)} className="block w-full text-left px-3 py-2 text-sm text-gray-700 rounded-lg hover:bg-gray-50">Excel</a>
                </Menu>

                <label className="sr-only" htmlFor={`grid-per-page-${grid.name}`}>Rows per page</label>
                <select
                    id={`grid-per-page-${grid.name}`}
                    value={grid.state.perPage}
                    onChange={(e) => state.update({ per_page: Number(e.target.value) })}
                    className="border border-gray-300 rounded-lg px-2 py-2 text-sm text-gray-700 bg-white"
                >
                    {grid.perPageOptions.map((n) => (
                        <option key={n} value={n}>{n}/page</option>
                    ))}
                </select>
            </div>
        </div>
    );
}
