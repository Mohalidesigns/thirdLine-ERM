<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\ScoringProfile;
use App\Support\Scoring\ScoringProfileTemplates;
use App\Support\Tenancy\TenantContext;

/**
 * Turns likelihood and impact into a score and a rating.
 *
 * WP-05 TASK 3 removed every hardcoded constant from this class. There is no
 * `>= 20 is Critical` here any more, no `for ($l = 1; $l <= 5)`, and no
 * assumption that impact has five dimensions. All of it is read from a
 * ScoringProfile resolved for the organisation, node and risk type in play.
 *
 * The public signatures are unchanged, because thirteen call sites across the
 * controllers, models, listeners and the repository call them with no profile
 * to hand. Passing null resolves the profile for the current tenant, so those
 * call sites became profile-aware without being touched. New code should pass
 * the profile explicitly when it already has one — resolution is memoised, but
 * an explicit profile is the difference between "the score for this risk" and
 * "the score for whatever tenant happens to be in context".
 *
 * OUT-OF-RANGE INPUTS ARE CLAMPED, NOT REJECTED. An organisation that moves
 * from 5×5 to 4×4 still has risks carrying a likelihood of 5. Clamping rates
 * them at the top of the new scale; the alternative is a score of 20 on a
 * matrix whose maximum is 16, which falls into no band and renders as a blank
 * rating on every screen. On a 5×5 profile with 1–5 inputs, clamping is a
 * no-op, which is what keeps the upgrade score-for-score identical.
 */
class RiskScoringService
{
    /**
     * The five impact dimensions the platform shipped with.
     *
     * @deprecated Read ScoringProfile::dimensions() instead. Retained because
     *             it is a public constant and removing it in the same release
     *             that stops using it would break any caller outside this
     *             repository, contrary to the deprecation rule.
     */
    public const IMPACT_DIMENSIONS = ScoringProfileTemplates::DEFAULT_IMPACT_DIMENSIONS;

    public function __construct(private ?FormulaEvaluator $formulas = null) {}

    /* ------------------------------------------------------------------ */
    /*  Profile resolution */
    /* ------------------------------------------------------------------ */

    /**
     * The profile governing a calculation.
     *
     * Accepts a profile, a profile id, or nothing. Nothing resolves for the
     * current tenant. Resolution never returns null: an install whose seed
     * migration has not run falls back to the template rather than producing
     * null ratings across the product.
     */
    public function profileFor(
        ScoringProfile|int|null $profile = null,
        ?int $organizationId = null,
        ?int $nodeId = null,
        ?int $objectTypeId = null,
        ?string $riskType = null,
    ): ScoringProfile {
        if ($profile instanceof ScoringProfile) {
            return $profile;
        }

        if (is_int($profile)) {
            $found = ScoringProfile::withoutGlobalScopes()->find($profile);

            if ($found !== null) {
                return $found;
            }
        }

        return ScoringProfile::resolveFor($organizationId, $nodeId, $objectTypeId, $riskType)
            ?? ScoringProfile::fallback();
    }

    /** The profile that governs a specific risk, honouring its node and type. */
    public function profileForRisk(Risk $risk): ScoringProfile
    {
        return $this->profileFor(
            organizationId: $risk->organization_id,
            nodeId: $risk->node_id ?? null,
            riskType: $risk->risk_type ?? null,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Scores */
    /* ------------------------------------------------------------------ */

    /**
     * The inherent score: likelihood × impact, each clamped to its axis.
     */
    public function calculateScore(int $likelihood, int $impact, ScoringProfile|int|null $profile = null): int
    {
        $resolved = $this->profileFor($profile);

        // Zero means "not scored" and must stay zero rather than being clamped
        // up to 1, or an unassessed risk acquires a Low rating it never earned.
        $likelihood = $likelihood <= 0 ? 0 : min($likelihood, $resolved->matrix_rows);
        $impact = $impact <= 0 ? 0 : min($impact, $resolved->matrix_cols);

        return $likelihood * $impact;
    }

    /**
     * The rating band label for a score.
     *
     * A score outside every configured band returns the nearest band rather
     * than an empty string: a gap in a tenant's band configuration should
     * show up as a wrong-looking rating they can go and fix, not as a blank
     * cell that reads as "not assessed".
     */
    public function calculateRating(int $score, ScoringProfile|int|null $profile = null): string
    {
        $resolved = $this->profileFor($profile);
        $band = $resolved->bandFor($score);

        if ($band !== null) {
            return (string) ($band['label'] ?? $band['code'] ?? '');
        }

        return $this->nearestBandLabel($resolved, $score);
    }

    /** The full band definition, for callers that need its colour too. */
    public function ratingBand(int|float|null $score, ScoringProfile|int|null $profile = null): ?array
    {
        return $this->profileFor($profile)->bandFor($score);
    }

    /**
     * Collapse the impact dimensions into the single score that drives the
     * rating, using the profile's dimension list, aggregation method and
     * weights.
     *
     * Unscored dimensions are excluded rather than counted as zero: a
     * half-finished assessment should not be rated lower than a finished one
     * that happens to score the same on the dimensions both filled in.
     *
     * @param  array<string, int|string|null>  $impacts  keyed by dimension name
     */
    public function calculateImpact(
        array $impacts,
        ?int $organizationId = null,
        ScoringProfile|int|null $profile = null,
    ): int {
        $resolved = $this->profileFor($profile, $organizationId);

        $scored = [];

        foreach ($resolved->dimensions() as $dimension) {
            $value = $impacts[$dimension] ?? null;

            if ($value !== null && $value !== '') {
                $scored[$dimension] = min((int) $value, $resolved->matrix_cols);
            }
        }

        if ($scored === []) {
            return 0;
        }

        return match ($resolved->impact_aggregation) {
            'average' => (int) round(array_sum($scored) / count($scored)),
            'weighted' => $this->weightedImpact($scored, $resolved),
            'worst_two' => $this->worstTwoImpact($scored),
            default => max($scored),
        };
    }

    /**
     * Calculate the impact score across the platform's five named dimensions.
     *
     * Kept for the existing call sites, which pass positional arguments. A
     * profile that scores a different dimension set should be fed through
     * calculateImpact(), which is not limited to these five.
     */
    public function calculateMaxImpact(
        ?int $financial,
        ?int $operational,
        ?int $reputational,
        ?int $regulatory,
        ?int $strategic = null,
        ?int $organizationId = null,
        ScoringProfile|int|null $profile = null,
    ): int {
        return $this->calculateImpact([
            'financial' => $financial,
            'operational' => $operational,
            'reputational' => $reputational,
            'regulatory' => $regulatory,
            'strategic' => $strategic,
        ], $organizationId, $profile);
    }

    /**
     * Residual risk, from the profile's residual_formula.
     *
     * The formula is evaluated by FormulaEvaluator — symfony/expression-language
     * parsed to an AST, never eval() — with `inherent` and `effectiveness` in
     * scope. A profile with no formula, or one that fails to evaluate, falls
     * back to the platform default rather than returning nothing: a broken
     * expression in one tenant's configuration must not blank out the residual
     * column of their entire register.
     */
    public function calculateResidualScore(
        int $inherentScore,
        float $controlEffectivenessPct,
        ScoringProfile|int|null $profile = null,
    ): int {
        $resolved = $this->profileFor($profile);
        $formula = trim((string) ($resolved->residual_formula ?? ''));

        $residual = null;

        if ($formula !== '' && $formula !== ScoringProfileTemplates::DEFAULT_RESIDUAL_FORMULA) {
            try {
                $residual = ($this->formulas ?? app(FormulaEvaluator::class))->evaluate(
                    $formula,
                    ['organization_id' => $resolved->organization_id ?? TenantContext::organizationId()],
                    [
                        'inherent' => $inherentScore,
                        'effectiveness' => $controlEffectivenessPct,
                        'max_score' => $resolved->maxScore(),
                    ],
                );
            } catch (\Throwable $error) {
                logger()->warning('Scoring profile residual formula failed; using the platform default.', [
                    'scoring_profile_id' => $resolved->id,
                    'formula' => $formula,
                    'error' => $error->getMessage(),
                ]);
            }
        }

        $residual ??= $inherentScore * (1 - ($controlEffectivenessPct / 100));

        // A residual of zero would claim the controls removed the risk
        // entirely, which no control auditor would sign, so the floor is 1.
        // That is also true of an inherent score of 0, which reads oddly but is
        // what this method has always returned; changing it here would move
        // residual scores on upgrade for no reason connected to profiles.
        return max(1, (int) round($residual));
    }

    /* ------------------------------------------------------------------ */
    /*  Writing scores onto a risk */
    /* ------------------------------------------------------------------ */

    /**
     * Update a risk record's scores from an assessment.
     */
    public function updateRiskFromAssessment(Risk $risk, RiskAssessment $assessment): Risk
    {
        $profile = $this->profileForRisk($risk);

        $impactScore = $this->calculateMaxImpact(
            $assessment->impact_financial,
            $assessment->impact_operational,
            $assessment->impact_reputational,
            $assessment->impact_regulatory,
            $assessment->impact_strategic,
            $risk->organization_id,
            $profile,
        );

        $inherentScore = $this->calculateScore($assessment->likelihood_score, $impactScore, $profile);
        $inherentRating = $this->calculateRating($inherentScore, $profile);

        $hasResidual = $assessment->residual_likelihood && $assessment->residual_impact;
        $residualScore = $hasResidual
            ? $this->calculateScore($assessment->residual_likelihood, $assessment->residual_impact, $profile)
            : null;

        $risk->update([
            'inherent_likelihood' => $assessment->likelihood_score,
            'inherent_impact' => $impactScore,
            'inherent_impact_financial' => $assessment->impact_financial,
            'inherent_impact_operational' => $assessment->impact_operational,
            'inherent_impact_reputational' => $assessment->impact_reputational,
            'inherent_impact_regulatory' => $assessment->impact_regulatory,
            'inherent_score' => $inherentScore,
            'inherent_rating' => $inherentRating,
            'residual_likelihood' => $assessment->residual_likelihood,
            'residual_impact' => $assessment->residual_impact,
            'residual_score' => $residualScore,
            'residual_rating' => $residualScore === null ? null : $this->calculateRating($residualScore, $profile),
            'risk_velocity' => $assessment->risk_velocity,
            'last_assessment_date' => $assessment->assessment_date,
        ]);

        return $risk->fresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Heat map */
    /* ------------------------------------------------------------------ */

    /**
     * The heat map grid: matrix_rows × matrix_cols, sized by the profile.
     *
     * Cells are keyed [likelihood][impact] as they always were, so callers
     * that index the result do not change — they simply have more or fewer
     * keys to walk.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getRiskMatrix(int $organizationId, string $type = 'inherent', ScoringProfile|int|null $profile = null): array
    {
        $resolved = $this->profileFor($profile, $organizationId);
        $prefix = $type === 'residual' ? 'residual' : 'inherent';

        $risks = Risk::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull("{$prefix}_likelihood")
            ->whereNotNull("{$prefix}_impact")
            ->get();

        $matrix = [];

        for ($l = 1; $l <= $resolved->matrix_rows; $l++) {
            for ($i = 1; $i <= $resolved->matrix_cols; $i++) {
                $score = $l * $i;
                $band = $resolved->bandFor($score);

                $matrix[$l][$i] = [
                    'count' => 0,
                    'risks' => [],
                    'score' => $score,
                    'rating' => $band['label'] ?? $this->nearestBandLabel($resolved, $score),
                    'color' => $band['color'] ?? null,
                    'band' => $band['code'] ?? null,
                ];
            }
        }

        foreach ($risks as $risk) {
            // Clamped, so a risk still carrying a 5 after a move to 4×4 lands
            // in the top-right cell instead of falling out of the grid.
            $l = min((int) $risk->{"{$prefix}_likelihood"}, $resolved->matrix_rows);
            $i = min((int) $risk->{"{$prefix}_impact"}, $resolved->matrix_cols);

            if ($l < 1 || $i < 1) {
                continue;
            }

            $matrix[$l][$i]['count']++;
            $matrix[$l][$i]['risks'][] = [
                'id' => $risk->id,
                'title' => $risk->title,
                'risk_code' => $risk->risk_code,
            ];
        }

        return $matrix;
    }

    /**
     * Risk counts by rating band, using the profile's bands rather than four
     * hardcoded labels.
     *
     * @return array<string, int>
     */
    public function getRiskDistribution(int $organizationId, ScoringProfile|int|null $profile = null): array
    {
        $resolved = $this->profileFor($profile, $organizationId);

        $counts = Risk::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->selectRaw('inherent_rating, COUNT(*) as aggregate')
            ->groupBy('inherent_rating')
            ->pluck('aggregate', 'inherent_rating');

        $distribution = [];

        // Highest band first, which is the order every dashboard renders them.
        foreach (array_reverse($resolved->rating_bands ?? []) as $band) {
            $label = (string) ($band['label'] ?? $band['code'] ?? '');
            $distribution[$label] = (int) ($counts[$label] ?? 0);
        }

        return $distribution;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, int>  $scored
     */
    private function weightedImpact(array $scored, ScoringProfile $profile): int
    {
        $weights = $profile->weights();

        $weightedTotal = 0.0;
        $weightTotal = 0.0;

        foreach ($scored as $dimension => $value) {
            $weight = (float) ($weights[$dimension] ?? 1.0);
            $weightedTotal += $weight * $value;
            $weightTotal += $weight;
        }

        // Every weight set to zero is a configuration mistake, not an
        // instruction to report no impact at all.
        return $weightTotal > 0.0
            ? (int) round($weightedTotal / $weightTotal)
            : max($scored);
    }

    /**
     * @param  array<string, int>  $scored
     */
    private function worstTwoImpact(array $scored): int
    {
        $values = array_values($scored);
        rsort($values);

        $top = array_slice($values, 0, 2);

        return (int) round(array_sum($top) / count($top));
    }

    /**
     * The label of the band nearest to a score that fell into no band.
     *
     * Below every band takes the lowest; above every band takes the highest.
     * An empty band list — a profile saved with none — takes the template's,
     * because a rating column of empty strings is not a useful signal.
     */
    private function nearestBandLabel(ScoringProfile $profile, int|float $score): string
    {
        $bands = $profile->rating_bands ?? [];

        if ($bands === []) {
            $bands = ScoringProfileTemplates::DEFAULT_RATING_BANDS;
        }

        usort($bands, fn ($a, $b) => ($a['min'] ?? 0) <=> ($b['min'] ?? 0));

        if ($score <= ($bands[0]['min'] ?? 1)) {
            return (string) ($bands[0]['label'] ?? '');
        }

        $highest = $bands[count($bands) - 1];

        return (string) ($highest['label'] ?? '');
    }
}
