import { router } from '@inertiajs/react';

export default function GridBulkBar({ grid, selected, selectingAll, onSelectAll, onClear, state }) {
    const total = grid.rows.meta.total;
    const count = selectingAll ? total : selected.length;

    if (count === 0) return null;

    const run = (action) => {
        if (action.confirm && !window.confirm(action.confirm)) return;
        router.post(
            grid.urls.bulk.replace('__action__', action.key),
            selectingAll ? { all: true, ...state.query() } : { ids: selected },
            { preserveScroll: true, onSuccess: () => onClear() },
        );
    };

    return (
        <div className="bg-[var(--color-primary)] text-white rounded-xl px-4 py-2.5 flex flex-wrap items-center gap-3" data-testid="grid-bulk-bar">
            <span className="text-sm font-medium">{count} selected</span>
            {!selectingAll && total > selected.length && (
                <button type="button" onClick={onSelectAll} className="text-xs underline text-white/80 hover:text-white">
                    Select all {total} matching
                </button>
            )}
            <div className="ml-auto flex items-center gap-2">
                {grid.bulkActions.map((action) => (
                    <button key={action.key} type="button" onClick={() => run(action)} className="px-3 py-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-xs font-medium flex items-center gap-1.5">
                        <span className="material-symbols-outlined text-base" aria-hidden="true">{action.icon}</span>
                        {action.label}
                    </button>
                ))}
                <button type="button" onClick={onClear} className="p-1 rounded hover:bg-white/10" aria-label="Clear selection">
                    <span className="material-symbols-outlined text-lg" aria-hidden="true">close</span>
                </button>
            </div>
        </div>
    );
}
