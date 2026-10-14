/**
 * How the RCSA screens write an enum value for a person (RCSA-09).
 *
 * The VALUE stays what the server sent — `ad_hoc`, `in_review` — because it is
 * what the form posts and what the filters send back. Only the text on the
 * glass changes. One helper, so a select and a badge cannot disagree about
 * whether it is "Ad hoc" or "Ad Hoc".
 *
 * Sentence case, not Title Case Of Every Word: "In review", "Ad hoc". The few
 * values that contain an initialism need the explicit map below, because no
 * rule can tell that `bu_approval` is a business unit and not a person called Bu.
 */
const EXPLICIT = {
    bu_approval: 'BU approval',
    orm: 'ORM',
    kri: 'KRI',
};

export function humanise(value) {
    if (value === null || value === undefined || value === '') return '';

    const key = String(value);

    if (Object.hasOwn(EXPLICIT, key)) return EXPLICIT[key];

    const words = key.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The words for an ASSESSMENT's status (RCSA-05).
 *
 * An assessment is created `draft` when its cycle opens and becomes
 * `in_progress` on the first saved rating (the `start` event), so `draft` here means nobody has
 * started — "Not started". It is a separate helper, and not a change to
 * `humanise('draft')`, because a CYCLE's `draft` is still "Draft" (it has not
 * been opened) and `humanise` is also what writes every cycle and action-plan
 * label. Call this wherever the thing being labelled is an assessment.
 */
export function assessmentStatusLabel(status) {
    if (status === 'draft') return 'Not started';

    return humanise(status);
}
