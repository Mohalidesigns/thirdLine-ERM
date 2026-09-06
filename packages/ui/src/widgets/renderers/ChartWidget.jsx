import { useCallback, useState } from 'react';
import { chartConfigs } from '../chartConfigs';
import useChart from './useChart';

/** Every Chart.js-backed widget type: one canvas, one config builder. */
export default function ChartWidget({ type, data, envelope }) {
    const [empty, setEmpty] = useState(false);
    const build = useCallback((d, env, t, width) => chartConfigs[type]?.(d, env, t, width) ?? null, [type]);
    const canvasRef = useChart(build, data, envelope, setEmpty);

    return (
        <div className="widget-chart relative h-full min-h-[180px]">
            <canvas ref={canvasRef} className={empty ? 'hidden' : ''} />
            {empty && <p className="widget-empty-note">No data in scope</p>}
        </div>
    );
}
