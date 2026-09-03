/**
 * The React face of resources/views/components/kpi-card.blade.php: the same
 * `.kpi-card` shell, the same icon/value colour maps.
 */
const ICON_COLORS = {
    primary: 'text-gray-400',
    danger: 'text-red-400',
    warning: 'text-yellow-500',
    success: 'text-green-500',
    info: 'text-blue-400',
};

const VALUE_COLORS = {
    primary: 'text-[#1A365D]',
    danger: 'text-red-600',
    warning: 'text-yellow-600',
    success: 'text-green-600',
    info: 'text-blue-600',
};

export default function KpiCard({ title, value, icon, color = 'primary', subtitle, unavailable = false, unavailableLabel = 'Not assessed' }) {
    const iconColor = unavailable ? 'text-gray-300' : ICON_COLORS[color] ?? ICON_COLORS.primary;
    const valueColor = VALUE_COLORS[color] ?? VALUE_COLORS.primary;

    return (
        <div className="kpi-card">
            <div className="flex items-center gap-2 mb-2">
                {icon && <span className={`material-symbols-outlined text-lg ${iconColor}`}>{icon}</span>}
                <span className="text-xs text-gray-500 font-medium">{title}</span>
            </div>
            {unavailable ? (
                <div className="text-gray-400 text-lg font-semibold italic">{unavailableLabel}</div>
            ) : (
                <div className={`${valueColor} text-2xl font-bold`}>{value}</div>
            )}
            {subtitle && <div className="text-xs text-gray-500 mt-1">{subtitle}</div>}
        </div>
    );
}
