import { useEffect, useRef } from 'react';
import { readTokens } from '../theme';

let chartModule = null;

/**
 * Own one Chart.js instance on a <canvas> (migration Phase 2).
 *
 * `build(data, envelope, tokens, width)` is a pure config builder from
 * widgets/chartConfigs.js. The chart is (re)built when the data changes and
 * when the colour scheme flips — tokens are read from CSS at build time, so a
 * rebuild is what restyles it. Chart.js is loaded lazily: the bundle only pays
 * for it on the pages that draw one.
 *
 * Returns the canvas ref. A builder that returns null (nothing to draw) leaves
 * the canvas empty; the caller decides what "no data" looks like.
 */
export default function useChart(build, data, envelope, onEmpty) {
    const canvasRef = useRef(null);
    const chartRef = useRef(null);

    useEffect(() => {
        let cancelled = false;

        const destroy = () => {
            if (chartRef.current) {
                chartRef.current.destroy();
                chartRef.current = null;
            }
        };

        const render = async () => {
            if (!chartModule) {
                chartModule = (await import('chart.js/auto')).default;
            }
            if (cancelled || !canvasRef.current) return;

            destroy();

            const canvas = canvasRef.current;
            const width = canvas.parentElement?.clientWidth || 480;
            const config = build(data || {}, envelope || {}, readTokens(), width);

            if (!config) {
                onEmpty?.(true);
                return;
            }

            onEmpty?.(false);
            chartRef.current = new chartModule(canvas, config);
        };

        render().catch((e) => {
            if (import.meta.env?.DEV) console.warn('[widget chart]', e);
            onEmpty?.(true);
        });

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onScheme = () => render().catch(() => {});
        media.addEventListener('change', onScheme);

        return () => {
            cancelled = true;
            media.removeEventListener('change', onScheme);
            destroy();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, build]);

    return canvasRef;
}
