export default function DonutChart({ data, size = 140, strokeWidth = 28 }) {
    const total = data.reduce((sum, d) => sum + d.value, 0);
    if (total === 0) {
        return (
            <div className="flex items-center justify-center h-40 text-gray-400 text-sm">
                No data available
            </div>
        );
    }
    let cumulative = 0;
    const radius = (size - strokeWidth) / 2;
    const circumference = radius * 2 * Math.PI;

    return (
        <div className="flex items-center gap-6">
            <div className="relative">
                <svg width={size} height={size} className="-rotate-90">
                    {data.map((item, idx) => {
                        const pct = (item.value / total) * 100;
                        const rotation = (cumulative / total) * 360;
                        cumulative += item.value;
                        return (
                            <circle
                                key={idx} cx={size / 2} cy={size / 2} r={radius} fill="none"
                                stroke={item.color} strokeWidth={strokeWidth}
                                strokeDasharray={`${circumference * (pct / 100)} ${circumference}`}
                                strokeDashoffset={0}
                                style={{ transform: `rotate(${rotation}deg)`, transformOrigin: '50% 50%' }}
                            />
                        );
                    })}
                </svg>
                <div className="absolute inset-0 flex items-center justify-center">
                    <div className="text-center">
                        <div className="text-xl font-bold">{total}</div>
                        <div className="text-[10px] text-gray-500">Total</div>
                    </div>
                </div>
            </div>
            <div className="space-y-2">
                {data.map((item, idx) => (
                    <div key={idx} className="flex items-center gap-2">
                        <span className="w-3 h-3 rounded-full flex-shrink-0" style={{ backgroundColor: item.color }}></span>
                        <span className="text-sm text-gray-600">{item.name}</span>
                        <span className="text-sm font-semibold ml-auto">{item.value}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
