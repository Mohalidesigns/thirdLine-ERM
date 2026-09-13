/**
 * Cost against achievable recovery time, one point per strategy option.
 *
 * THE CHART IS THE ISO 22331 CONVERSATION. Cheap and slow in one corner,
 * expensive and fast in the other, and a vertical line where the BIA says the
 * recovery time has to be. Everything left of the line meets the requirement;
 * everything right of it does not, however good the price is.
 *
 * Inline SVG and no chart dependency, which is the house convention
 * (Components/Quantification/SeriesChart). An empty series renders nothing
 * rather than an empty axis — the caller says "nothing to plot" in words.
 */
export default function CostRtoScatter({ points = [], height = 300, currency = '₦' }) {
    if (points.length === 0) return null;

    const width = 720;
    const pad = { top: 18, right: 18, bottom: 48, left: 72 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    const maxRto = Math.max(...points.map((p) => p.rto_achievable_hours), 1);
    const maxCost = Math.max(...points.map((p) => p.cost_minor), 1);

    const xFor = (hours) => pad.left + (hours / maxRto) * plotW;
    const yFor = (cost) => pad.top + plotH - (cost / maxCost) * plotH;

    // One requirement line per distinct required RTO on the chart. A register
    // filtered to Tier 1 usually has two or three, not twenty.
    const requirements = [...new Set(points.map((p) => p.rto_required_hours).filter((v) => v != null))];

    const money = (minor) => `${currency}${(minor / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

    return (
        <svg viewBox={`0 0 ${width} ${height}`} className="w-full" role="img" aria-label="Strategy cost against achievable recovery time">
            {[0, 0.25, 0.5, 0.75, 1].map((t) => (
                <g key={t}>
                    <line x1={pad.left} x2={width - pad.right} y1={yFor(maxCost * t)} y2={yFor(maxCost * t)} stroke="#eef1f5" />
                    <text x={pad.left - 8} y={yFor(maxCost * t) + 4} textAnchor="end" fontSize="10" fill="#8b95a1">
                        {money(maxCost * t)}
                    </text>
                </g>
            ))}

            {requirements.map((hours) => (
                <g key={hours}>
                    <line
                        x1={xFor(hours)} x2={xFor(hours)} y1={pad.top} y2={pad.top + plotH}
                        stroke="#c2410c" strokeDasharray="4 3"
                    />
                    <text x={xFor(hours) + 4} y={pad.top + 10} fontSize="9" fill="#c2410c">
                        required {hours}h
                    </text>
                </g>
            ))}

            {points.map((p) => (
                <g key={p.strategy_id}>
                    <circle
                        cx={xFor(p.rto_achievable_hours)}
                        cy={yFor(p.cost_minor)}
                        r={p.is_selected ? 7 : 5}
                        fill={p.meets_requirement === false ? '#dc2626' : p.meets_requirement === true ? '#16a34a' : '#94a3b8'}
                        fillOpacity={p.is_selected ? 1 : 0.65}
                        stroke={p.is_selected ? '#0f172a' : 'none'}
                        strokeWidth="1.5"
                    >
                        <title>
                            {`${p.process_code} — ${p.strategy_label}\n${money(p.cost_minor)} · recovers in ${p.rto_achievable_hours}h`
                                + (p.rto_required_hours != null ? `\nrequired ${p.rto_required_hours}h` : '')}
                        </title>
                    </circle>
                </g>
            ))}

            <line x1={pad.left} x2={width - pad.right} y1={pad.top + plotH} y2={pad.top + plotH} stroke="#cbd5e1" />
            <line x1={pad.left} x2={pad.left} y1={pad.top} y2={pad.top + plotH} stroke="#cbd5e1" />

            <text x={pad.left + plotW / 2} y={height - 12} textAnchor="middle" fontSize="10" fill="#5b6773">
                Achievable recovery time (hours)
            </text>
            <text x={14} y={pad.top + plotH / 2} textAnchor="middle" fontSize="10" fill="#5b6773"
                transform={`rotate(-90 14 ${pad.top + plotH / 2})`}>
                Estimated cost
            </text>
        </svg>
    );
}
