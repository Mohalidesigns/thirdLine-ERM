/**
 * Shared formatting for the treatment plan pages (migration Phase 3.5).
 *
 * The Blade views declared the same `$compactNaira` closure twice, in
 * dashboard.blade.php and show.blade.php. One copy, here.
 */

/** Naira, abbreviated so a nine-figure budget fits inside a KPI card. */
export function compactNaira(amount) {
    const value = Number(amount) || 0;
    const magnitude = Math.abs(value);

    if (magnitude >= 1e9) return `₦${(value / 1e9).toFixed(2)}B`;
    if (magnitude >= 1e6) return `₦${(value / 1e6).toFixed(2)}M`;
    if (magnitude >= 1e3) return `₦${(value / 1e3).toFixed(1)}K`;

    return `₦${value.toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

/** Naira in full, for a figure being compared rather than glanced at. */
export function naira(amount) {
    return `₦${(Number(amount) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

/** The progress bar's colour, by how far along it is. */
export function progressTone(progress) {
    if (progress >= 75) return 'bg-green-500';
    if (progress >= 50) return 'bg-yellow-500';
    if (progress >= 25) return 'bg-orange-500';

    return 'bg-red-500';
}

/**
 * `daysRemaining` arrives signed from the server, negative once the target has
 * passed and null when there is no target at all. The wording is here.
 */
export function deadlineLabel(days) {
    if (days === null || days === undefined) return { value: 'N/A', color: 'primary' };
    if (days < 0) {
        const late = Math.abs(days);

        return { value: `Overdue by ${late} ${late === 1 ? 'day' : 'days'}`, color: 'danger' };
    }
    if (days === 0) return { value: 'Due today', color: 'warning' };

    return { value: `${days} ${days === 1 ? 'day' : 'days'}`, color: 'success' };
}

const STRATEGY_TONES = {
    mitigate: 'bg-blue-100 text-blue-700',
    transfer: 'bg-purple-100 text-purple-700',
    avoid: 'bg-red-100 text-red-700',
    accept: 'bg-green-100 text-green-700',
};

export function strategyTone(strategy) {
    return STRATEGY_TONES[strategy] ?? 'bg-gray-100 text-gray-700';
}

export function titleCase(value) {
    return value ? value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ') : 'N/A';
}
