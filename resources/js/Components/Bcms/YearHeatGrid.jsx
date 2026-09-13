/**
 * Twelve months of testing density — the board's view of the year.
 *
 * ITS QUESTION IS "ARE WE CLUSTERING EVERYTHING INTO Q4", not "what is on the
 * 14th". So it draws one cell per month sized by count and split by status, and
 * deliberately does not draw individual days: a 365-cell grid answers the wrong
 * question and takes far longer to read.
 *
 * Inline SVG, no chart dependency — the house convention
 * (Components/Quantification/SeriesChart).
 */
export default function YearHeatGrid({ grid = {}, colours = {}, onSelectMonth }) {
    const months = grid.months ?? [];
    const max = Math.max(...months.map((m) => m.total), 1);

    if (months.length === 0) return null;

    const width = 720;
    const height = 190;
    const pad = { top: 26, right: 12, bottom: 42, left: 12 };
    const cellW = (width - pad.left - pad.right) / 12;
    const plotH = height - pad.top - pad.bottom;

    return (
        <div>
            <svg viewBox={`0 0 ${width} ${height}`} className="w-full" role="img" aria-label="Exercises by month">
                {months.map((m, i) => {
                    const x = pad.left + i * cellW;
                    let y = pad.top + plotH;

                    const segments = Object.entries(m.by_status ?? {});

                    return (
                        <g key={m.month} onClick={() => onSelectMonth?.(m.month)} className={onSelectMonth ? 'cursor-pointer' : undefined}>
                            <rect
                                x={x + 3} y={pad.top} width={cellW - 6} height={plotH}
                                fill="#f4f6f9" rx="3"
                            />
                            {segments.map(([status, count]) => {
                                const h = (count / max) * plotH;
                                y -= h;

                                return (
                                    <rect
                                        key={status}
                                        x={x + 3} y={y} width={cellW - 6} height={h}
                                        fill={colours[status] ?? '#94a3b8'}
                                    >
                                        <title>{`${m.label}: ${count} ${status.replace(/_/g, ' ')}`}</title>
                                    </rect>
                                );
                            })}
                            <text x={x + cellW / 2} y={pad.top + plotH + 16} textAnchor="middle" fontSize="10" fill="#5b6773">
                                {m.label}
                            </text>
                            {m.total > 0 && (
                                <text x={x + cellW / 2} y={pad.top - 8} textAnchor="middle" fontSize="10" fill="#1f2933" fontWeight="600">
                                    {m.total}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>

            <div className="mt-2 flex flex-wrap gap-3 text-[11px] text-gray-600">
                {Object.entries(colours).map(([status, colour]) => (
                    <span key={status} className="flex items-center gap-1">
                        <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: colour }} />
                        {status.replace(/_/g, ' ')}
                    </span>
                ))}
            </div>
        </div>
    );
}
