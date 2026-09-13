/**
 * heatmap / opportunity_heatmap — CSS grid matrix, count badge per cell,
 * click-through to the filtered register. Colours arrive in the payload from
 * the scoring profile; nothing here assumes 5×5 or a palette.
 */
export default function Heatmap({ data }) {
    const rows = Number(data.rows || 0);
    const cols = Number(data.cols || 0);
    const axis = data.axis || { likelihood: {}, impact: {} };
    const cells = Array.isArray(data.cells) ? data.cells : [];
    const opportunity = data.polarity === 'opportunity';

    if (rows === 0 || cols === 0) {
        return <p className="py-6 text-center text-xs text-gray-400">No scoring profile available.</p>;
    }

    const chunks = [];
    for (let i = 0; i < cells.length; i += cols) chunks.push(cells.slice(i, i + cols));
    const impactValues = Object.keys(axis.impact || {});

    return (
        <div className="flex h-full flex-col">
            <div className="grid flex-1 gap-1" style={{ gridTemplateColumns: `auto repeat(${cols}, minmax(0, 1fr))` }}>
                {chunks.map((rowCells, r) => {
                    const first = rowCells[0] || {};
                    return [
                        <div key={`l-${r}`} className="flex items-center justify-end pr-2 text-right text-[10px] leading-tight text-gray-500">
                            {axis.likelihood?.[first.likelihood] ?? first.likelihood}
                        </div>,
                        ...rowCells.map((cell, c) => {
                            const Tag = cell.url ? 'a' : 'div';
                            return (
                                <Tag
                                    key={`c-${r}-${c}`}
                                    href={cell.url || undefined}
                                    className={`group relative flex min-h-9 items-center justify-center rounded ${
                                        cell.url ? 'cursor-pointer hover:ring-2 hover:ring-gray-900/30' : 'cursor-default'
                                    }`}
                                    style={{ backgroundColor: `${cell.color || '#e5e7eb'}${cell.count === 0 ? '55' : ''}` }}
                                    title={`L${cell.likelihood} × C${cell.impact} — ${cell.count}`}
                                >
                                    <span
                                        className={`rounded-full px-1.5 text-xs font-bold tabular-nums ${
                                            cell.count > 0 ? 'bg-white/85 text-gray-900 shadow-sm' : 'text-gray-500/60'
                                        }`}
                                    >
                                        {cell.count}
                                    </span>
                                </Tag>
                            );
                        }),
                    ];
                })}

                <div />
                {impactValues.map((value) => (
                    <div key={`i-${value}`} className="pt-1 text-center text-[10px] leading-tight text-gray-500">
                        {axis.impact?.[value] ?? value}
                    </div>
                ))}
            </div>

            <div className="mt-2 flex items-center justify-between text-[10px] text-gray-400">
                <span>{opportunity ? 'Likelihood ↑ · Benefit →' : 'Likelihood ↑ · Consequence →'}</span>
                <span>
                    {data.total ?? 0} {opportunity ? 'opportunities' : 'risks'}
                </span>
            </div>
        </div>
    );
}
