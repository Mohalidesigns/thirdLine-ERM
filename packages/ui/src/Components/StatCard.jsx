export default function StatCard({ title, value, subtitle, icon, color = 'blue', trend }) {
    const colorClasses = {
        blue: 'bg-blue-50 text-blue-600',
        red: 'bg-red-50 text-red-600',
        green: 'bg-green-50 text-green-600',
        amber: 'bg-amber-50 text-amber-600',
        purple: 'bg-purple-50 text-purple-600',
        teal: 'bg-teal-50 text-teal-600',
    };

    return (
        <div className="stat-card">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-sm font-medium text-gray-500">{title}</p>
                    <p className="text-2xl font-bold mt-1 text-[var(--color-text-primary)]">{value}</p>
                    {subtitle && <p className="text-xs text-gray-500 mt-1">{subtitle}</p>}
                </div>
                <div className={`w-10 h-10 rounded-lg flex items-center justify-center ${colorClasses[color]}`}>
                    {icon}
                </div>
            </div>
            {trend && (
                <div className="mt-3 flex items-center gap-1">
                    <span className={`text-xs font-medium ${trend.up ? 'text-green-600' : 'text-red-600'}`}>
                        {trend.up ? '+' : ''}{trend.value}%
                    </span>
                    <span className="text-xs text-gray-500">vs last quarter</span>
                </div>
            )}
        </div>
    );
}
