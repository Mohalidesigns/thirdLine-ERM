/**
 * Two-series grouped bars (pure CSS, no chart dependency — house convention).
 *
 * Replaces the Chart.js bar chart comparing an assessment with the one before
 * it. `series` is one or two `{ label, values, color }` entries sharing the
 * same `labels` axis.
 */
export default function GroupedBarChart({ labels = [], series = [], max = 5 }) {
    if (labels.length === 0) return null;

    return (
        <div className="space-y-3">
            {labels.map((label, index) => (
                <div key={label}>
                    <div className="flex items-center justify-between text-xs mb-1">
                        <span className="text-gray-600">{label}</span>
                        <span className="font-semibold text-gray-800">
                            {series.map((entry) => entry.values[index] ?? 0).join(' / ')}
                        </span>
                    </div>
                    <div className="space-y-1">
                        {series.map((entry) => (
                            <div key={entry.label} className="h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div
                                    className="h-full rounded-full transition-all duration-500"
                                    style={{
                                        width: `${(Math.max(0, Math.min(max, entry.values[index] ?? 0)) / max) * 100}%`,
                                        backgroundColor: entry.color,
                                        opacity: entry.dashed ? 0.45 : 1,
                                    }}
                                />
                            </div>
                        ))}
                    </div>
                </div>
            ))}

            <div className="flex items-center gap-4 pt-1">
                {series.map((entry) => (
                    <span key={entry.label} className="flex items-center gap-1.5 text-xs text-gray-600">
                        <span
                            className="w-3 h-0.5 rounded"
                            style={{ backgroundColor: entry.color, opacity: entry.dashed ? 0.45 : 1 }}
                        />
                        {entry.label}
                    </span>
                ))}
            </div>
        </div>
    );
}
