/**
 * A labels-and-values series, drawn as bars or a line (migration Phase 5.2).
 *
 * House convention is inline SVG with no chart dependency, which the Blade
 * screens broke by reaching for Chart.js. The four series these pages draw —
 * a severity density, a loss histogram, each scenario's share, and a
 * cumulative distribution — are all one dimension against one label, so they
 * share this.
 *
 * An empty series renders nothing rather than an empty axis: a chart with no
 * points is a claim that there is nothing to plot, and the callers say that in
 * words instead.
 */
export default function SeriesChart({
    labels = [],
    values = [],
    height = 240,
    variant = 'bar',
    valueLabel = (value) => String(value),
    ariaLabel = 'Chart',
}) {
    if (labels.length === 0 || values.length === 0) return null;

    const width = 720;
    const pad = { top: 16, right: 16, bottom: 46, left: 56 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    const maxValue = Math.max(...values, 0) || 1;
    const xFor = (i) => (values.length === 1 ? pad.left + plotW / 2 : pad.left + (i / (values.length - 1)) * plotW);
    const yFor = (value) => pad.top + plotH - (value / maxValue) * plotH;

    const barW = Math.max(2, (plotW / values.length) * 0.7);
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => maxValue * t);

    // A label every nth point, so a 20-bucket axis stays readable.
    const labelEvery = Math.max(1, Math.ceil(labels.length / 8));

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full" role="img" aria-label={ariaLabel}>
            {ticks.map((tick) => (
                <g key={tick}>
                    <line x1={pad.left} x2={width - pad.right} y1={yFor(tick)} y2={yFor(tick)} stroke="#E5E7EB" strokeWidth="1" />
                    <text x={pad.left - 8} y={yFor(tick) + 3.5} textAnchor="end" fontSize="10" fill="#9CA3AF">
                        {valueLabel(tick)}
                    </text>
                </g>
            ))}

            {variant === 'line' ? (
                <>
                    <polyline
                        fill="none"
                        stroke="#1A365D"
                        strokeWidth="2"
                        points={values.map((value, i) => `${xFor(i)},${yFor(value)}`).join(' ')}
                    />
                    {values.map((value, i) => (
                        <circle key={i} cx={xFor(i)} cy={yFor(value)} r="3" fill="#1A365D" />
                    ))}
                </>
            ) : (
                values.map((value, i) => (
                    <rect
                        key={i}
                        x={pad.left + (i + 0.5) * (plotW / values.length) - barW / 2}
                        y={yFor(value)}
                        width={barW}
                        height={pad.top + plotH - yFor(value)}
                        rx="2"
                        fill="rgba(26,54,93,0.75)"
                    />
                ))
            )}

            {labels.map((label, i) =>
                i % labelEvery === 0 ? (
                    <text
                        key={i}
                        x={variant === 'line' ? xFor(i) : pad.left + (i + 0.5) * (plotW / values.length)}
                        y={height - 22}
                        textAnchor="middle"
                        fontSize="9"
                        fill="#9CA3AF"
                    >
                        {label}
                    </text>
                ) : null,
            )}
        </svg>
    );
}
