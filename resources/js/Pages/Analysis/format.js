/** Shared helpers for the analysis pages (migration Phase 5.1). */

export function titleCase(value) {
    if (!value) return '—';

    return String(value).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * The colour of a heat-map cell, from the tenant's own scoring profile.
 *
 * The bands arrive with the page — `{code,label,color,min,max}` — because the
 * profile is the single definition of what a score means (WP-05). Nothing here
 * assumes 5×5 or a fixed palette: a bank on a 4×6 matrix with three bands gets
 * three colours.
 */
export function bandFor(score, bands = []) {
    return bands.find((band) => score >= (band.min ?? 1) && score <= (band.max ?? Infinity)) ?? null;
}

export const SELECT =
    'form-select mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]';

export const INPUT = SELECT.replace('form-select', 'form-input').replace(' bg-white', '');

/** Tone classes for a control-effectiveness label, the product's four values. */
export function effectivenessTone(value) {
    return {
        effective: 'bg-green-50 text-green-700',
        partially: 'bg-yellow-50 text-yellow-700',
        ineffective: 'bg-red-50 text-red-700',
        unrated: 'bg-gray-100 text-gray-600',
    }[value] ?? 'bg-gray-100 text-gray-600';
}

/**
 * The four band series every analysis chart draws, in TrendChart's shape.
 *
 * TrendChart takes `data: [{month, <key>: n}]` and `series: [{key,label,color}]`;
 * the services return parallel arrays keyed by band. This is the adapter, in
 * one place, so the four charts cannot disagree about which colour Critical is.
 */
export const BAND_SERIES = [
    { key: 'critical', label: 'Critical', color: '#dc2626' },
    { key: 'high', label: 'High', color: '#f97316' },
    { key: 'medium', label: 'Medium', color: '#eab308' },
    { key: 'low', label: 'Low', color: '#22c55e' },
];

export function bandSeriesData(series) {
    return (series?.labels ?? []).map((label, index) => ({
        month: label,
        critical: series.critical?.[index] ?? 0,
        high: series.high?.[index] ?? 0,
        medium: series.medium?.[index] ?? 0,
        low: series.low?.[index] ?? 0,
    }));
}

/** A single-value series (average score, counts) in TrendChart's shape. */
export function singleSeriesData(series, key = 'value') {
    return (series?.labels ?? []).map((label, index) => ({
        month: label,
        [key]: series.values?.[index] ?? 0,
    }));
}
