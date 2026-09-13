import { useEffect, useState } from 'react';

const BADGE_COLUMNS = ['residual_rating', 'inherent_rating', 'priority', 'event_severity', 'current_status', 'issue_status', 'status'];

function formatValue(value) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'number' && !Number.isInteger(value)) return value.toFixed(1);
    if (typeof value === 'string' && value !== '' && !Number.isNaN(Number(value)) && !Number.isInteger(Number(value))) {
        return Number(value).toFixed(1);
    }
    return String(value);
}

/**
 * register — paginated table with search, inline +, per-row ⋮. Row links and
 * the + target were resolved server-side (WidgetPayloadPresenter). Search and
 * paging are the engine's runtime filters `_search` / `_page`, sent back
 * through the panel's onFilters.
 */
export default function Register({ data, envelope, onFilters }) {
    const columns = data.columns || [];
    const rows = data.rows || [];
    const page = Number(data.page || 1);
    const perPage = Math.max(1, Number(data.per_page || 10));
    const total = Number(data.total || 0);
    const lastPage = Math.ceil(total / perPage);
    const [search, setSearch] = useState(data.search || '');

    useEffect(() => setSearch(data.search || ''), [data.search]);

    const submitSearch = () => {
        if ((data.search || '') !== search) onFilters?.({ _search: search, _page: 1 });
    };

    return (
        <div className="flex h-full flex-col">
            <div className="mb-2 flex items-center gap-2">
                <div className="relative flex-1">
                    <span className="material-symbols-outlined pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[16px] text-gray-400">search</span>
                    <input
                        type="search"
                        value={search}
                        placeholder="Search…"
                        onChange={(e) => setSearch(e.target.value)}
                        onBlur={submitSearch}
                        onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                        className="form-input w-full rounded-md border-gray-200 py-1 pl-7 pr-2 text-xs focus:border-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                    />
                </div>
                {envelope?.urls?.create && (
                    <a href={envelope.urls.create} className="inline-flex items-center gap-1 rounded-md bg-[var(--color-primary)] px-2 py-1 text-xs font-medium text-white hover:opacity-90">
                        <span className="material-symbols-outlined text-[16px] leading-none">add</span> New
                    </a>
                )}
            </div>

            <div className="min-h-0 flex-1 overflow-auto">
                <table className="min-w-full divide-y divide-gray-100 text-xs">
                    <thead>
                        <tr className="text-left text-[10px] uppercase tracking-wide text-gray-400">
                            {columns.map((column) => (
                                <th key={column.key} className="px-2 py-1.5 font-medium">{column.label}</th>
                            ))}
                            <th className="w-8" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-50">
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length + 1} className="px-2 py-6 text-center text-gray-400">Nothing in scope.</td>
                            </tr>
                        )}
                        {rows.map((row, i) => (
                            <tr key={row.id ?? i} className="hover:bg-gray-50">
                                {columns.map((column, ci) => {
                                    const value = row[column.key];
                                    return (
                                        <td key={column.key} className="px-2 py-1.5 text-gray-700">
                                            {ci === 0 && row.url ? (
                                                <a href={row.url} className="font-medium text-[var(--color-primary)] hover:underline">{formatValue(value)}</a>
                                            ) : BADGE_COLUMNS.includes(column.key) && value !== null && value !== undefined ? (
                                                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-700">{value}</span>
                                            ) : (
                                                formatValue(value)
                                            )}
                                        </td>
                                    );
                                })}
                                <td className="px-1 text-right">
                                    {row.url && (
                                        <a href={row.url} className="text-gray-300 hover:text-gray-600" title="Open">
                                            <span className="material-symbols-outlined text-[16px] leading-none">more_vert</span>
                                        </a>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {lastPage > 1 && (
                <div className="mt-2 flex items-center justify-between text-[11px] text-gray-500">
                    <span>{total} rows</span>
                    <div className="flex items-center gap-1">
                        <button type="button" disabled={page <= 1} onClick={() => onFilters?.({ _page: page - 1 })} className="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:opacity-30">‹</button>
                        <span>{page} / {lastPage}</span>
                        <button type="button" disabled={page >= lastPage} onClick={() => onFilters?.({ _page: page + 1 })} className="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:opacity-30">›</button>
                    </div>
                </div>
            )}
        </div>
    );
}
