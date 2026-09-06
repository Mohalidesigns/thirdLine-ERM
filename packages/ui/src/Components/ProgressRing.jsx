export default function ProgressRing({ percentage, value, max = 100, size = 80, strokeWidth = 6, color = '#2D7D46' }) {
    // Accept either a direct `percentage` or a `value`/`max` pair.
    const raw = percentage != null
        ? percentage
        : (value != null && max ? (value / max) * 100 : 0);
    const pct = Math.max(0, Math.min(100, Math.round(Number(raw) || 0)));

    const radius = (size - strokeWidth) / 2;
    const circumference = radius * 2 * Math.PI;
    const offset = circumference - (pct / 100) * circumference;

    return (
        <div className="relative inline-flex items-center justify-center">
            <svg width={size} height={size} className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="#E2E8F0" strokeWidth={strokeWidth} />
                <circle
                    cx={size / 2} cy={size / 2} r={radius} fill="none" stroke={color}
                    strokeWidth={strokeWidth} strokeDasharray={circumference} strokeDashoffset={offset}
                    strokeLinecap="round" className="transition-all duration-1000"
                />
            </svg>
            <span className="absolute text-lg font-bold text-[var(--color-text-primary)]">{pct}%</span>
        </div>
    );
}
