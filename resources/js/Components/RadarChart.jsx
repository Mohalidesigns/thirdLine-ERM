/**
 * Radar chart (pure SVG, no chart dependency — house convention, as
 * DonutChart and HBarChart are).
 *
 * Replaces the Chart.js radar on the assessment detail page. Decision 3
 * permits Chart.js, but every other chart the migration has ported is drawn
 * by hand, and a 12 kB dependency for one five-axis polygon is not the trade
 * the rest of the product made.
 *
 * `series` is one or two entries: the current assessment, and optionally the
 * one before it. `max` is the axis maximum — the scoring profile's matrix
 * width, not a hardcoded 5.
 */
export default function RadarChart({ labels = [], series = [], max = 5, size = 260 }) {
    if (labels.length < 3) return null;

    const center = size / 2;
    const radius = center - 42;
    const rings = 4;

    // Straight up for the first axis, then clockwise.
    const point = (index, value) => {
        const angle = (Math.PI * 2 * index) / labels.length - Math.PI / 2;
        const distance = (Math.max(0, Math.min(max, value)) / max) * radius;

        return [center + distance * Math.cos(angle), center + distance * Math.sin(angle)];
    };

    const polygon = (values) => values.map((value, index) => point(index, value).join(',')).join(' ');

    return (
        <div className="flex flex-col items-center">
            <svg width={size} height={size} role="img" aria-label="Impact dimension scores">
                {/* Rings and spokes */}
                {Array.from({ length: rings }, (_, ring) => (
                    <polygon
                        key={ring}
                        points={polygon(labels.map(() => (max * (ring + 1)) / rings))}
                        fill="none"
                        stroke="#e5e7eb"
                        strokeWidth="1"
                    />
                ))}
                {labels.map((label, index) => {
                    const [x, y] = point(index, max);

                    return <line key={label} x1={center} y1={center} x2={x} y2={y} stroke="#e5e7eb" strokeWidth="1" />;
                })}

                {series.map((entry) => (
                    <polygon
                        key={entry.label}
                        points={polygon(entry.values)}
                        fill={entry.color}
                        fillOpacity={entry.dashed ? 0.06 : 0.18}
                        stroke={entry.color}
                        strokeWidth="2"
                        strokeDasharray={entry.dashed ? '4 3' : undefined}
                    />
                ))}

                {/* Axis labels, nudged outside the outer ring */}
                {labels.map((label, index) => {
                    const [x, y] = point(index, max * 1.22);

                    return (
                        <text
                            key={label}
                            x={x}
                            y={y}
                            textAnchor={x > center + 4 ? 'start' : x < center - 4 ? 'end' : 'middle'}
                            dominantBaseline="middle"
                            className="fill-gray-500"
                            style={{ fontSize: 10 }}
                        >
                            {label}
                        </text>
                    );
                })}
            </svg>

            <div className="flex items-center gap-4 mt-2">
                {series.map((entry) => (
                    <span key={entry.label} className="flex items-center gap-1.5 text-xs text-gray-600">
                        <span
                            className="w-3 h-0.5 rounded"
                            style={{
                                backgroundColor: entry.color,
                                opacity: entry.dashed ? 0.6 : 1,
                            }}
                        />
                        {entry.label}
                    </span>
                ))}
            </div>
        </div>
    );
}
