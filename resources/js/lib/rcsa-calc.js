/**
 * The RCSA calculation engine, mirrored for the browser.
 *
 * THIS IS A DISPLAY MIRROR, NOT A SECOND SOURCE OF TRUTH. The grid calls it so
 * that picking a likelihood repaints the residual badge in the same frame
 * instead of after a round trip. The server recomputes on every save through
 * App\Services\Rcsa\RcsaCalculationService and its value is what is stored —
 * the client never persists a number it worked out alone.
 *
 * The two are kept honest by resources/js/lib/rcsa-truth-table.json: 100 cases
 * that tests/Unit/Rcsa/RcsaCalculationTest runs through BOTH implementations,
 * the PHP one directly and this one under node. A change to either that moves a
 * number turns that test red.
 *
 * If you change anything here, change RcsaCalculationService in the same commit.
 */

/**
 * @typedef {object} RcsaBand
 * @property {string} level      Band code, e.g. "very_high".
 * @property {string} label      Display label, e.g. "Very High".
 * @property {number} minScore   Inclusive lower bound.
 * @property {number} maxScore   Inclusive upper bound.
 * @property {string|null} colour
 * @property {string} treatment  accept | mitigate | treat
 * @property {string} appetiteStatus
 */

/**
 * @typedef {object} RcsaMethodologyPayload
 * @property {RcsaBand[]} bands                    Ascending by minScore.
 * @property {{label: string, modifier: number}[]} controlEffectiveness
 * @property {{value: number, label: string}[]} likelihood
 * @property {{value: number, label: string}[]} impact
 * @property {'calculated'|'assessed'|'hybrid'} residualMode
 * @property {number} residualFloor
 * @property {string} appetiteCeilingLevel
 */

const normalise = (value) =>
  typeof value === 'string' ? value.trim().replace(/\s+/g, ' ').toLowerCase() : '';

/**
 * The band containing `score`.
 *
 * Clamps rather than returning null, exactly as RcsaMethodology::bandFor does:
 * a score outside every band renders at the nearest end rather than as a blank
 * badge. The bands are assumed ascending — the server always sends them that
 * way — but they are sorted here anyway, because a payload assembled by hand in
 * a future screen should not be able to silently mis-band a whole grid.
 */
export function bandFor(score, bands) {
  if (!Array.isArray(bands) || bands.length === 0) return null;

  const ordered = [...bands].sort((a, b) => a.minScore - b.minScore);
  const hit = ordered.find((band) => score >= band.minScore && score <= band.maxScore);

  if (hit) return hit;

  return score < ordered[0].minScore ? ordered[0] : ordered[ordered.length - 1];
}

/** How high a band sits, 0-based from the bottom, or null if unknown. */
export function bandRank(level, bands) {
  if (level === null || level === undefined) return null;

  const ordered = [...bands].sort((a, b) => a.minScore - b.minScore);
  const index = ordered.findIndex((band) => band.level === level);

  return index === -1 ? null : index;
}

/**
 * Whether a residual band sits above the appetite ceiling.
 *
 * Derived from the CEILING, not from the band's own sentence — see the long
 * note on RcsaMethodology::isAboveAppetite. The sentence is what the user
 * reads; the ceiling is what the obligation follows.
 */
export function isAboveAppetite(level, methodology) {
  const rank = bandRank(level, methodology.bands);
  const ceiling = bandRank(methodology.appetiteCeilingLevel, methodology.bands);

  if (rank === null || ceiling === null) return false;

  return rank > ceiling;
}

/** The control-effectiveness rating matching `label`, case- and space-insensitively. */
export function controlEffectivenessFor(label, methodology) {
  const needle = normalise(label);
  if (needle === '') return null;

  return (
    (methodology.controlEffectiveness || []).find((item) => normalise(item.label) === needle) || null
  );
}

/** A rating that exists on the named scale, or null. Rejects, never clamps. */
function validRating(value, scale) {
  if (value === null || value === undefined || value === '') return null;

  const number = Number(value);
  if (!Number.isInteger(number)) return null;

  return (scale || []).some((item) => item.value === number) ? number : null;
}

/**
 * Compute the calculated columns for one line.
 *
 * Mirrors RcsaCalculationService::calculate, including its refusals: partial
 * input computes what it can rather than throwing, an out-of-range rating is
 * treated as unassessed rather than clamped, the residual is never rounded
 * before it is banded, and the floor is not applied to an assessed residual.
 *
 * @param {object} input
 * @param {number|null} input.likelihood
 * @param {number|null} input.impact
 * @param {string|null} input.controlEffectiveness
 * @param {number|null} [input.residualLikelihood]
 * @param {number|null} [input.residualImpact]
 * @param {RcsaMethodologyPayload} methodology
 */
export function calculate(input, methodology) {
  const likelihood = validRating(input.likelihood, methodology.likelihood);
  const impact = validRating(input.impact, methodology.impact);
  const rating = controlEffectivenessFor(input.controlEffectiveness, methodology);

  const inherentScore = likelihood !== null && impact !== null ? likelihood * impact : null;
  const inherentBand = inherentScore !== null ? bandFor(inherentScore, methodology.bands) : null;

  const modifier = rating ? rating.modifier : null;

  let residualScore = null;
  let residualFloored = false;
  let residualAssessed = false;

  const assessed = assessedResidual(input, methodology);

  if (assessed !== null) {
    residualScore = assessed;
    residualAssessed = true;
  } else if (inherentScore !== null && modifier !== null) {
    const formula = inherentScore * (1 - modifier / 100);
    const floor = Math.min(Number(methodology.residualFloor) || 0, inherentScore);

    residualScore = Math.max(formula, floor);
    residualFloored = residualScore > formula;
  }

  // The only rounding in the engine, and it happens after the formula and
  // before banding so that the value banded is the value stored.
  if (residualScore !== null) {
    residualScore = Math.round(residualScore * 100) / 100;
  }

  const residualBand = residualScore !== null ? bandFor(residualScore, methodology.bands) : null;

  return {
    inherentScore,
    inherentLevel: inherentBand ? inherentBand.level : null,
    inherentLabel: inherentBand ? inherentBand.label : null,
    inherentColour: inherentBand ? inherentBand.colour : null,
    controlEffectiveness: rating ? rating.label : null,
    ceModifier: modifier,
    residualScore,
    residualLevel: residualBand ? residualBand.level : null,
    residualLabel: residualBand ? residualBand.label : null,
    residualColour: residualBand ? residualBand.colour : null,
    riskTreatment: residualBand ? residualBand.treatment : null,
    appetiteStatus: residualBand ? residualBand.appetiteStatus : null,
    actionPlanRequired: residualBand ? isAboveAppetite(residualBand.level, methodology) : false,
    isComplete: residualBand !== null,
    residualFloored,
    residualAssessed,
  };
}

/** The residual the assessor supplied, if the methodology accepts one. */
function assessedResidual(input, methodology) {
  if (methodology.residualMode === 'calculated') return null;

  const likelihood = validRating(input.residualLikelihood, methodology.likelihood);
  const impact = validRating(input.residualImpact, methodology.impact);

  if (likelihood === null || impact === null) return null;

  return likelihood * impact;
}

export default calculate;
