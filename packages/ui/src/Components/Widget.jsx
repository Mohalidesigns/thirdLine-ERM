import { useCallback, useEffect, useState } from 'react';
import Menu from './DataGrid/Menu';
import Icon from './Icon';
import { rendererFor } from '../widgets/renderers';

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
        <div className="widget-panel flex h-full flex-col rounded-xl border border-gray-100 bg-white shadow-sm" aria-busy={loading} data-widget-code={payload?.code ?? ''}>
            <div className="flex items-center justify-between gap-2 border-b border-gray-100 px-6 py-4">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold text-gray-800">{payload?.title ?? 'Widget'}</h3>
                    {(meta.period || meta.node) && (
                        <p className="truncate text-xs text-gray-500">
                            {meta.node ?? ''}
                            {meta.period && meta.node ? ' · ' : ''}
                            {meta.period ?? ''}
                            {asOf && <span className="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700">as at {asOf}</span>}
                        </p>
                    )}
                </div>

                <Menu
                    width="w-44"
                    button={({ toggle }) => (
                        <button type="button" onClick={toggle} className="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2" aria-label={`Widget menu: ${payload?.title ?? 'Widget'}`} aria-haspopup="menu">
                            {loading ? (
                                <span className="material-symbols-outlined text-[20px] leading-none animate-spin motion-reduce:animate-none" aria-hidden="true">progress_activity</span>
                            ) : (
                                <Icon name="more_vert" className="w-5 h-5" />
                            )}
                        </button>
                    )}
                >
                    {({ close }) => (
                        <>
                            <button type="button" onClick={() => { close(); load(); }} className="flex w-full items-center gap-2 rounded px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                <Icon name="refresh" className="w-[18px] h-[18px]" /> Refresh
                            </button>
                            {exportUrl && (
                                <a href={exportUrl} onClick={close} className="flex w-full items-center gap-2 rounded px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                    <Icon name="download" className="w-[18px] h-[18px]" /> Export
                                </a>
                            )}
                            {payload?.urls?.drill && (
                                <a href={payload.urls.drill} className="flex w-full items-center gap-2 rounded px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                    <Icon name="zoom_in" className="w-[18px] h-[18px]" /> Drill down
                                </a>
                            )}
                        </>
                    )}
                </Menu>
            </div>

            <div className="min-h-0 flex-1 overflow-auto px-6 py-4">
                {state === 'forbidden' ? (
                    <div className="flex h-full flex-col items-center justify-center gap-2 py-6 text-gray-500">
                        <Icon name="lock" className="w-8 h-8" />
                        <p className="text-sm">You do not have permission to view this data.</p>
                    </div>
                ) : state !== 'ok' || !Renderer ? (
                    <div className="flex h-full flex-col items-center justify-center gap-2 py-6 text-gray-500">
                        <Icon name="error_outline" className="w-8 h-8" />
                        <p className="text-sm">This widget could not be rendered.</p>
                        {payload?.urls?.payload && (
                            <button type="button" onClick={() => load()} className="text-sm font-medium text-[var(--color-primary)] underline hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 rounded">
                                Retry
                            </button>
                        )}
                    </div>
                ) : (
                    <Renderer type={type} data={payload.data || {}} envelope={payload} onFilters={load} />
                )}
            </div>
        </div>
    );
}
