/*
| widget_type → React renderer (migration Phase 2).
|
| Table-like types were Blade partials under resources/views/widgets/types;
| chart types were hydrated by widgets/index.js from a JSON island. Both now
| render from the same envelope the engine always produced.
*/
import { chartConfigs } from '../chartConfigs';
import ActivityTable from './ActivityTable';
import ChartWidget from './ChartWidget';
import Heatmap from './Heatmap';
import KpiTile from './KpiTile';
import MeasureTable from './MeasureTable';
import Network from './Network';
import Register from './Register';
import Treemap from './Treemap';

const renderers = {
    kpi_tile: KpiTile,
    heatmap: Heatmap,
    opportunity_heatmap: Heatmap,
    register: Register,
    activity_table: ActivityTable,
    measure_table: MeasureTable,
    treemap: Treemap,
    network: Network,
};

export function rendererFor(type) {
    if (renderers[type]) return renderers[type];
    if (chartConfigs[type]) return ChartWidget;
    return null;
}

export const widgetTypes = [...Object.keys(renderers), ...Object.keys(chartConfigs)];
