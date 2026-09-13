import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

/**
 * URL-synced state for one DataGrid.
 *
 * The grid's state lives in the page URL (search, filters[], sort, dir,
 * per_page, columns, view, page) and the server (App\Grids\GridState) is the
 * authority on what it means. Every change is a partial Inertia reload of
 * the page prop that holds the grid, so the page around it keeps its own
 * state and scroll position.
 */
export function useGridState(grid, propName = 'grid') {
    const [loading, setLoading] = useState(false);

    const current = () => ({
        search: grid.state.search || '',
        filters: { ...(grid.state.filters || {}) },
        sort: grid.state.sort,
        dir: grid.state.dir,
        per_page: grid.state.perPage,
        columns: grid.state.columns,
        view: grid.state.viewId ?? undefined,
    });

    const visit = useCallback(
        (params, extra = {}) => {
            const query = {};
            for (const [key, value] of Object.entries(params)) {
                if (value === undefined || value === null) continue;
                if (key === 'filters') {
                    const clean = Object.fromEntries(Object.entries(value).filter(([, v]) => v !== '' && v != null));
                    if (Object.keys(clean).length > 0) query.filters = clean;
                    continue;
                }
                if (key === 'columns') {
                    if (Array.isArray(value) && value.length > 0) query.columns = value.join(',');
                    continue;
                }
                if (value === '') continue;
                query[key] = value;
            }

            router.get(window.location.pathname, query, {
                only: [propName],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
                ...extra,
            });
        },
        [propName],
    );

    /** Apply a partial change; most changes go back to page 1. */
    const update = useCallback(
        (patch, { resetPage = true } = {}) => {
            const next = { ...current(), ...patch };
            if (patch.filters) next.filters = { ...current().filters, ...patch.filters };
            if (resetPage) next.page = undefined;
            // Touching the toolbar leaves a named view: the view's own state
            // is now merely the starting point.
            if (!('view' in patch)) next.view = undefined;
            visit(next);
        },
        [visit], // eslint-disable-line react-hooks/exhaustive-deps
    );

    const clear = useCallback(() => visit({ sort: grid.state.sort, dir: grid.state.dir, per_page: grid.state.perPage, columns: grid.state.columns }), [visit, grid]);

    const applyView = useCallback((id) => visit({ view: id }), [visit]);

    const goTo = useCallback(
        (url) => {
            if (!url) return;
            router.get(url, {}, {
                only: [propName],
                preserveState: true,
                preserveScroll: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            });
        },
        [propName],
    );

    const reload = useCallback(() => visit(current(), { replace: true }), [visit]); // eslint-disable-line react-hooks/exhaustive-deps

    return { loading, update, clear, applyView, goTo, reload, query: current };
}
