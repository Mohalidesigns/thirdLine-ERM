import { useCallback, useEffect, useState } from 'react';
import Menu from '@/Components/DataGrid/Menu';
import { rendererFor } from '@/widgets/renderers';

function toQuery(request) {
    const params = new URLSearchParams();
    if (request?.node) params.set('node', request.node);
    Object.entries(request?.filters || {}).forEach(([k, v]) => v !== null && v !== undefined && v !== '' && params.set(`filters[${k}]`, v));
    Object.entries(request?.overrides || {}).forEach(([k, v]) => v !== null && v !== undefined && v !== '' && params.set(`overrides[${k}]`, v));
    const s = params.toString();
    return s ? `?${s}` : '';
}

/**
 * One dashboard panel (migration Phase 2: the Livewire WidgetPanel).
 *
 * The chrome — title, meta line, ⋮ menu — is shared by every widget type; the
 * body dispatches on `type`. The initial envelope arrives with the page; Refresh
 * and the register widget's search/paging re-fetch it from urls.payload with
 * the runtime filters merged in.
 */
export default function Widget({ payload: initial }) {
    const [payload, setPayload] = useState(initial);
    const [loading, setLoading] = useState(false);

    useEffect(() => setPayload(initial), [initial]);

    const request = payload?.request || initial?.request || {};

    const load = useCallback(
        async (patch = {}) => {
            const url = payload?.urls?.payload || initial?.urls?.payload;
            if (!url) return;
            const next = { ...request, filters: { ...(request.filters || {}), ...patch } };
            setLoading(true);
            try {
                const res = await window.axios.get(url + toQuery(next), { headers: { Accept: 'application/json' } });
                setPayload({ ...res.data, request: next });
            } catch (e) {
                if (import.meta.env?.DEV) console.warn('[widget] refresh failed', e);
                setPayload((p) => ({ ...p, state: 'error' }));
            } finally {
                setLoading(false);
            }
        },
        [payload, initial, request],
    );

    const state = payload?.state ?? 'error';
    const type = payload?.type ?? 'missing';
    const meta = payload?.meta || {};
    const asOf = payload?.data?.as_of;
    const Renderer = rendererFor(type);
    const exportUrl = payload?.urls?.export ? payload.urls.export + toQuery(request) : null;

    return (
        <div className="widget-panel flex h-full flex-col rounded-lg border border-gray-200 bg-white shadow-sm" data-widget-code={payload?.code ?? ''}>
            <div className="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold text-gray-800">{payload?.title ?? 'Widget'}</h3>
                    {(meta.period || meta.node) && (
                        <p className="truncate text-xs text-gray-400">
                            {meta.node ?? ''}
                            {meta.period && meta.node ? ' · ' : ''}
                            {meta.period ?? ''}
                            {asOf && <span className="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">as at {asOf}</span>}
                        </p>
                    )}
                </div>

                <Menu
                    width="w-44"
                    button={({ toggle }) => (
                        <button type="button" onClick={toggle} className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Widget menu">
                            <span className={`material-symbols-outlined text-[20px] leading-none ${loading ? 'animate-spin' : ''}`}>{loading ? 'progress_activity' : 'more_vert'}</span>
                        </button>
                    )}
                >
                    {({ close }) => (
                        <>
                            <button type="button" onClick={() => { close(); load(); }} className="flex w-full items-center gap-2 rounded px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                <span className="material-symbols-outlined text-[18px]">refresh</span> Refresh
                            </button>
                            {exportUrl && (
                                <a href={exportUrl} onClick={close} className="flex w-full items-center gap-2 rounded px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                    <span className="material-symbols-outlined text-[18px]">download</span> Export
                                </a>
                            )}
                            {payload?.urls?.drill && (
                                <a href={payload.urls.drill} className="flex w-full items-center gap-2 rounded px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                    <span className="material-symbols-outlined text-[18px]">zoom_in</span> Drill down
                                </a>
                            )}
                        </>
                    )}
                </Menu>
            </div>

            <div className="min-h-0 flex-1 overflow-auto p-3">
                {state === 'forbidden' ? (
                    <div className="flex h-full flex-col items-center justify-center gap-1 py-6 text-gray-400">
                        <span className="material-symbols-outlined text-3xl">lock</span>
                        <p className="text-xs">You do not have permission to view this data.</p>
                    </div>
                ) : state !== 'ok' || !Renderer ? (
                    <div className="flex h-full flex-col items-center justify-center gap-1 py-6 text-gray-400">
                        <span className="material-symbols-outlined text-3xl">error_outline</span>
                        <p className="text-xs">This widget could not be rendered.</p>
                    </div>
                ) : (
                    <Renderer type={type} data={payload.data || {}} envelope={payload} onFilters={load} />
                )}
            </div>
        </div>
    );
}
