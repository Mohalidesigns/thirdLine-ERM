/*
| Chart.js 4 configuration builders for the widget engine (migration Phase 2).
|
| Ported from the DOM renderers in charts.js: every builder has the signature
| (data, envelope, tokens, width) and returns a Chart.js config — or null when
| the payload has nothing to draw. Owning the <canvas> and the Chart instance
| is the useChart hook's job (widgets/renderers/useChart.js), so nothing here
| touches the DOM.
|
| Colour rules (dataviz method):
|  - RAG / band-semantic marks use the colours carried IN the payload
|    (scoring-profile threshold bands) — never re-invented client-side.
|  - Series without semantic colour take the validated categorical palette
|    from the theme tokens, in fixed slot order.
|  - Chrome (grid, ticks, legend text) wears ink tokens, never series colour.
*/

import { alpha, clamp, fmtDate, fmtNaira, fmtNum } from './theme';
/* ---------------------------------------------------------------- helpers */

function tooltip(t, callbacks = {}) {
    return {
        backgroundColor: t.tooltipBg,
        titleColor: t.tooltipInk,
        bodyColor: t.tooltipInk,
        titleFont: { family: t.fontFamily, size: 12, weight: '600' },
        bodyFont: { family: t.fontFamily, size: 12 },
        cornerRadius: 6,
        padding: 10,
        boxPadding: 4,
        usePointStyle: true,
        callbacks,
    };
}

function legend(t, position = 'bottom') {
    return {
        display: true,
        position,
        labels: {
            usePointStyle: true,
            pointStyle: 'circle',
            boxWidth: 8,
            boxHeight: 8,
            padding: 14,
            color: t.inkSecondary,
            font: { family: t.fontFamily, size: 11 },
        },
    };
}

function catScale(t, extra = {}) {
    return {
        grid: { display: false },
        border: { display: false },
        ticks: { color: t.muted, font: { family: t.fontFamily, size: 11 } },
        ...extra,
    };
}

function linScale(t, extra = {}) {
    return {
        beginAtZero: true,
        grid: { color: t.grid, drawTicks: false },
        border: { display: false },
        ticks: { color: t.muted, font: { family: t.fontFamily, size: 11 }, maxTicksLimit: 6 },
        ...extra,
    };
}

function baseOptions(t, overrides = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        interaction: { mode: 'nearest', intersect: true },
        plugins: { legend: { display: false }, tooltip: tooltip(t) },
        ...overrides,
    };
}

const num = (v) => (v === null || v === undefined || v === '' ? null : +v);

/* ------------------------------------------------------- kpi sparkline */

export function sparklineConfig(points, t) {
    if (!Array.isArray(points) || points.length === 0) return null;

    const values = points.map((p) => num(p?.value));
    let lastIdx = -1;
    for (let i = values.length - 1; i >= 0; i--) {
        if (values[i] !== null) {
            lastIdx = i;
            break;
        }
    }
    if (lastIdx === -1) return null;

    return {
        type: 'line',
        data: {
            labels: points.map((p) => p?.period ?? ''),
            datasets: [
                {
                    data: values,
                    borderColor: t.series[0],
                    borderWidth: 1.5,
                    tension: 0.25,
                    fill: false,
                    spanGaps: false, // a missing period is a gap, not a guess
                    pointRadius: (ctx) => (ctx.dataIndex === lastIdx ? 2.5 : 0),
                    pointBackgroundColor: t.series[0],
                    pointBorderWidth: 0,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            events: [],
            layout: { padding: 3 },
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            scales: { x: { display: false }, y: { display: false } },
        },
    };
}
/* --------------------------------------------------------------- donut */

function donut(data, env, t, width) {
    let slices = (data.slices || []).filter((s) => num(s?.value) > 0);
    if (slices.length === 0) return null;

    // Never a 9th categorical hue: fold the tail into "Other".
    if (slices.length > 8) {
        const head = slices.slice(0, 7);
        head.push({ label: 'Other', value: slices.slice(7).reduce((a, s) => a + +s.value, 0) });
        slices = head;
    }

    const total = slices.reduce((a, s) => a + +s.value, 0);
    const narrow = width > 0 && width < 360;

    return {
        type: 'doughnut',
        data: {
            labels: slices.map((s) => s.label),
            datasets: [
                {
                    data: slices.map((s) => +s.value),
                    backgroundColor: slices.map((_, i) => t.series[i]),
                    borderColor: t.surface, // 2px surface gap between fills
                    borderWidth: 2,
                    hoverOffset: 4,
                },
            ],
        },
        options: baseOptions(t, {
            cutout: '62%',
            plugins: {
                legend: legend(t, narrow ? 'bottom' : 'right'),
                tooltip: tooltip(t, {
                    label: (ctx) => {
                        const pct = total > 0 ? Math.round((ctx.parsed / total) * 1000) / 10 : 0;
                        return ` ${ctx.label}: ${fmtNum(ctx.parsed)} (${pct}%)`;
                    },
                }),
            },
        }),
    };
}

/* -------------------------------------------------------------- pareto */

function pareto(data, env, t, width) {
    const bars = data.bars || [];
    if (bars.length === 0) return null;

    return {
        type: 'bar',
        data: {
            labels: bars.map((b) => b.label),
            datasets: [
                {
                    label: 'Count',
                    data: bars.map((b) => num(b.value)),
                    backgroundColor: t.series[0],
                    borderRadius: 4,
                    maxBarThickness: 36,
                    order: 2,
                },
                {
                    label: 'Cumulative %',
                    type: 'line',
                    data: bars.map((b) => num(b.cumulative_pct)),
                    yAxisID: 'pct',
                    borderColor: t.accent,
                    backgroundColor: t.accent,
                    borderWidth: 2,
                    tension: 0.2,
                    pointRadius: 0,
                    pointHoverRadius: 3,
                    order: 1,
                },
            ],
        },
        options: baseOptions(t, {
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: catScale(t),
                y: linScale(t),
                pct: linScale(t, {
                    position: 'right',
                    min: 0,
                    max: 100,
                    grid: { drawOnChartArea: false, drawTicks: false },
                    ticks: {
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                        maxTicksLimit: 5,
                        callback: (v) => `${v}%`,
                    },
                }),
            },
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, {
                    label: (ctx) =>
                        ctx.dataset.yAxisID === 'pct'
                            ? ` Cumulative: ${fmtNum(ctx.parsed.y)}%`
                            : ` ${fmtNum(ctx.parsed.y)}`,
                }),
            },
        }),
    };
}

/* -------------------------------------------------- stacked_bar_bands */

function stackedBarBands(data, env, t, width) {
    const bands = data.bands || [];
    const units = data.units || [];
    if (bands.length === 0 || units.length === 0) return null;

    return {
        type: 'bar',
        data: {
            labels: units.map((u) => u.label),
            datasets: bands.map((band, bi) => ({
                label: band.label ?? band.code,
                data: units.map((u) => num(u.counts?.[bi]) ?? 0),
                backgroundColor: band.color || t.series[bi], // payload band colour wins
                borderColor: t.surface, // 2px surface gap between segments
                borderWidth: 1,
                stack: 'bands',
                maxBarThickness: 44,
            })),
        },
        options: baseOptions(t, {
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: catScale(t, { stacked: true }),
                y: linScale(t, { stacked: true }),
            },
            plugins: {
                legend: legend(t),
                tooltip: tooltip(t, {
                    label: (ctx) => ` ${ctx.dataset.label}: ${fmtNum(ctx.parsed.y)}`,
                }),
            },
        }),
    };
}

/* ------------------------------------------------------- grouped_bar_3 */

function groupedBar3(data, env, t, width) {
    const groups = data.groups || [];
    if (groups.length === 0) return null;

    const datasets = [
        { label: 'Inherent', key: 'inherent', color: t.series[0] },
        { label: 'Residual', key: 'residual', color: t.series[1] },
        { label: 'Planned', key: 'planned', color: t.series[2] },
    ]
        .map((d) => ({
            label: d.label,
            data: groups.map((g) => num(g[d.key])),
            backgroundColor: d.color,
            borderRadius: 4,
            maxBarThickness: 22,
        }))
        // Drop a series that has no value anywhere (e.g. planned never set).
        .filter((d) => d.data.some((v) => v !== null));

    if (datasets.length === 0) return null;

    return {
        type: 'bar',
        data: { labels: groups.map((g) => g.label), datasets },
        options: baseOptions(t, {
            scales: { x: catScale(t), y: linScale(t) },
            plugins: {
                legend: legend(t),
                tooltip: tooltip(t, {
                    label: (ctx) => ` ${ctx.dataset.label}: ${fmtNum(ctx.parsed.y)}`,
                }),
            },
        }),
    };
}

/* -------------------------------------------------------------- bubble */

function bubble(data, env, t, width) {
    const points = (data.points || []).filter((p) => num(p?.x) !== null && num(p?.y) !== null);
    if (points.length === 0) return null;

    const maxSize = Math.max(...points.map((p) => num(p.size) ?? 0), 1);
    const k = 22 / Math.sqrt(maxSize);

    return {
        type: 'bubble',
        data: {
            datasets: [
                {
                    data: points.map((p) => ({
                        x: +p.x,
                        y: +p.y,
                        r: clamp(Math.sqrt(Math.max(num(p.size) ?? 0, 0)) * k, 4, 22),
                        label: p.label,
                    })),
                    backgroundColor: alpha(t.series[0], 0.75),
                    borderColor: t.surface, // surface ring separates overlaps
                    borderWidth: 2,
                    hoverBorderColor: t.surface,
                    hoverBorderWidth: 2,
                },
            ],
        },
        options: baseOptions(t, {
            scales: {
                x: linScale(t, {
                    title: {
                        display: true,
                        text: 'Control effectiveness %',
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                    },
                }),
                y: linScale(t, {
                    title: {
                        display: true,
                        text: 'Exposure (L×C)',
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                    },
                }),
            },
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, {
                    title: (items) => items[0]?.raw?.label ?? '',
                    label: (ctx) =>
                        ` Effectiveness ${fmtNum(ctx.raw.x)}% · Exposure ${fmtNum(ctx.raw.y)}`,
                }),
            },
        }),
    };
}

/* --------------------------------------------------------- stacked_area */

function stackedArea(data, env, t, width) {
    const periods = data.periods || [];
    const bands = data.bands || [];
    const series = data.series || {};
    if (periods.length === 0 || bands.length === 0) return null;

    return {
        type: 'line',
        data: {
            labels: periods,
            datasets: bands.map((band) => ({
                label: band.label ?? band.code,
                data: (series[band.code] || []).map(num),
                borderColor: band.color || t.series[0],
                backgroundColor: alpha(band.color || t.series[0], 0.35),
                fill: true,
                borderWidth: 2,
                tension: 0.25,
                pointRadius: 0,
                pointHoverRadius: 3,
            })),
        },
        options: baseOptions(t, {
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: catScale(t),
                y: linScale(t, { stacked: true }),
            },
            plugins: {
                legend: legend(t),
                tooltip: tooltip(t, {
                    label: (ctx) => ` ${ctx.dataset.label}: ${fmtNum(ctx.parsed.y)}`,
                }),
            },
        }),
    };
}

/* ---------------------------------------------------- trend_stacked_bar */

function trendStackedBar(data, env, t, width) {
    const quarters = data.quarters || [];
    const series = data.series || {};
    if (quarters.length === 0) return null;

    // Polarity, not identity: worsening wears status-red, improving wears
    // status-green, flat a neutral gray-blue — legend + tooltip carry the
    // names so colour is never alone (CVD relief).
    const spec = [
        { key: 'increasing', label: 'Increasing', color: t.trendUp },
        { key: 'constant', label: 'Constant', color: t.trendFlat },
        { key: 'decreasing', label: 'Decreasing', color: t.trendDown },
    ];

    return {
        type: 'bar',
        data: {
            labels: quarters,
            datasets: spec.map((d) => ({
                label: d.label,
                data: (series[d.key] || []).map(num),
                backgroundColor: d.color,
                borderColor: t.surface,
                borderWidth: 1,
                stack: 'trend',
                maxBarThickness: 44,
            })),
        },
        options: baseOptions(t, {
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: catScale(t, { stacked: true }),
                y: linScale(t, { stacked: true }),
            },
            plugins: {
                legend: legend(t),
                tooltip: tooltip(t, {
                    label: (ctx) => ` ${ctx.dataset.label}: ${fmtNum(ctx.parsed.y)}`,
                }),
            },
        }),
    };
}

/* ------------------------------------------------------ cumulative_line */

function cumulativeLine(data, env, t, width) {
    const periods = data.periods || [];
    const values = (data.values || []).map(num);
    if (periods.length === 0 || values.length === 0) return null;

    const counts = data.counts || [];

    return {
        type: 'line',
        data: {
            labels: periods,
            datasets: [
                {
                    data: values,
                    borderColor: t.series[0],
                    backgroundColor: alpha(t.series[0], 0.12),
                    fill: 'origin',
                    borderWidth: 2,
                    tension: 0.25,
                    pointRadius: 0,
                    pointHoverRadius: 3,
                    spanGaps: false,
                },
            ],
        },
        options: baseOptions(t, {
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: catScale(t),
                y: linScale(t, {
                    min: 0,
                    max: 100,
                    ticks: {
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                        maxTicksLimit: 5,
                        callback: (v) => `${v}%`,
                    },
                }),
            },
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, {
                    label: (ctx) => {
                        const c = counts[ctx.dataIndex];
                        const pct = ` ${fmtNum(ctx.parsed.y)}%`;
                        return c && c.total !== undefined
                            ? `${pct} (${fmtNum(c.done)}/${fmtNum(c.total)})`
                            : pct;
                    },
                }),
            },
        }),
    };
}

/* ---------------------------------------------------------- bar_by_type */

function barByType(data, env, t, width) {
    const bars = data.bars || [];
    if (bars.length === 0) return null;

    return {
        type: 'bar',
        data: {
            labels: bars.map((b) => b.label),
            datasets: [
                {
                    data: bars.map((b) => num(b.value)),
                    backgroundColor: t.series[0], // nominal bars: one hue, length encodes value
                    borderRadius: 4,
                    maxBarThickness: 22,
                },
            ],
        },
        options: baseOptions(t, {
            indexAxis: 'y',
            scales: {
                x: linScale(t),
                y: catScale(t),
            },
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, { label: (ctx) => ` ${fmtNum(ctx.parsed.x)}` }),
            },
        }),
    };
}

/* -------------------------------------------------------------- tornado */

function tornado(data, env, t, width) {
    if (data.empty === true) return null;
    const rows = [...(data.rows || [])]
        .filter((r) => num(r?.low) !== null && num(r?.high) !== null)
        .sort((a, b) => (+b.high - +b.low) - (+a.high - +a.low));
    if (rows.length === 0) return null;

    return {
        type: 'bar',
        data: {
            labels: rows.map((r) => r.label),
            datasets: [
                {
                    label: 'Range',
                    data: rows.map((r) => [+r.low, +r.high]), // floating bars
                    backgroundColor: alpha(t.series[0], 0.8),
                    borderRadius: 4,
                    borderSkipped: false,
                    maxBarThickness: 20,
                    order: 2,
                },
                {
                    label: 'Mid',
                    type: 'line',
                    data: rows.map((r) => num(r.mid)),
                    showLine: false,
                    pointStyle: 'line',
                    pointRotation: 90, // vertical tick marks the mid estimate
                    pointRadius: 8,
                    pointHoverRadius: 8,
                    pointBorderColor: t.ink,
                    pointBorderWidth: 2,
                    order: 1,
                },
            ],
        },
        options: baseOptions(t, {
            indexAxis: 'y',
            scales: {
                x: linScale(t, { beginAtZero: false }),
                y: catScale(t),
            },
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, {
                    label: (ctx) => {
                        const row = rows[ctx.dataIndex];
                        const unit = row.unit ? ` ${row.unit}` : '';
                        return ctx.dataset.type === 'line'
                            ? ` Mid: ${fmtNum(row.mid)}${unit}`
                            : ` ${fmtNum(row.low)} – ${fmtNum(row.high)}${unit}`;
                    },
                }),
            },
        }),
    };
}

/* ------------------------------------------------------------ lec_curve */

function lecCurve(data, env, t, width) {
    if (data.empty === true) return null;
    const raw = (data.points || []).filter((p) => num(p?.loss) !== null && num(p?.probability) !== null);
    if (raw.length === 0) return null;

    const points = [...raw].sort((a, b) => +a.loss - +b.loss);
    // Probability may arrive as a 0–1 fraction or already in percent.
    const asFraction = points.every((p) => +p.probability <= 1);
    const scale = asFraction ? 100 : 1;

    return {
        type: 'line',
        data: {
            datasets: [
                {
                    data: points.map((p) => ({ x: +p.loss, y: +p.probability * scale })),
                    borderColor: t.series[0],
                    backgroundColor: alpha(t.series[0], 0.12),
                    fill: 'origin',
                    borderWidth: 2,
                    tension: 0,
                    pointRadius: 0,
                    pointHoverRadius: 3,
                },
            ],
        },
        options: baseOptions(t, {
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            scales: {
                x: linScale(t, {
                    type: 'linear',
                    beginAtZero: true,
                    ticks: {
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                        maxTicksLimit: 6,
                        callback: (v) => fmtNaira(v),
                    },
                    title: {
                        display: true,
                        text: 'Loss',
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                    },
                }),
                y: linScale(t, {
                    ticks: {
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                        maxTicksLimit: 5,
                        callback: (v) => `${fmtNum(v)}%`,
                    },
                    title: {
                        display: true,
                        text: 'Probability of exceedance',
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                    },
                }),
            },
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, {
                    title: (items) => (items[0] ? fmtNaira(items[0].parsed.x) : ''),
                    label: (ctx) => ` ${fmtNum(ctx.parsed.y)}% probability`,
                }),
            },
        }),
    };
}

/* ---------------------------------------------------------------- gauge */

function gauge(data, env, t, width) {
    let bands = data.bands || [];
    const value = num(data.value);
    if (value === null && bands.length === 0) return null;

    // A value without configured threshold bands still deserves a gauge —
    // one neutral arc sized to the value's own scale. Bands appear the
    // moment a threshold is configured; nothing is invented meanwhile.
    if (bands.length === 0) {
        const ceiling = Math.max(1, Math.ceil(value * 1.25));
        bands = [{ code: '_scale', label: '', color: t.grid, min: 0, max: ceiling }];
    }

    const current =
        (typeof data.band === 'object' && data.band) ||
        bands.find((b) => b.code !== undefined && b.code === data.band) ||
        (value !== null ? bands.find((b) => value >= +b.min && value <= +b.max) : null);

    const centreText = {
        id: 'gaugeCentreText',
        afterDraw(chart) {
            const arc = chart.getDatasetMeta(0).data[0];
            if (!arc) return;
            const { ctx } = chart;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.fillStyle = t.ink;
            ctx.font = `600 26px ${t.fontFamily}`;
            ctx.fillText(value === null ? '–' : fmtNum(value), arc.x, arc.y - 6);
            if (current?.label) {
                ctx.fillStyle = t.muted;
                ctx.font = `500 12px ${t.fontFamily}`;
                ctx.fillText(current.label, arc.x, arc.y + 16);
            }
            ctx.restore();
        },
    };

    return {
        type: 'doughnut',
        data: {
            labels: bands.map((b) => b.label ?? b.code ?? ''),
            datasets: [
                {
                    data: bands.map((b) => Math.max(+b.max - +b.min, 0)),
                    // Payload band colours; the band the value sits in reads
                    // full-strength, the rest recede.
                    backgroundColor: bands.map((b) =>
                        current && b !== current && !(current.code && b.code === current.code)
                            ? alpha(b.color, 0.3)
                            : b.color
                    ),
                    borderColor: t.surface,
                    borderWidth: 2,
                },
            ],
        },
        options: baseOptions(t, {
            circumference: 180,
            rotation: 270,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, {
                    label: (ctx) => {
                        const b = bands[ctx.dataIndex];
                        return ` ${b.label ?? b.code}: ${fmtNum(b.min)}–${fmtNum(b.max)}`;
                    },
                }),
            },
        }),
        plugins: [centreText],
    };
}

/* ------------------------------------------------------------- timeline */

const DAY_MS = 86400000;

function parseIso(value) {
    if (!value) return null;
    const s = String(value);
    const date = new Date(s.length <= 10 ? `${s}T00:00:00` : s);
    return Number.isNaN(date.getTime()) ? null : date;
}

function ragColor(item, t) {
    const raw = item.rag ?? item.color ?? '';
    if (/^#[0-9a-f]{3,8}$/i.test(String(raw))) return String(raw);
    const map = {
        red: '#dc2626',
        orange: '#f97316',
        amber: '#f59e0b',
        yellow: '#eab308',
        green: '#22c55e',
        blue: t.series[0],
        gray: t.trendFlat,
        grey: t.trendFlat,
    };
    return map[String(raw).toLowerCase()] || t.series[0];
}

function timeline(data, env, t, width) {
    const items = (data.items || [])
        .map((item) => ({ ...item, startDate: parseIso(item.start), endDate: parseIso(item.end) }))
        .filter((item) => item.startDate && item.endDate);
    if (items.length === 0) return null;

    const minMs = Math.min(...items.map((i) => i.startDate.getTime()));
    const maxMs = Math.max(...items.map((i) => i.endDate.getTime()));
    const spanDays = Math.max((maxMs - minMs) / DAY_MS, 1);
    const withYear = spanDays > 300;

    const offsets = items.map((item) => {
        const start = (item.startDate.getTime() - minMs) / DAY_MS;
        const end = (item.endDate.getTime() - minMs) / DAY_MS;
        return [start, Math.max(end, start + 0.5)]; // zero-length item stays visible
    });

    return {
        type: 'bar',
        data: {
            labels: items.map((i) => i.label),
            datasets: [
                {
                    data: offsets,
                    backgroundColor: items.map((i) => ragColor(i, t)), // payload RAG wins
                    borderRadius: 4,
                    borderSkipped: false,
                    maxBarThickness: 14,
                },
            ],
        },
        options: baseOptions(t, {
            indexAxis: 'y',
            scales: {
                x: {
                    type: 'linear',
                    min: 0,
                    max: Math.ceil(spanDays),
                    grid: { color: t.grid, drawTicks: false },
                    border: { display: false },
                    ticks: {
                        color: t.muted,
                        font: { family: t.fontFamily, size: 11 },
                        maxTicksLimit: 6,
                        callback: (v) => fmtDate(new Date(minMs + v * DAY_MS), withYear),
                    },
                },
                y: catScale(t),
            },
            plugins: {
                legend: { display: false },
                tooltip: tooltip(t, {
                    title: (ctx) => (ctx[0] ? items[ctx[0].dataIndex]?.label ?? '' : ''),
                    label: (ctx) => {
                        const item = items[ctx.dataIndex];
                        const range = `${fmtDate(item.startDate, true)} → ${fmtDate(item.endDate, true)}`;
                        return item.status ? ` ${range} · ${item.status}` : ` ${range}`;
                    },
                }),
            },
        }),
    };
}

/* -------------------------------------------------------------- exports */

/* ------------------------------------------------------ appetite bands */

/* Current position per category as a bar coloured by its band (the band
   colours travel in the payload — they are semantic, not a palette), with
   the target maximum and the hard limit drawn as lines over the bars. */
function appetitePosition(data, env, t, width) {
    const bars = (data.bars || []).filter((b) => b.label);
    if (bars.length === 0) return null;

    const colors = data.colors || {};
    const tone = (status) => colors[status] || t.series[0];

    return {
        type: 'bar',
        data: {
            labels: bars.map((b) => b.label),
            datasets: [
                {
                    type: 'line',
                    label: 'Hard limit',
                    data: bars.map((b) => num(b.limit)),
                    borderColor: colors.breach || t.trendUp,
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    fill: false,
                    order: 0,
                },
                {
                    type: 'line',
                    label: 'Target max',
                    data: bars.map((b) => num(b.target_max)),
                    borderColor: t.inkSecondary,
                    borderWidth: 1.5,
                    pointRadius: 0,
                    fill: false,
                    order: 1,
                },
                {
                    label: 'Current position',
                    data: bars.map((b) => num(b.current)),
                    backgroundColor: bars.map((b) => tone(b.status)),
                    borderRadius: 4,
                    maxBarThickness: 28,
                    order: 2,
                },
            ],
        },
        options: baseOptions(t, {
            scales: { x: catScale(t), y: linScale(t, { beginAtZero: true }) },
            plugins: {
                legend: legend(t),
                tooltip: tooltip(t, {
                    label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y === null ? 'not recorded' : fmtNum(ctx.parsed.y)}`,
                }),
            },
        }),
    };
}

export const chartConfigs = {
    donut,
    pareto,
    stacked_bar_bands: stackedBarBands,
    grouped_bar_3: groupedBar3,
    bubble,
    stacked_area: stackedArea,
    trend_stacked_bar: trendStackedBar,
    cumulative_line: cumulativeLine,
    bar_by_type: barByType,
    tornado,
    lec_curve: lecCurve,
    gauge,
    timeline,
    appetite_position: appetitePosition,
};
