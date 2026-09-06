import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import Menu from './Menu';

function Cell({ column, cell }) {
    const text = cell.text ?? '—';

    if (cell.href) {
        // Plain anchor: record pages are Blade until their module is ported.
        return (
            <a href={cell.href} className="font-medium text-[var(--color-primary)] hover:underline">
                {text}
            </a>
        );
    }

    switch (column.type) {
        case 'badge':
        case 'rag':
            return <span className={`badge ${cell.class || 'bg-gray-100 text-gray-600'}`}>{text}</span>;
        case 'date':
        case 'datetime':
            return <span className="text-xs text-gray-500">{text}</span>;
        case 'money':
            return <span className="text-xs tabular-nums">{text}</span>;
        case 'progress':
            return (
                <div className="flex items-center gap-2 min-w-[90px]">
                    <div className="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div className={`h-full rounded-full ${cell.pct >= 100 ? 'bg-green-500' : 'bg-[var(--color-primary)]'}`} style={{ width: `${cell.pct}%` }} />
                    </div>
                    <span className="text-[10px] text-gray-500 tabular-nums">{cell.pct}%</span>
                </div>
            );
        case 'count':
            return <span className="font-medium text-[var(--color-primary)]">{text}</span>;
        default:
            return <span className="text-xs">{text}</span>;
    }
}

function EditableCell({ grid, column, row, onSaved }) {
    const cell = row.cells[column.key];
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(cell.raw ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const input = useRef(null);

    useEffect(() => {
        if (editing) input.current?.focus();
    }, [editing]);

    useEffect(() => {
        setValue(cell.raw ?? '');
    }, [cell.raw]);

    const save = async (next) => {
        setSaving(true);
        setError(null);
        try {
            const { data } = await axios.post(grid.urls.cell, { id: row.id, key: column.key, value: next });
            onSaved(row.id, column.key, data.cell);
            setEditing(false);
        } catch (e) {
            setError(e.response?.data?.message || 'Could not save.');
            setValue(cell.raw ?? '');
        } finally {
            setSaving(false);
        }
    };

    if (!editing) {
        return (
            <span className="inline-flex items-center gap-1">
                <Cell column={column} cell={cell} />
                <button type="button" onClick={() => setEditing(true)} className="p-0.5 rounded hover:bg-gray-100 align-middle" aria-label={`Edit ${column.label}`}>
                    <span className="material-symbols-outlined text-gray-300 text-sm hover:text-gray-500" aria-hidden="true">edit</span>
                </button>
                {error && <span className="text-[10px] text-red-600">{error}</span>}
            </span>
        );
    }

    if (column.editable.type === 'select') {
        return (
            <select
                ref={input}
                value={value}
                disabled={saving}
                onChange={(e) => { setValue(e.target.value); save(e.target.value); }}
                onKeyDown={(e) => e.key === 'Escape' && setEditing(false)}
                onBlur={() => !saving && setEditing(false)}
                className="border border-[var(--color-primary)] rounded-lg px-2 py-1 text-xs bg-white"
            >
                {column.editable.options.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                ))}
            </select>
        );
    }

    return (
        <input
            ref={input}
            type="text"
            value={value}
            disabled={saving}
            onChange={(e) => setValue(e.target.value)}
            onKeyDown={(e) => {
                if (e.key === 'Enter') save(value);
                if (e.key === 'Escape') setEditing(false);
            }}
            onBlur={() => save(value)}
            className="border border-[var(--color-primary)] rounded-lg px-2 py-1 text-xs w-full"
        />
    );
}

export default function GridTable({ grid, state, selected, selectingAll, onToggleRow, onTogglePage, onCellSaved }) {
    const columns = grid.columns.filter((c) => grid.state.columns.includes(c.key));
    const rows = grid.rows.data;
    const hasBulk = grid.selection.mode === 'multiple';
    const hasActions = rows.some((r) => r.actions.length > 0);
    const pageIds = rows.map((r) => r.id);
    const pageAllSelected = pageIds.length > 0 && pageIds.every((id) => selectingAll || selected.includes(id));
    const dirty = (grid.state.search || '') !== '' || Object.values(grid.state.filters || {}).some((v) => v !== '' && v != null);

    if (rows.length === 0) {
        return (
            <div className="relative bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="text-center py-14 px-6">
                    <span className="material-symbols-outlined text-4xl text-gray-300 mb-2 block" aria-hidden="true">{grid.emptyIcon}</span>
                    <p className="text-sm text-gray-500">{grid.emptyMessage}</p>
                    {dirty && (
                        <button type="button" onClick={state.clear} className="mt-3 text-xs text-[var(--color-primary)] font-medium hover:underline">
                            Clear search &amp; filters
                        </button>
                    )}
                </div>
            </div>
        );
    }

    const sortIcon = (column) => (grid.state.sort === column.key && grid.state.dir === 'desc' ? 'arrow_downward' : 'arrow_upward');

    return (
        <div className={`relative bg-white rounded-xl border border-gray-200 overflow-hidden ${state.loading ? 'opacity-60' : ''}`} aria-busy={state.loading}>
            <div className="overflow-x-auto">
                <table className="w-full text-sm" data-testid="grid-table">
                    <thead>
                        <tr className="bg-gray-50 border-b border-gray-200 text-left">
                            {hasBulk && (
                                <th scope="col" className="w-10 px-4 py-3">
                                    <input type="checkbox" aria-label="Select page" checked={pageAllSelected} onChange={() => onTogglePage(pageIds)} className="form-checkbox rounded border-gray-300 text-[var(--color-primary)]" />
                                </th>
                            )}
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    className="px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide whitespace-nowrap"
                                    aria-sort={grid.state.sort === column.key ? (grid.state.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                                >
                                    {column.sortable ? (
                                        <button
                                            type="button"
                                            onClick={() => state.update({ sort: column.key, dir: grid.state.sort === column.key && grid.state.dir === 'asc' ? 'desc' : 'asc' })}
                                            className="flex items-center gap-1 hover:text-[var(--color-primary)]"
                                        >
                                            {column.label}
                                            <span className={`material-symbols-outlined text-sm ${grid.state.sort === column.key ? 'text-[var(--color-primary)]' : 'text-gray-300'}`} aria-hidden="true">
                                                {sortIcon(column)}
                                            </span>
                                        </button>
                                    ) : (
                                        column.label
                                    )}
                                </th>
                            ))}
                            {hasActions && (
                                <th scope="col" className="w-12 px-4 py-3">
                                    <span className="sr-only">Actions</span>
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((row) => {
                            const isSelected = selectingAll || selected.includes(row.id);
                            return (
                                <tr
                                    key={row.id}
                                    tabIndex={0}
                                    onKeyDown={(e) => e.key === 'Enter' && row.href && (window.location.href = row.href)}
                                    className={`hover:bg-blue-50/50 focus:outline-none focus:bg-blue-50 ${isSelected ? 'bg-blue-50/60' : ''}`}
                                >
                                    {hasBulk && (
                                        <td className="px-4 py-2.5">
                                            <input type="checkbox" aria-label="Select row" checked={isSelected} onChange={() => onToggleRow(row.id)} className="form-checkbox rounded border-gray-300 text-[var(--color-primary)]" />
                                        </td>
                                    )}
                                    {columns.map((column) => (
                                        <td key={column.key} className="px-4 py-2.5">
                                            {column.editable ? (
                                                <EditableCell grid={grid} column={column} row={row} onSaved={onCellSaved} />
                                            ) : (
                                                <Cell column={column} cell={row.cells[column.key]} />
                                            )}
                                        </td>
                                    ))}
                                    {hasActions && (
                                        <td className="px-4 py-2.5 text-right">
                                            {row.actions.length > 0 && (
                                                <Menu
                                                    width="w-40"
                                                    className="inline-block"
                                                    button={({ toggle, open }) => (
                                                        <button type="button" onClick={toggle} aria-expanded={open} aria-label="Row actions" className="p-1 rounded hover:bg-gray-100">
                                                            <span className="material-symbols-outlined text-gray-400 text-lg" aria-hidden="true">more_vert</span>
                                                        </button>
                                                    )}
                                                >
                                                    {row.actions.map((action) => (
                                                        <a key={action.label} href={action.url} className="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 rounded-lg hover:bg-gray-50">
                                                            <span className="material-symbols-outlined text-base text-gray-400" aria-hidden="true">{action.icon}</span>
                                                            {action.label}
                                                        </a>
                                                    ))}
                                                </Menu>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
