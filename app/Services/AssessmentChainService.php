<?php

namespace App\Services;

use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentControl;
use App\Models\ScoringProfile;
use Illuminate\Support\Collection;

/**
 * The links in the assessment chain that had no code behind them.
 *
 *   … Inherent Risk → Existing Controls → Control Effectiveness →
 *     Residual Risk → …
 *
 * Before WP-10a the platform jumped from inherent risk straight to a residual
 * likelihood and impact typed in by hand. Controls were mapped to risks and
 * tested on their own schedule, but no assessment ever read them, so residual
 * risk was an assertion. This service makes it a derivation: the controls the
 * risk actually has, rated as they stood on the assessment date, aggregated by
 * their mapping weights, and applied to the inherent score through the
 * organization's own scoring profile.
 *
 * The assessor can still overrule the result — expert judgement is a legitimate
 * input and pretending otherwise just pushes it off-system. But an override is
 * recorded as one and has to carry a justification, so a reviewer can see at a
 * glance which residual scores are arithmetic and which are judgement.
 */
class AssessmentChainService
{
    /**
     * Which axis a control acts on.
     *
     * Preventive and directive controls stop the event happening, so their
     * assurance reduces LIKELIHOOD. Detective and corrective controls act once
     * it has started, limiting how bad it gets, so theirs reduces IMPACT.
     * That distinction is the whole reason `controls.control_type` is
     * collected, and until now nothing consumed it.
     */
    private const AXIS_BY_CONTROL_TYPE = [
        'preventive' => 'likelihood',
        'directive' => 'likelihood',
        'detective' => 'impact',
        'corrective' => 'impact',
    ];

    public function __construct(private RiskScoringService $scoring) {}

    /* ------------------------------------------------------------------ */
    /*  Step 6 — Existing Controls */
    /* ------------------------------------------------------------------ */

    /**
     * The controls to put in front of an assessor, pre-populated.
     *
     * Ratings come from the previous assessment where there is one — an
     * assessor's job is to confirm or change last quarter's judgement, not to
     * retype it — and from the control library otherwise. Everything is
     * returned as a plain array so the same shape serves a blank form, a draft
     * being edited, and a re-submission after review.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function controlsFor(Risk $risk, ?RiskAssessment $assessment = null): Collection
    {
        $existing = $assessment?->exists
            ? $assessment->assessedControls()->get()->keyBy('control_id')
            : collect();

        $previous = $this->previousRatings($risk, $assessment);

        return $risk->controls()->orderBy('control_code')->get()
            ->map(fn (Control $control) => $this->controlRow($control, $existing->get($control->id), $previous->get($control->id)))
            ->values();
    }

    /**
     * One control's row in the chain.
     *
     * Extracted from the map() closure above so its return type is declared
     * rather than inferred: Phase 3.2 gave Risk::controls() a generic type,
     * and without a declaration here PHPStan narrows the closure to the
     * precise array shape, which Collection's invariant value template will
     * not accept as controlsFor()'s declared array<string, mixed>.
     *
     * @return array<string, mixed>
     */
    private function controlRow(Control $control, ?RiskAssessmentControl $saved, ?RiskAssessmentControl $prior): array
    {
        // Precedence: what this assessment already holds, then what the last
        // one concluded, then the library's standing rating.
        $design = $saved->design_effectiveness
            ?? $prior?->design_effectiveness
            ?? $control->effectiveness_rating;

        $operating = $saved->operating_effectiveness
            ?? $prior?->operating_effectiveness
            ?? $control->effectiveness_rating;

        return [
            'control_id' => $control->id,
            'control_code' => $control->control_code,
            'control_name' => $control->name,
            'control_type' => $control->control_type,
            'axis' => $this->axisFor($control->control_type),
            'automation_level' => $control->automation_level,
            'last_test_date' => $control->last_test_date,
            'last_test_result' => $control->last_test_result,
            'library_rating' => $control->effectiveness_rating,
            'design_effectiveness' => $design,
            'operating_effectiveness' => $operating,
            'control_weight' => (float) ($control->pivot->control_weight ?? 1.0),
            'is_key_control' => (bool) ($control->pivot->is_key_control ?? false),
            'notes' => $saved?->notes,
            'evidence_ref' => $saved?->evidence_ref,
            // Drives the "changed since last assessment" highlight.
            'prior_design' => $prior?->design_effectiveness,
            'prior_operating' => $prior?->operating_effectiveness,
        ];
    }

    /**
     * Persist this assessment's control ratings.
     *
     * Rows are keyed by control id and confined to the controls actually mapped
     * to the risk, so a forged control id in the request body cannot attach a
     * another tenant's control to an assessment.
     *
     * @param  array<int, array<string, mixed>>  $input  keyed by control id
     */
    public function syncControls(RiskAssessment $assessment, array $input): Collection
    {
        $permitted = $assessment->risk->controls()->get()->keyBy('id');
        $ratings = array_keys(RiskAssessmentControl::RATINGS);

        $kept = [];

        foreach ($input as $controlId => $row) {
            $control = $permitted->get((int) $controlId);

            if ($control === null) {
                continue;
            }

            $design = in_array($row['design_effectiveness'] ?? null, $ratings, true)
                ? $row['design_effectiveness']
                : null;

            $operating = in_array($row['operating_effectiveness'] ?? null, $ratings, true)
                ? $row['operating_effectiveness']
                : null;

            $assessed = RiskAssessmentControl::updateOrCreate(
                [
                    'risk_assessment_id' => $assessment->id,
                    'control_id' => $control->id,
                ],
                [
                    'organization_id' => $assessment->organization_id,
                    'control_code' => $control->control_code,
                    'control_name' => $control->name,
                    'design_effectiveness' => $design,
                    'operating_effectiveness' => $operating,
                    'control_weight' => (float) ($control->pivot->control_weight ?? 1.0),
                    'is_key_control' => (bool) ($control->pivot->is_key_control ?? false),
                    'notes' => $row['notes'] ?? null,
                    'evidence_ref' => $row['evidence_ref'] ?? null,
                ],
            );

            $assessed->applyEffectiveness()->save();
            $kept[] = $assessed->id;
        }

        // A control unmapped from the risk mid-cycle should stop counting
        // toward this assessment's effectiveness.
        $assessment->assessedControls()->whereNotIn('id', $kept ?: [0])->delete();

        return $assessment->assessedControls()->get();
    }

    /* ------------------------------------------------------------------ */
    /*  The live preview (migration Phase 3.3) */
    /* ------------------------------------------------------------------ */

    /**
     * Score an unsaved chain, exactly as saving it would.
     *
     * This replaces the Alpine `assessmentChain()` component that used to run
     * the same arithmetic in the browser. That mirror had two problems. It was
     * a second implementation of impact aggregation, effectiveness weighting
     * and the axis split, free to drift from the server's; and it could not
     * evaluate a tenant's configured residual formula at all — it said so in a
     * comment and fell back to the platform default, so an organisation on a
     * custom formula watched one number while it typed and got a different one
     * on save.
     *
     * The equivalence here is structural rather than asserted: the input is
     * turned into UNSAVED RiskAssessmentControl instances, and then the same
     * effectiveness() and deriveResidual() the save path calls are called on
     * them. There is no second implementation to keep in step.
     *
     * @param  array<string, mixed>  $input  the chain as the form currently holds it
     * @return array<string, mixed>
     */
    public function preview(Risk $risk, array $input): array
    {
        $profile = $this->scoring->profileForRisk($risk);

        // Steps 4-5 — impact aggregation and inherent risk.
        $impacts = collect($profile->dimensions())
            ->mapWithKeys(fn (string $dimension) => [
                $dimension => $this->positiveInt(data_get($input, "impacts.{$dimension}")),
            ])
            ->all();

        $impactScore = $this->scoring->calculateImpact($impacts, $risk->organization_id, $profile);
        $likelihood = (int) ($this->positiveInt(data_get($input, 'likelihood')) ?? 0);
        $inherentScore = $this->scoring->calculateScore($likelihood, $impactScore, $profile);

        // Steps 6-7 — the control ratings, scored through the model that
        // stores them so the percentages and findings are the stored ones.
        $rated = $this->unsavedControls($risk, (array) data_get($input, 'controls', []));
        $effectiveness = $this->effectiveness($rated);

        // Step 8 — residual, through the organisation's own residual formula.
        $derived = $this->deriveResidual($likelihood, $impactScore, $inherentScore, $effectiveness, $profile);

        $override = $this->overrideFrom($input, $derived, $profile);

        return [
            'impacts' => $impacts,
            'impactScore' => $impactScore,
            'inherent' => [
                'likelihood' => $likelihood ?: null,
                'impact' => $impactScore ?: null,
                'score' => $inherentScore ?: null,
                'rating' => $inherentScore > 0 ? $this->scoring->calculateRating($inherentScore, $profile) : null,
            ],
            'controls' => $rated->map(fn (RiskAssessmentControl $row) => [
                'id' => (int) $row->control_id,
                'design' => $row->design_effectiveness,
                'operating' => $row->operating_effectiveness,
                'effective' => $row->effectiveness_pct === null ? null : (float) $row->effectiveness_pct,
                'finding' => $row->finding,
                'changed' => (bool) $row->getAttribute('changed_since_prior'),
            ])->values()->all(),
            'aggregate' => $effectiveness,
            'derived' => $derived,
            'residual' => $override ?? $derived,
            'isOverride' => $override !== null,
            // What the configured formula asked for before the matrix rounded
            // it to a cell, kept so the difference stays inspectable.
            'target' => $derived['target'] ?? null,
        ];
    }

    /**
     * The posted control ratings as unsaved RiskAssessmentControl rows.
     *
     * Confined to the controls actually mapped to the risk, for the reason
     * syncControls() is: a forged control id in the request body must not pull
     * another tenant's control into the arithmetic.
     *
     * @param  array<int|string, array<string, mixed>>  $input  keyed by control id
     * @return Collection<int, RiskAssessmentControl>
     */
    private function unsavedControls(Risk $risk, array $input): Collection
    {
        $permitted = $risk->controls()->get()->keyBy('id');
        $ratings = array_keys(RiskAssessmentControl::RATINGS);
        $previous = $this->previousRatings($risk, null);

        $rows = collect();

        foreach ($input as $controlId => $row) {
            $control = $permitted->get((int) $controlId);

            if ($control === null) {
                continue;
            }

            $design = in_array($row['design_effectiveness'] ?? null, $ratings, true)
                ? $row['design_effectiveness']
                : null;

            $operating = in_array($row['operating_effectiveness'] ?? null, $ratings, true)
                ? $row['operating_effectiveness']
                : null;

            $assessed = new RiskAssessmentControl([
                'organization_id' => $risk->organization_id,
                'control_id' => $control->id,
                'control_code' => $control->control_code,
                'control_name' => $control->name,
                'design_effectiveness' => $design,
                'operating_effectiveness' => $operating,
                'control_weight' => (float) (data_get($control, 'pivot.control_weight') ?? 1.0),
                'is_key_control' => (bool) data_get($control, 'pivot.is_key_control'),
            ]);

            // effectiveness() groups by the control's type, so the relation has
            // to be there without a query per row.
            $assessed->setRelation('control', $control);
            $assessed->applyEffectiveness();

            $prior = $previous->get($control->id);
            $assessed->setAttribute(
                'changed_since_prior',
                $prior !== null && (
                    $design !== $prior->design_effectiveness
                    || $operating !== $prior->operating_effectiveness
                ),
            );

            $rows->push($assessed);
        }

        return $rows;
    }

    /**
     * An assessor's own residual pair, but only where it actually differs from
     * the derivation — the same test applyToAssessment() applies, so a form
     * posting back the pre-filled derived values is not branded an override
     * by the preview either.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $derived
     * @return array<string, mixed>|null
     */
    private function overrideFrom(array $input, ?array $derived, ScoringProfile $profile): ?array
    {
        $likelihood = $this->positiveInt(data_get($input, 'residual_likelihood'));
        $impact = $this->positiveInt(data_get($input, 'residual_impact'));

        if ($likelihood === null || $impact === null) {
            return null;
        }

        $isReal = $derived === null
            || $likelihood !== $derived['likelihood']
            || $impact !== $derived['impact'];

        if (! $isReal) {
            return null;
        }

        $score = $this->scoring->calculateScore($likelihood, $impact, $profile);

        return [
            'likelihood' => $likelihood,
            'impact' => $impact,
            'score' => $score,
            'rating' => $this->scoring->calculateRating($score, $profile),
        ];
    }

    /** A scored axis value, or null for "not scored". */
    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Step 7 — Control Effectiveness */
    /* ------------------------------------------------------------------ */

    /**
     * This assessment's control effectiveness, overall and split by the axis
     * each control acts on.
     *
     * @return array{overall: float|null, likelihood: float|null, impact: float|null, rated: int, total: int, unrated_key_controls: int}
     */
    public function effectiveness(Collection $assessedControls): array
    {
        $byAxis = $assessedControls->groupBy(
            fn (RiskAssessmentControl $row) => $this->axisFor($row->control?->control_type)
        );

        return [
            'overall' => RiskAssessmentControl::aggregateEffectiveness($assessedControls),
            'likelihood' => RiskAssessmentControl::aggregateEffectiveness($byAxis->get('likelihood', collect())),
            'impact' => RiskAssessmentControl::aggregateEffectiveness($byAxis->get('impact', collect())),
            'rated' => $assessedControls->whereNotNull('effectiveness_pct')->count(),
            'total' => $assessedControls->count(),
            // Surfaced on the form: an unrated KEY control is the one gap that
            // should stop an assessment being submitted with a straight face.
            'unrated_key_controls' => $assessedControls
                ->where('is_key_control', true)
                ->whereNull('effectiveness_pct')
                ->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Step 8 — Residual Risk */
    /* ------------------------------------------------------------------ */

    /**
     * Derive residual risk from inherent risk and control effectiveness.
     *
     * Three steps.
     *
     * First, how far the score should fall. That comes from RiskScoringService,
     * which honours whatever residual formula the organization has configured —
     * deriving it here with a second formula would give a tenant two different
     * residual numbers depending on which screen they were looking at.
     *
     * Second, where that lands on the matrix. The reduction is split between
     * the two axes in proportion to the assurance the preventive and the
     * detective controls actually provide: multiplying likelihood by r^p and
     * impact by r^(1-p) reduces their product to exactly r. A risk whose
     * controls are all detective therefore moves down the impact axis, which is
     * what a first-line assessor would expect to see.
     *
     * Third, the score is recomputed from that clamped pair. The matrix is
     * discrete, so the projection rounds; taking the formula's number and
     * printing the rounded cell beside it would produce "residual 7 (L3 × I2)"
     * on a board paper, and 3 × 2 is not 7. Every other score in the platform
     * is likelihood × impact, and residual risk is not the place to make an
     * exception.
     *
     * @return array{likelihood: int, impact: int, score: int, rating: string, effectiveness: float, split: float, target: int}|null
     */
    public function deriveResidual(
        int $inherentLikelihood,
        int $inherentImpact,
        int $inherentScore,
        array $effectiveness,
        ScoringProfile|int|null $profile = null,
    ): ?array {
        $overall = $effectiveness['overall'];

        // Nothing rated means residual cannot be derived. Returning null rather
        // than "no reduction" is deliberate: an unassessed control set and a
        // control set assessed as useless are different findings, and quietly
        // reporting residual == inherent would hide the first inside the second.
        if ($overall === null || $inherentScore <= 0) {
            return null;
        }

        $resolved = $this->scoring->profileFor($profile);
        $target = $this->scoring->calculateResidualScore($inherentScore, (float) $overall, $resolved);

        $reduction = min(1.0, max(0.0, $target / $inherentScore));
        $split = $this->axisSplit($effectiveness);

        $likelihood = $this->clampToAxis(
            $inherentLikelihood * ($reduction ** $split),
            $resolved->matrix_rows,
        );

        $impact = $this->clampToAxis(
            $inherentImpact * ($reduction ** (1 - $split)),
            $resolved->matrix_cols,
        );

        $score = $this->scoring->calculateScore($likelihood, $impact, $resolved);

        return [
            'likelihood' => $likelihood,
            'impact' => $impact,
            'score' => $score,
            'rating' => $this->scoring->calculateRating($score, $resolved),
            'effectiveness' => (float) $overall,
            'split' => $split,
            // What the configured formula asked for, before the matrix rounded
            // it to a cell. Kept so the difference is inspectable rather than
            // silently absorbed.
            'target' => $target,
        ];
    }

    /**
     * Write steps 7 and 8 onto the assessment.
     *
     * `$override` carries an assessor's own residual likelihood and impact. It
     * is honoured only where it actually differs from the derivation — a form
     * that posts back the pre-filled derived values must not brand every
     * assessment an override.
     *
     * @param  array{likelihood: ?int, impact: ?int, justification: ?string}  $override
     */
    public function applyToAssessment(RiskAssessment $assessment, array $override = []): RiskAssessment
    {
        $assessedControls = $assessment->assessedControls()->with('control:id,control_type')->get();
        $effectiveness = $this->effectiveness($assessedControls);
        $profile = $this->scoring->profileForRisk($assessment->risk);

        $derived = $this->deriveResidual(
            (int) $assessment->likelihood_score,
            (int) $assessment->impact_score,
            (int) $assessment->overall_score,
            $effectiveness,
            $profile,
        );

        $overrideLikelihood = $override['likelihood'] ?? null;
        $overrideImpact = $override['impact'] ?? null;
        $hasOverride = $overrideLikelihood !== null && $overrideImpact !== null;

        $isRealOverride = $hasOverride && (
            $derived === null
            || (int) $overrideLikelihood !== $derived['likelihood']
            || (int) $overrideImpact !== $derived['impact']
        );

        if ($isRealOverride) {
            $score = $this->scoring->calculateScore((int) $overrideLikelihood, (int) $overrideImpact, $profile);

            $assessment->fill([
                'residual_likelihood' => (int) $overrideLikelihood,
                'residual_impact' => (int) $overrideImpact,
                'residual_score' => $score,
                'residual_rating' => $this->scoring->calculateRating($score, $profile),
                'residual_source' => RiskAssessment::RESIDUAL_OVERRIDE,
                'residual_justification' => $override['justification'] ?? null,
            ]);
        } elseif ($derived !== null) {
            $assessment->fill([
                'residual_likelihood' => $derived['likelihood'],
                'residual_impact' => $derived['impact'],
                'residual_score' => $derived['score'],
                'residual_rating' => $derived['rating'],
                'residual_source' => RiskAssessment::RESIDUAL_DERIVED,
                'residual_justification' => null,
            ]);
        }

        $assessment->fill([
            'control_effectiveness_pct' => $effectiveness['overall'],
            // The per-control breakdown behind the aggregate. The column was
            // declared in the original 2026-02 migration and written by nothing
            // until now.
            'control_effectiveness_data' => [
                'aggregate' => $effectiveness,
                'controls' => $assessedControls->map(fn (RiskAssessmentControl $row) => [
                    'control_id' => $row->control_id,
                    'code' => $row->control_code,
                    'name' => $row->control_name,
                    'design' => $row->design_effectiveness,
                    'operating' => $row->operating_effectiveness,
                    'effectiveness_pct' => $row->effectiveness_pct === null ? null : (float) $row->effectiveness_pct,
                    'weight' => (float) $row->control_weight,
                    'is_key_control' => (bool) $row->is_key_control,
                ])->all(),
            ],
        ]);

        $assessment->save();

        return $assessment;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * How much of the residual reduction is attributed to likelihood.
     *
     * 0.5 — an even split — when neither axis has rated controls, or when both
     * provide equal assurance. A risk covered only by preventive controls gets
     * its whole reduction on the likelihood axis, which is what a first-line
     * assessor would expect to see on the heat map.
     */
    private function axisSplit(array $effectiveness): float
    {
        $likelihood = (float) ($effectiveness['likelihood'] ?? 0);
        $impact = (float) ($effectiveness['impact'] ?? 0);
        $total = $likelihood + $impact;

        if ($total <= 0) {
            return 0.5;
        }

        // Bounded away from a pure single-axis move: even an all-preventive
        // control set is rarely credited with zero effect on severity, and a
        // 0/1 exponent produces a residual sitting on the grid edge.
        return min(0.85, max(0.15, $likelihood / $total));
    }

    private function axisFor(?string $controlType): string
    {
        return self::AXIS_BY_CONTROL_TYPE[strtolower((string) $controlType)] ?? 'likelihood';
    }

    private function clampToAxis(float $value, int $max): int
    {
        return (int) max(1, min($max, (int) round($value)));
    }

    /**
     * The ratings recorded by the assessment immediately preceding this one,
     * used to pre-populate the form and to highlight what has changed.
     *
     * @return Collection<int, RiskAssessmentControl>
     */
    private function previousRatings(Risk $risk, ?RiskAssessment $assessment): Collection
    {
        $previous = RiskAssessment::where('risk_id', $risk->id)
            ->when($assessment?->exists, fn ($query) => $query->where('id', '!=', $assessment->id))
            ->whereIn('status', ['approved', 'in_review'])
            ->orderByDesc('assessment_date')
            ->orderByDesc('id')
            ->first();

        return $previous
            ? $previous->assessedControls()->get()->keyBy('control_id')
            : collect();
    }
}
