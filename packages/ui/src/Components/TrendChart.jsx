/**
 * Grouped-bar month trend (SVG, no chart dependency — house convention).
 * series: [{ key, label, color }]; data: [{ month, [key]: number }]
 */
export default function TrendChart({ data = [], series = [], height = 220 }) {
    if (!data.length || !series.length) return null;

    const width = 720;
    const pad = { top: 16, right: 12, bottom: 34, left: 36 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    const maxValue = Math.max(1, ...data.flatMap(d => series.map(s => d[s.key] || 0)));
    const niceMax = Math.ceil(maxValue / 5) * 5 || 5;

    const groupW = plotW / data.length;
    const barW = Math.min(22, (groupW * 0.6) / series.length);

    const yFor = (value) => pad.top + plotH - (value / niceMax) * plotH;
    const ticks = [0, 0.25, 0.5, 0.75, 1].map(t => Math.round(niceMax * t));

    return (
        <div>
            <svg viewBox={`0 0 ${width} ${height}`} className="w-full" role="img" aria-label="Case trend chart">
                {ticks.map(tick => (
                    <g key={tick}>
                        <line x1={pad.left} x2={width - pad.right} y1={yFor(tick)} y2={yFor(tick)} stroke="#E5E7EB" strokeWidth="1" />
                        <text x={pad.left - 6} y={yFor(tick) + 3.5} textAnchor="end" fontSize="10" fill="#9CA3AF">{tick}</text>
                    </g>
                ))}
                {data.map((d, i) => {
                    const groupX = pad.left + i * groupW + (groupW - barW * series.length) / 2;
                    return (
                        <g key={d.month}>
                            {series.map((s, j) => {
                                const value = d[s.key] || 0;
                                const barH = (value / niceMax) * plotH;
                                return (
                                    <rect
                                        key={s.key}
                                        x={groupX + j * barW}
                                        y={yFor(value)}
                                        width={barW - 2}
                                        height={barH}
                                        rx="2"
                                        fill={s.color}
                                    >
                                        <title>{`${d.month} — ${s.label}: ${value}`}</title>
                                    </rect>
                                );
                            })}
                            <text
                                x={pad.left + i * groupW + groupW / 2}
                                y={height - pad.bottom + 14}
                                textAnchor="middle"
                                fontSize="9.5"
                                fill="#6B7280"
                            >
                                {data.length > 8 ? d.month.split(' ')[0] : d.month}
                            </text>
                        </g>
                    );
                })}
            </svg>
            <div className="flex items-center justify-center gap-5 mt-1">
                {series.map(s => (
                    <span key={s.key} className="inline-flex items-center gap-1.5 text-xs text-gray-500">
                        <span className="w-2.5 h-2.5 rounded-sm" style={{ backgroundColor: s.color }}></span>
                        {s.label}
                    </span>
                ))}
            </div>
        </div>
    );
}
