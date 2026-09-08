<?php

namespace App\Services\Rcsa;

/**
 * The computed half of an RCSA line — workbook columns L, M, P, Q, R, S and T,
 * plus the one derived flag the workbook has no column for.
 *
 * Immutable, and deliberately dumb: it holds numbers RcsaCalculationService has
 * already worked out and knows how to name itself for a payload. Nothing in
 * here calculates, so there is no second place for the engine to live.
 *
 * EVERY FIELD IS NULLABLE except $actionPlanRequired and $isComplete, because a
 * line is scored a cell at a time. An assessor who has picked a likelihood and
 * nothing else must still be able to save, and the grid must still be able to
 * render that row — showing empty computed cells, not a crash and not a zero.
 * A zero would be worse than empty: 0 bands as VERY LOW, and an unassessed risk
 * that displays as VERY LOW is exactly the failure the module exists to prevent.
 */
final readonly class RcsaResult
{
    /**
     * @param  int|null  $inherentScore  Column L — likelihood × impact, 1-25.
     * @param  string|null  $inherentLevel  Column M — band level code.
     * @param  string|null  $inherentLabel  Column M as displayed.
     * @param  string|null  $controlEffectiveness  Column O, canonicalised.
     * @param  int|null  $ceModifier  Column P — 100 / 75 / 50 / 25.
     * @param  float|null  $residualScore  Column Q — fractional, unrounded.
     * @param  string|null  $residualLevel  Column R — band level code.
     * @param  string|null  $residualLabel  Column R as displayed.
     * @param  string|null  $riskTreatment  Column S — treat / mitigate / accept.
     * @param  string|null  $appetiteStatus  Column T — the sentence.
     * @param  bool  $actionPlanRequired  Derived: the line is above appetite.
     * @param  bool|null  $aboveAppetite  The same fact, STORED — null until scored.
     * @param  bool  $isComplete  All three assessed inputs are present.
     * @param  bool  $residualFloored  The floor, not the formula, set the residual.
     * @param  bool  $residualAssessed  The residual came from the assessor, not the formula.
     */
    public function __construct(
        public ?int $inherentScore = null,
        public ?string $inherentLevel = null,
        public ?string $inherentLabel = null,
        public ?string $inherentColour = null,
        public ?string $controlEffectiveness = null,
        public ?int $ceModifier = null,
        public ?float $residualScore = null,
        public ?string $residualLevel = null,
        public ?string $residualLabel = null,
        public ?string $residualColour = null,
        public ?string $riskTreatment = null,
        public ?string $appetiteStatus = null,
        public bool $actionPlanRequired = false,
        public ?bool $aboveAppetite = null,
        public bool $isComplete = false,
        public bool $residualFloored = false,
        public bool $residualAssessed = false,
    ) {}

    /**
     * The columns as they are written to `rcsa_assessment_lines`.
     *
     * Exactly the calculated columns and nothing else, so a caller can splat it
     * over a line's attributes without also overwriting the assessor's inputs.
     *
     * @return array<string, mixed>
     */
    public function toLineColumns(): array
    {
        return [
            'inherent_score' => $this->inherentScore,
            'inherent_level' => $this->inherentLevel,
            'ce_modifier' => $this->ceModifier,
            'residual_score' => $this->residualScore,
            'residual_level' => $this->residualLevel,
            'risk_treatment' => $this->riskTreatment,
            'appetite_status' => $this->appetiteStatus,

            // Stored, not derived at read time, because appetite stopped being
            // a property of the BAND when §14 Q4 made it a property of the band
            // AND the category. Three services used to reconstruct this with
            // `whereIn('residual_level', $levelsAboveAppetite)`, which cannot
            // express "VERY LOW is above appetite for Compliance and inside it
            // for Strategic". The engine already knows the answer; this is it
            // written down.
            //
            // NULL, not false, on an unscored line: "no residual band yet" and
            // "inside appetite" are different states and the dashboards count
            // them separately.
            'above_appetite' => $this->aboveAppetite,
        ];
    }

    /**
     * The shape the React grid and the /calculate endpoint receive.
     *
     * camelCase, and it carries the labels and colours the columns do not, so
     * that a badge can be rendered without the client holding its own copy of
     * the band table.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'inherentScore' => $this->inherentScore,
            'inherentLevel' => $this->inherentLevel,
            'inherentLabel' => $this->inherentLabel,
            'inherentColour' => $this->inherentColour,
            'controlEffectiveness' => $this->controlEffectiveness,
            'ceModifier' => $this->ceModifier,
            'residualScore' => $this->residualScore,
            'residualLevel' => $this->residualLevel,
            'residualLabel' => $this->residualLabel,
            'residualColour' => $this->residualColour,
            'riskTreatment' => $this->riskTreatment,
            'appetiteStatus' => $this->appetiteStatus,
            'actionPlanRequired' => $this->actionPlanRequired,
            'isComplete' => $this->isComplete,
            'residualFloored' => $this->residualFloored,
            'residualAssessed' => $this->residualAssessed,
        ];
    }
}
