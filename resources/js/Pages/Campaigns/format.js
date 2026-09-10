/** Shared presentation helpers for the campaign and questionnaire pages (Phase 4.5). */

export function titleCase(value) {
    if (!value) return '—';

    return String(value)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * The one control-effectiveness vocabulary — the mirror of
 * App\Models\CampaignResponse::EFFECTIVENESS.
 *
 * The respond form used to offer a fifth value, `not_applicable`, which the
 * read-back had no label for and printed as an em dash. `not_applicable` is
 * kept HERE and only here: rows stored before Phase 4.5 still hold it, and a
 * historic answer should read as what the respondent chose rather than as a
 * blank. Nothing offers it as a new choice.
 */
export const EFFECTIVENESS_OPTIONS = [
    { value: 'effective', label: 'Effective' },
    { value: 'partially_effective', label: 'Partially Effective' },
    { value: 'ineffective', label: 'Ineffective' },
    { value: 'not_tested', label: 'Not Tested' },
];

const EFFECTIVENESS_TONE = {
    effective: 'text-green-700 bg-green-50',
    partially_effective: 'text-yellow-700 bg-yellow-50',
    ineffective: 'text-red-700 bg-red-50',
    not_tested: 'text-gray-600 bg-gray-100',
    not_applicable: 'text-gray-600 bg-gray-100',
};

export function effectivenessLabel(value) {
    if (!value) return null;

    const known = EFFECTIVENESS_OPTIONS.find((option) => option.value === value);

    return {
        label: known ? known.label : titleCase(value),
        tone: EFFECTIVENESS_TONE[value] ?? 'text-gray-600 bg-gray-100',
    };
}

export const LIKELIHOOD_LABELS = ['', 'Rare', 'Unlikely', 'Possible', 'Likely', 'Almost Certain'];

export const IMPACT_LABELS = ['', 'Insignificant', 'Minor', 'Moderate', 'Major', 'Catastrophic'];

export const INPUT =
    'form-input mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]';

export const SELECT = INPUT.replace('form-input', 'form-select') + ' bg-white';
