import { useCallback } from 'react';
import { sparklineConfig } from '../chartConfigs';
import useChart from './useChart';

function Sparkline({ points }) {
    const build = useCallback((d, env, t) => sparklineConfig(d.points, t), []);
    const canvasRef = useChart(build, { points }, null);

    return (
        <div className="mt-2 h-10">
            <canvas ref={canvasRef} className="h-10 w-full" />
        </div>
    );
}

/** kpi_tile — big number, delta chip, sparkline. */
export default function KpiTile({ data }) {
    const delta = data.delta && typeof data.delta === 'object' ? data.delta : null;
    const spark = Array.isArray(data.sparkline) ? data.sparkline.filter((p) => p && p.value !== null) : [];
    const hasValue = data.value !== null && data.value !== undefined;

    return (
        <div className="flex h-full flex-col justify-between">
            <div>
                <p className="text-3xl font-bold tabular-nums text-gray-900">
                    {hasValue ? (
                        <>
                            {data.formatted ?? Number(data.value).toLocaleString('en', { maximumFractionDigits: 0 })}
                            {data.unit && <span className="ml-1 text-base font-medium text-gray-400">{data.unit}</span>}
                        </>
                    ) : (
                        <span className="text-xl font-medium text-gray-300">No data</span>
                    )}
                </p>

                {delta && (
                    <p
                        className={`mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                            delta.improving ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'
                        }`}
                    >
                        <span className="material-symbols-outlined text-[14px] leading-none">
                            {(delta.absolute ?? 0) >= 0 ? 'arrow_upward' : 'arrow_downward'}
                        </span>
                        {delta.pct !== null && delta.pct !== undefined
                            ? `${Math.abs(delta.pct).toFixed(1)}%`
                            : Math.abs(delta.absolute ?? 0).toFixed(1)}{' '}
                        vs previous
                    </p>
                )}
            </div>

            {spark.length >= 2 && <Sparkline points={data.sparkline} />}
        </div>
    );
}
