/**
 * Horizontal bar chart (pure CSS, no chart dependency — house convention).
 * data: [{ label, count }]
 */
export default function HBarChart({ data = [], color = 'var(--color-primary)', formatLabel }) {
    if (!data.length) return null;

    const max = Math.max(1, ...data.map(d => d.count));

    return (
        <div className="space-y-2.5">
            {data.map(row => (
                <div key={row.label}>
                    <div className="flex items-center justify-between text-xs mb-0.5">
                        <span className="text-gray-600 capitalize">
                            {formatLabel ? formatLabel(row.label) : String(row.label).replace(/_/g, ' ')}
                        </span>
                        <span className="font-semibold text-gray-800">{row.count}</span>
                    </div>
                    <div className="h-2 rounded-full bg-gray-100 overflow-hidden">
                        <div
                            className="h-full rounded-full transition-all duration-500"
                            style={{ width: `${(row.count / max) * 100}%`, backgroundColor: color }}
                        ></div>
                    </div>
                </div>
            ))}
        </div>
    );
}
