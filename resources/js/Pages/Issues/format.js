/** Shared formatting for the issue pages (migration Phase 4.4). */

export function titleCase(value) {
    return value
        ? value.charAt(0).toUpperCase() + value.slice(1).toLowerCase().replace(/_/g, ' ')
        : '—';
}

export const PRIORITY_TONE = {
    critical: 'badge-critical',
    high: 'badge-high',
    medium: 'badge-medium',
    low: 'badge-low',
};

export function priorityTone(priority) {
    return PRIORITY_TONE[String(priority ?? '').toLowerCase()] ?? 'bg-gray-100 text-gray-600';
}

export const STATUS_TONE = {
    OPEN: 'bg-blue-100 text-blue-700',
    IN_PROGRESS: 'bg-yellow-100 text-yellow-700',
    OVERDUE: 'bg-red-100 text-red-700',
    PENDING_CLOSURE: 'bg-purple-100 text-purple-700',
    CLOSED: 'bg-green-100 text-green-700',
    CANCELLED: 'bg-gray-100 text-gray-600',
    REOPENED: 'bg-orange-100 text-orange-700',
};

export function statusTone(status) {
    return STATUS_TONE[String(status ?? '').toUpperCase()] ?? 'bg-gray-100 text-gray-600';
}

/** The band colours, shared by the dashboard and the ageing report. */
export const BAND_COLORS = ['#2D7D46', '#D4AF37', '#DD6B20', '#C53030'];
