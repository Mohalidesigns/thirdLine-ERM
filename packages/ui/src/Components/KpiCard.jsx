import { isValidElement } from 'react';
import Icon from './Icon';

/**
 * The React face of the ThirdLine stat card: label, value, sub-line, and a
 * tinted 40px icon tile on the right. Semantic colour lives in the tile only;
 * the value is always the primary text colour. A status that must be read
 * (breach, overdue) goes in `subtitle` as words, never by recolouring the
 * number.
 *
 * `value` must never be a placeholder 0 while loading - absence is
 * `unavailable`, which renders the label, not a number.
 *
 * Reference grid: grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6
 */
const TILES = {
    primary: 'bg-blue-50 text-blue-600',
    info: 'bg-blue-50 text-blue-600',
    blue: 'bg-blue-50 text-blue-600',
    danger: 'bg-red-50 text-red-600',
    red: 'bg-red-50 text-red-600',
    // yellow-600 on yellow-50 is 2.9:1; the glyph needs 3:1, so -700.
    warning: 'bg-yellow-50 text-yellow-700',
    amber: 'bg-amber-50 text-amber-600',
    success: 'bg-green-50 text-green-600',
    green: 'bg-green-50 text-green-600',
    purple: 'bg-purple-50 text-purple-600',
    teal: 'bg-teal-50 text-teal-600',
};

export default function KpiCard({ title, value, icon, color = 'primary', subtitle, unavailable = false, unavailableLabel = 'Not assessed' }) {
    const tile = unavailable ? 'bg-gray-50 text-gray-400' : TILES[color] ?? TILES.primary;

    return (
        <div className="stat-card">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-gray-500 truncate">{title}</p>
                    {unavailable ? (
                        <p className="text-lg font-semibold italic text-gray-500 mt-1">{unavailableLabel}</p>
                    ) : (
                        <p className={`${String(value ?? '').length > 13 ? 'text-lg' : 'text-2xl'} font-bold mt-1 text-[var(--color-text-primary)] break-words`}>
                            {value}
                        </p>
                    )}
                    {subtitle && <p className="text-xs text-gray-500 mt-1 line-clamp-2">{subtitle}</p>}
                </div>
                {icon && (
                    <div className={`w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 ${tile}`} aria-hidden="true">
                        {isValidElement(icon) ? icon : <Icon name={icon} className="w-5 h-5" />}
                    </div>
                )}
            </div>
        </div>
    );
}
