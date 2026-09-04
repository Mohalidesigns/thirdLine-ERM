/** Shared formatting for the loss-event pages (migration Phase 4.3). */

/** Naira in full — a loss figure is compared, not glanced at. */
export function naira(amount, currency = 'NGN') {
    const symbol = currency === 'NGN' ? '₦' : `${currency} `;

    return `${symbol}${(Number(amount) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Naira abbreviated, so a nine-figure loss fits inside a KPI card. */
export function compactNaira(amount) {
    const value = Number(amount) || 0;
    const magnitude = Math.abs(value);

    if (magnitude >= 1e9) return `₦${(value / 1e9).toFixed(2)}bn`;
    if (magnitude >= 1e6) return `₦${(value / 1e6).toFixed(2)}m`;
    if (magnitude >= 1e3) return `₦${(value / 1e3).toFixed(1)}k`;

    return naira(value);
}

export function titleCase(value) {
    return value
        ? value.charAt(0).toUpperCase() + value.slice(1).toLowerCase().replace(/_/g, ' ')
        : '—';
}

export const SEVERITY_TONE = {
    CATASTROPHIC: 'badge-critical',
    MAJOR: 'badge-high',
    MODERATE: 'badge-medium',
    MINOR: 'badge-low',
    INSIGNIFICANT: 'badge-low',
};

export function severityTone(severity) {
    return SEVERITY_TONE[String(severity ?? '').toUpperCase()] ?? 'bg-gray-100 text-gray-600';
}

export const STATUS_TONE = {
    REPORTED: 'bg-blue-100 text-blue-700',
    UNDER_INVESTIGATION: 'bg-yellow-100 text-yellow-700',
    PENDING_APPROVAL: 'bg-purple-100 text-purple-700',
    APPROVED: 'bg-green-100 text-green-700',
    CLOSED: 'bg-gray-100 text-gray-600',
    REOPENED: 'bg-orange-100 text-orange-700',
    ESCALATED: 'bg-red-100 text-red-700',
};

export function statusTone(status) {
    return STATUS_TONE[String(status ?? '').toUpperCase()] ?? 'bg-gray-100 text-gray-600';
}
