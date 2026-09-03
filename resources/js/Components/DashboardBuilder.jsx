import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Menu from '@/Components/DataGrid/Menu';

const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

/* Inertia round-trips for every editor action: the server owns the layout
   truth (DashboardEditor), the response re-renders the page with fresh props. */
function post(url, data = {}, options = {}) {
    router.post(url, data, { preserveScroll: true, preserveState: true, ...options });
}
function patch(url, data = {}) {
    router.patch(url, data, { preserveScroll: true, preserveState: true });
}
function destroy(url) {
    router.delete(url, { preserveScroll: true, preserveState: true });
}

/**
 * The dashboard builder (migration Phase 2: the Livewire DashboardBuilder +
 * widgets/builder.js). GridStack is loaded on demand and owns drag/resize only;
 * every change is posted to urls.layout as [{position,x,y,w,h}] — the shape
 * builder.js always sent.
 */
export default function DashboardBuilder({ dashboard, tabs, activeTab, palette, placedWidgetIds, roles, objectTypes, binding, urls }) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('');
    const [renaming, setRenaming] = useState(null);
    const [name, setName] = useState(dashboard.name);
    const gridHost = useRef(null);
    const gridRef = useRef(null);

    useEffect(() => setName(dashboard.name), [dashboard.name]);

    const current = tabs.find((t) => t.code === activeTab) || tabs[0] || { code: '', label: '', layout: [] };
    const placed = useMemo(() => new Set((placedWidgetIds || []).map(Number)), [placedWidgetIds]);
    const categories = useMemo(() => palette.map((g) => g.category), [palette]);

    const filteredPalette = useMemo(() => {
        const q = search.trim().toLowerCase();
        return palette
            .filter((g) => category === '' || g.category === category)
            .map((g) => ({
                ...g,
                widgets: g.widgets.filter((w) => q === '' || w.name.toLowerCase().includes(q) || (w.description || '').toLowerCase().includes(q) || w.type.includes(q)),
            }))
            .filter((g) => g.widgets.length > 0);
    }, [palette, search, category]);

    const tabUrl = (code) => `${window.location.pathname}?tab=${encodeURIComponent(code)}`;
    const tabsUrl = (code, suffix = '') => `${urls.tabs}/${encodeURIComponent(code)}${suffix}`;

    /* GridStack: rebuilt whenever the item set changes (tab switch, add,
       remove) — it cannot morph, so React renders the items and GridStack is
       (re)initialised over them afterwards. */
    const layoutKey = `${current.code}:${current.layout.map((p) => `${p.position}-${p.widget_id}-${p.x}-${p.y}-${p.w}-${p.h}`).join('|')}`;
    useEffect(() => {
        let disposed = false;
        const host = gridHost.current;
        if (!host) return undefined;

        (async () => {
            const { GridStack } = await import('gridstack');
            await import('gridstack/dist/gridstack.min.css');
            if (disposed || !gridHost.current) return;

            gridRef.current?.destroy(false);
            gridRef.current = GridStack.init({ column: 12, cellHeight: 92, margin: 8, float: false, animate: true }, host);
            gridRef.current.on('change', () => {
                const items = gridRef.current.engine.nodes.map((node) => ({
                    position: parseInt(node.el?.dataset.position ?? '-1', 10),
                    x: node.x, y: node.y, w: node.w, h: node.h,
                }));
                window.axios.post(urls.layout, { tab: current.code, items }).catch((e) => {
                    if (import.meta.env?.DEV) console.warn('[builder] layout save failed', e);
                });
            });
        })();

        return () => {
            disposed = true;
            gridRef.current?.destroy(false);
            gridRef.current = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [layoutKey]);

    const canPublish = !dashboard.isPublished || dashboard.hasUnpublishedChanges;
    const blocked = binding !== null && !binding.renderable;
    const roleCount = dashboard.roleIds.length;

    const toggleRole = (id) => {
        const next = dashboard.roleIds.includes(id) ? dashboard.roleIds.filter((r) => r !== id) : [...dashboard.roleIds, id];
        patch(urls.update, { role_ids: next });
    };

    return (
        <div className="flex gap-4">
            {/* ───────── Widget library ───────── */}
            <aside className="w-72 shrink-0">
                <div className="sticky top-20 flex max-h-[calc(100vh-6rem)] flex-col rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div className="border-b border-gray-100 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Widget library</div>
                    <div className="border-b border-gray-100 p-2">
                        <div className="relative">
                            <span className="material-symbols-outlined pointer-events-none absolute left-2 top-1.5 text-[18px] text-gray-400">search</span>
                            <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search widgets" aria-label="Search widgets"
                                className="form-input w-full rounded-md border-gray-200 py-1.5 pl-8 pr-2 text-xs focus:border-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1">
                            {['', ...categories].map((c) => (
                                <button key={c || 'all'} type="button" onClick={() => setCategory(c)}
                                    className={`rounded-full border px-2 py-0.5 text-[11px] ${category === c ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400'}`}>
                                    {c || 'All'}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="min-h-0 flex-1 overflow-auto">
                        {filteredPalette.length === 0 ? (
                            <p className="px-3 py-8 text-center text-xs text-gray-400">{search !== '' ? `No widgets match “${search}”.` : 'No widgets in this category.'}</p>
                        ) : filteredPalette.map((group) => (
                            <div key={group.category}>
                                <div className="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{group.category}</div>
                                <ul className="divide-y divide-gray-50">
                                    {group.widgets.map((w) => {
                                        const isPlaced = placed.has(Number(w.id));
                                        return (
                                            <li key={w.id}>
                                                <button type="button" onClick={() => post(tabsUrl(current.code, '/widgets'), { widget_id: w.id })}
                                                    className="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-gray-50"
                                                    title={isPlaced ? 'Already on this dashboard — click to add another' : 'Add to the active tab'}>
                                                    <span className={`material-symbols-outlined mt-px shrink-0 text-[16px] ${isPlaced ? 'text-emerald-500' : 'text-gray-400'}`}>{isPlaced ? 'check_circle' : 'add_box'}</span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-xs font-medium text-gray-700">{w.name}</span>
                                                        <span className="block truncate text-[10px] text-gray-400">{w.description}</span>
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>
            </aside>

            <div className="min-w-0 flex-1">
                {/* ───────── Meta bar ───────── */}
                <div className="mb-3 rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                        <input type="text" value={name} onChange={(e) => setName(e.target.value)} onBlur={() => name !== dashboard.name && patch(urls.update, { name })}
                            aria-label="Dashboard name"
                            className="form-input w-64 rounded-md border-gray-200 text-sm font-semibold focus:border-[var(--color-primary)] focus:ring-[var(--color-primary)]" />

                        <div className="flex items-center gap-1.5 text-xs text-gray-500">
                            <span>Shows on</span>
                            <select value={dashboard.objectTypeId ?? ''} onChange={(e) => patch(urls.update, { object_type_id: e.target.value === '' ? null : Number(e.target.value) })}
                                aria-label="Object type" className="form-select rounded-md border-gray-200 text-xs">
                                {objectTypes.map((t) => (
                                    <option key={t.id ?? 'any'} value={t.id ?? ''}>
                                        {t.name}{!t.is_node_type ? ' — not a node type' : t.node_count === 0 ? ' — no nodes yet' : ` (${t.node_count})`}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <Menu align="left" width="w-56" button={({ toggle }) => (
                            <button type="button" onClick={toggle} className="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                                <span className="material-symbols-outlined text-[15px]">group</span>
                                {roleCount === 0 ? 'All roles' : plural(roleCount, 'role')}
                            </button>
                        )}>
                            {roles.map((role) => (
                                <label key={role.id} className="flex items-center gap-2 rounded px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                    <input type="checkbox" checked={dashboard.roleIds.includes(role.id)} onChange={() => toggleRole(role.id)}
                                        className="form-checkbox rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                                    {role.name}
                                </label>
                            ))}
                        </Menu>

                        <span className="flex-1" />

                        {dashboard.isPublished && dashboard.hasUnpublishedChanges ? (
                            <>
                                <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-1 text-[10px] font-semibold text-blue-700"><span className="material-symbols-outlined text-[13px]">edit</span> Unpublished changes</span>
                                <span className="text-[11px] text-gray-400">v{dashboard.version} is live</span>
                            </>
                        ) : dashboard.isPublished ? (
                            <>
                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-700"><span className="material-symbols-outlined text-[13px]">check_circle</span> Published</span>
                                <span className="text-[11px] text-gray-400">v{dashboard.version}</span>
                            </>
                        ) : (
                            <>
                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-700">Draft</span>
                                <span className="text-[11px] text-gray-400">not live</span>
                            </>
                        )}

                        {binding?.previewUrl && (
                            <Link href={binding.previewUrl} className="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50" title={`See this draft on a real ${binding.name} node, with real data`}>
                                <span className="material-symbols-outlined text-[15px]">visibility</span> Preview
                            </Link>
                        )}

                        <button type="button" onClick={() => post(urls.duplicate)} className="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                            <span className="material-symbols-outlined text-[15px]">content_copy</span> Save as template
                        </button>

                        {dashboard.isPublished && dashboard.hasUnpublishedChanges ? (
                            <button type="button" onClick={() => window.confirm(`Discard your unpublished changes and go back to the live v${dashboard.version}?`) && post(urls.discard)}
                                className="rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">Discard changes</button>
                        ) : dashboard.isPublished ? (
                            <button type="button" onClick={() => window.confirm(`Unpublish? Business HQ will stop showing this on every ${binding?.name ?? 'matching'} node.`) && post(urls.unpublish)}
                                className="rounded-md border border-gray-200 px-2.5 py-1.5 text-xs text-gray-600 hover:bg-gray-50">Unpublish</button>
                        ) : null}

                        <button type="button" disabled={!canPublish || blocked} onClick={() => post(urls.publish)}
                            title={blocked ? 'This binding reaches no nodes — nobody would see it.' : canPublish ? 'Copy the draft to Business HQ' : 'Nothing to publish'}
                            className={`inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-xs font-semibold ${canPublish && !blocked ? 'bg-[var(--color-primary)] text-white hover:opacity-90' : 'cursor-not-allowed border border-gray-200 bg-gray-50 text-gray-400'}`}>
                            <span className="material-symbols-outlined text-[15px]">publish</span>
                            {dashboard.isPublished ? 'Publish changes' : 'Publish'}
                        </button>
                    </div>

                    {binding && (
                        <div className={`border-t border-gray-100 px-4 py-2 text-[11px] ${binding.renderable ? 'text-gray-500' : 'bg-amber-50 text-amber-800'}`}>
                            {!binding.is_node_type ? (
                                <><span className="material-symbols-outlined align-[-3px] text-[14px]">warning</span> <b>{binding.name}</b> is not a node type, so Business HQ has no page to render this on. Bind it to a type that appears in the organisation tree.</>
                            ) : binding.node_count === 0 ? (
                                <><span className="material-symbols-outlined align-[-3px] text-[14px]">warning</span> No <b>{binding.name}</b> nodes exist yet — publishing this would put it nowhere.</>
                            ) : (
                                <>
                                    Renders on <b>{plural(binding.node_count, 'node')}</b>{' '}
                                    {binding.id !== null ? <>of type <b>{binding.name}</b></> : '(every node with no dashboard of its own)'}
                                    {' · '}visible to {roleCount === 0 ? 'every role' : plural(roleCount, 'role')}
                                    {dashboard.isPublished && <> · Business HQ is serving v{dashboard.version} with {plural(dashboard.publishedWidgetCount, 'widget')}</>}
                                    {binding.published && binding.published.id !== dashboard.id && (
                                        <span className="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-amber-800">“{binding.published.name}” is already published for this type — whichever matches the viewer's role wins.</span>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                </div>

                {/* ───────── Tab strip ───────── */}
                <div className="mb-3 flex items-center gap-1 overflow-x-auto rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
                    {tabs.map((tab) => {
                        const active = tab.code === current.code;
                        return (
                            <div key={tab.code} className={`group flex items-center ${active ? 'rounded-md bg-[var(--color-primary)] text-white' : 'text-gray-600'}`}>
                                {renaming === tab.code ? (
                                    <input autoFocus type="text" defaultValue={tab.label} aria-label="Tab name"
                                        onKeyDown={(e) => { if (e.key === 'Enter') { patch(tabsUrl(tab.code), { label: e.target.value }); setRenaming(null); } if (e.key === 'Escape') setRenaming(null); }}
                                        onBlur={(e) => { if (e.target.value !== tab.label) patch(tabsUrl(tab.code), { label: e.target.value }); setRenaming(null); }}
                                        className="form-input mx-2 my-1 w-28 rounded border-0 bg-white/20 px-1 py-0 text-xs text-inherit" />
                                ) : (
                                    <Link href={tabUrl(tab.code)} preserveScroll onDoubleClick={(e) => { e.preventDefault(); setRenaming(tab.code); }}
                                        className={`flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium ${active ? '' : 'rounded-md hover:bg-gray-100'}`}>
                                        <span>{tab.label}</span>
                                        <span className={`rounded-full px-1.5 text-[10px] ${active ? 'bg-white/25' : 'bg-gray-100 text-gray-500'}`}>{tab.layout.length}</span>
                                    </Link>
                                )}
                                {tabs.length > 1 && (
                                    <button type="button" onClick={() => window.confirm(`Delete “${tab.label}” and its ${tab.layout.length} widget(s)?`) && destroy(tabsUrl(tab.code))}
                                        className="pr-2 opacity-0 hover:!opacity-100 group-hover:opacity-70" title="Remove tab">
                                        <span className="material-symbols-outlined text-[13px] leading-none">close</span>
                                    </button>
                                )}
                            </div>
                        );
                    })}
                    <button type="button" onClick={() => post(urls.tabs, { label: 'New tab' })} className="ml-1 rounded-md px-2 py-1.5 text-gray-400 hover:bg-gray-100" title="Add tab">
                        <span className="material-symbols-outlined text-[16px] leading-none">add</span>
                    </button>
                    <span className="ml-auto pr-2 text-[10px] text-gray-400">Double-click a tab to rename · drag &amp; resize tiles below</span>
                </div>

                {/* ───────── The grid ───────── */}
                <div data-dashboard-builder>
                    <div key={layoutKey} ref={gridHost} className="grid-stack rounded-lg border border-dashed border-gray-300 bg-gray-50/60 p-1" data-builder-grid>
                        {current.layout.map((placement) => (
                            <div key={`${current.code}-${placement.position}`} className="grid-stack-item" data-position={placement.position}
                                gs-x={placement.x} gs-y={placement.y} gs-w={placement.w} gs-h={placement.h}
                                gs-min-w={placement.widget?.min_w ?? 2} gs-min-h={placement.widget?.min_h ?? 2}>
                                <div className="grid-stack-item-content !overflow-visible">
                                    <div className="flex h-full flex-col rounded-lg border border-gray-200 bg-white shadow-sm">
                                        <div className="flex items-center justify-between gap-1 border-b border-gray-100 px-3 py-2">
                                            <input type="text" defaultValue={placement.overrides?.title ?? ''} placeholder={placement.widget?.name ?? 'Missing widget'}
                                                onBlur={(e) => e.target.value !== (placement.overrides?.title ?? '') && patch(tabsUrl(current.code, `/widgets/${placement.position}`), { title: e.target.value })}
                                                className="form-input w-full truncate rounded border-0 p-0 text-xs font-semibold text-gray-800 placeholder-gray-400 focus:ring-0"
                                                title="Title override — blank uses the widget's own name" />
                                            <button type="button" onClick={() => destroy(tabsUrl(current.code, `/widgets/${placement.position}`))} className="shrink-0 text-gray-300 hover:text-red-500" title="Remove">
                                                <span className="material-symbols-outlined text-[16px] leading-none">delete</span>
                                            </button>
                                        </div>
                                        <div className="flex flex-1 flex-col items-center justify-center gap-1 p-3 text-gray-300">
                                            <span className="material-symbols-outlined text-2xl">insert_chart</span>
                                            <span className="text-[10px] uppercase tracking-wide">{(placement.widget?.type ?? '?').replace(/_/g, ' ')}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                    {current.layout.length === 0 && (
                        <p className="p-6 text-center text-xs text-gray-400">“{current.label || 'This tab'}” is empty. Add widgets from the library on the left.</p>
                    )}
                </div>
            </div>
        </div>
    );
}
