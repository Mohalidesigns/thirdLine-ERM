<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaScaleItem;

/**
 * The RCSA calculation engine — the one place inherent risk, control
 * effectiveness, residual risk, treatment and appetite alignment are worked out.
 *
 * §3 of the implementation plan makes this a single-implementation rule: the
 * UI, the import pipeline, the API and the export writer all call this, and no
 * calculation logic is duplicated in React. The client mirror in
 * resources/js/lib/rcsa-calc.js exists ONLY to paint a cell before the round
 * trip completes; the server value always wins on save, and the shared truth
 * table in resources/js/lib/rcsa-truth-table.json is what stops the two
 * drifting. RcsaCalculationTest runs both implementations over that file.
 *
 * THE ENGINE, IN FULL:
 *
 *   inherent_score = likelihood × impact                            (1 … 25)
 *   inherent_level = the band containing that score
 *   ce_modifier    = the control rating's modifier (100/75/50/25)
 *   residual_score = MAX(inherent × (1 − modifier ÷ 100), floor)
 *   residual_level = the band containing THAT score, unrounded
 *   treatment      = the residual band's treatment
 *   appetite       = the residual band's statement
 *   action plan    = the residual band sits above the appetite ceiling
 *
 * IT IS PURE AND IT TOUCHES NO DATABASE. Everything it needs is on the
 * methodology it is handed, so scoring a 2,000-line assessment is one query for
 * the methodology and its scales, then 2,000 array lookups. Callers must
 * eager-load `scaleItems` and `bands`; `methodology()` below does it for them.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *
 *   It does not round before banding. Defect D3: residual 12.5 is a real value
 *   and rounding it moves risks between bands.
 *
 *   It does not reject partial input. A grid autosaves a cell at a time, so a
 *   line with a likelihood and nothing else must compute what it can (nothing)
 *   and say so through isComplete, rather than throwing at the assessor.
 *
 *   It does not apply the residual floor to an ASSESSED residual. The floor
 *   guards against the formula producing an implausible zero (defect D2); an
 *   assessor who has looked at the risk and rated the residual themselves has
 *   made a judgement, and silently raising their number would be the module
 *   overruling the assessor without telling them.
 */
class RcsaCalculationService
{
    /**
     * Compute the calculated columns for one line.
     *
     * @param  int|null  $likelihood  Column J — 1-5, or null if unassessed.
     * @param  int|null  $impact  Column K — 1-5, or null if unassessed.
     * @param  string|null  $controlEffectiveness  Column O — the rating LABEL, matched leniently.
     * @param  int|null  $residualLikelihood  ASSESSED / HYBRID mode only.
     * @param  int|null  $residualImpact  ASSESSED / HYBRID mode only.
     */
    public function calculate(
        ?int $likelihood,
        ?int $impact,
        ?string $controlEffectiveness,
        RcsaMethodology $methodology,
        ?int $residualLikelihood = null,
        ?int $residualImpact = null,
        ?string $riskCategory = null,
    ): RcsaResult {
        $likelihood = $this->validRating($likelihood, RcsaScaleItem::TYPE_LIKELIHOOD, $methodology);
        $impact = $this->validRating($impact, RcsaScaleItem::TYPE_IMPACT, $methodology);

        $rating = $methodology->controlEffectiveness($controlEffectiveness);

        /* --- Columns L and M ------------------------------------------- */

        $inherentScore = ($likelihood !== null && $impact !== null)
            ? $likelihood * $impact
            : null;

        $inherentBand = $inherentScore !== null
            ? $methodology->bandFor((float) $inherentScore)
            : null;

        /* --- Column P --------------------------------------------------- */

        $modifier = $rating?->modifier;

        /* --- Column Q --------------------------------------------------- */

        $assessedResidual = $this->assessedResidual(
            $methodology, $residualLikelihood, $residualImpact
        );

        $floored = false;

        if ($assessedResidual !== null) {
            $residualScore = $assessedResidual;
        } elseif ($inherentScore !== null && $modifier !== null) {
            $formula = $inherentScore * (1 - $modifier / 100);
            $floor = (float) $methodology->residual_floor;

            // The floor never LOWERS a residual, and it never raises one above
            // the inherent score it discounts: a floor of 5 on an inherent 3
            // would report a control making the risk worse.
            $floor = min($floor, (float) $inherentScore);

            $residualScore = max($formula, $floor);
            $floored = $residualScore > $formula;
        } else {
            $residualScore = null;
        }

        // Two decimal places, matching decimal(5,2) on the column. This is the
        // ONLY rounding in the engine and it happens AFTER the formula and
        // BEFORE banding purely so that the value banded is the value stored —
        // a score banded at full float precision and then truncated on write
        // could read back into a different band. The inputs make it a no-op
        // (every residual is a multiple of 0.25); it is here so that a
        // methodology with an odd floor or an assessed residual cannot break
        // the invariant.
        $residualScore = $residualScore !== null ? round($residualScore, 2) : null;

        $residualBand = $residualScore !== null
            ? $methodology->bandFor($residualScore)
            : null;

        /* --- Columns S and T -------------------------------------------- */

        // The category is what makes appetite a per-risk question rather than a
        // per-methodology one (§14 Q4). It is IGNORED in `single` mode, so
        // passing it costs nothing and forgetting to pass it costs nothing
        // either — until a tenant switches mode, at which point a caller that
        // never learned to pass it would silently score against the house
        // ceiling. Every caller in this module passes it; the parameter is
        // optional only so that the /calculate endpoint's own tests, and the
        // truth table, can go on calling the engine with six arguments.
        $aboveAppetite = $residualBand !== null
            && $methodology->isAboveAppetite($residualBand->level, $riskCategory);

        return new RcsaResult(
            inherentScore: $inherentScore,
            inherentLevel: $inherentBand?->level,
            inherentLabel: $inherentBand?->label,
            inherentColour: $inherentBand?->colour,
            controlEffectiveness: $rating?->label,
            ceModifier: $modifier,
            residualScore: $residualScore,
            residualLevel: $residualBand?->level,
            residualLabel: $residualBand?->label,
            residualColour: $residualBand?->colour,
            riskTreatment: $residualBand?->treatment,
            appetiteStatus: $residualBand?->appetite_status,
            actionPlanRequired: $aboveAppetite,
            aboveAppetite: $residualBand !== null ? $aboveAppetite : null,
            isComplete: $residualBand !== null,
            residualFloored: $floored,
            residualAssessed: $assessedResidual !== null,
        );
    }

    /**
     * The residual the ASSESSOR supplied, if this methodology accepts one and
     * they supplied a complete pair.
     *
     * CALCULATED mode ignores an assessed pair entirely rather than honouring
     * it, because in that mode the pair is not a permitted input: a stale value
     * left on a line by a methodology change must not quietly become the
     * residual score. Whether the bank wants ASSESSED mode at all is §14 Q1 and
     * is still open — the mode exists so that answering it is configuration.
     */
    private function assessedResidual(
        RcsaMethodology $methodology,
        ?int $residualLikelihood,
        ?int $residualImpact,
    ): ?float {
        if ($methodology->isCalculatedResidual()) {
            return null;
        }

        $likelihood = $this->validRating($residualLikelihood, RcsaScaleItem::TYPE_LIKELIHOOD, $methodology);
        $impact = $this->validRating($residualImpact, RcsaScaleItem::TYPE_IMPACT, $methodology);

        if ($likelihood === null || $impact === null) {
            return null;
        }

        return (float) ($likelihood * $impact);
    }

    /**
     * A rating that exists on the methodology's scale, or null.
     *
     * REJECTS RATHER THAN CLAMPS, which is the opposite of what
     * RiskScoringService does with an out-of-range rating, and the difference
     * is deliberate. That service re-rates a REGISTER whose rows were scored
     * under an older matrix, so clamping preserves a rating that already
     * exists. Here the input is a dropdown selection on a form or a cell in an
     * uploaded workbook: a 7 on a 5-point scale is a data error, and quietly
     * scoring it as a 5 would put a number in a regulatory return that nobody
     * chose. It comes back as unassessed, and the import validator and the
     * submission gate both report an unassessed line.
     */
    private function validRating(?int $value, string $type, RcsaMethodology $methodology): ?int
    {
        if ($value === null) {
            return null;
        }

        return isset($methodology->scale($type)[$value]) ? $value : null;
    }

    /**
     * The methodology to score against, with everything the engine reads
     * already loaded.
     *
     * Callers that already hold a methodology should pass it; this exists for
     * the ones that do not, and it eager-loads so that a caller cannot
     * accidentally make the engine issue a query per line.
     */
    public function methodology(?RcsaMethodology $methodology = null, ?int $organizationId = null): ?RcsaMethodology
    {
        $methodology ??= RcsaMethodology::active($organizationId);

        return $methodology?->loadMissing(['scaleItems', 'bands']);
    }
}
