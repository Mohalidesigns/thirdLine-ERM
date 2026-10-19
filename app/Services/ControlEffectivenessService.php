<?php

namespace App\Services;

use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Support\RiskCalculationSettings;
use Illuminate\Support\Collection;

class ControlEffectivenessService
{
    /** The risk columns recalculateForRisk() writes, and its audit entry reports. */
    private const AUDITED = [
        'control_effectiveness_pct',
        'residual_likelihood',
        'residual_impact',
        'residual_score',
        'residual_rating',
    ];

    /**
     * Optional so `new ControlEffectivenessService` keeps working where the
     * characterisation tests build it by hand; resolved from the container
     * on first use otherwise.
     */
    public function __construct(private ?AssessmentChainService $chain = null) {}

    /**
     * Calculate aggregate control effectiveness for a risk
     * Uses weighted average: SUM(weight * effectiveness) / SUM(weight)
     */
    public function calculateForRisk(Risk $risk): float
    {
        return $this->aggregate($risk)['overall'];
    }

    /**
     * Recalculate and update a risk's control effectiveness and residual —
     * likelihood, impact, score and rating together.
     *
     * One of three writers of risks.residual_*. The other two are
     * RiskScoringService::updateRiskFromAssessment() — the authority on the
     * approval path — and RiskAssessmentBinding::onApproved(), which delegates
     * to it. This one runs when the risk's control picture changes: a control
     * mapped or unmapped (RiskRegisterService, ControlService) or re-rated
     * (App\Listeners\RecalculateResidualRisk, on ControlUpdated).
     *
     * It derives the residual through AssessmentChainService::deriveResidual(),
     * the same arithmetic the approval path uses: preventive and directive
     * controls reduce likelihood, detective and corrective controls reduce
     * impact, and the score is the matrix cell the pair lands on. It used to
     * write residual_score alone (inherent × (1 − effectiveness)) and leave
     * the pair standing, so a control change on a risk whose residual came
     * from an approved assessment left a residual_score that was no longer
     * residual_likelihood × residual_impact.
     *
     * A risk with an inherent score but no inherent likelihood and impact has
     * no axes to split; its residual score is the scalar formula and its pair
     * is cleared, since nothing could have derived one.
     *
     * NOT DECIDED HERE: whether a control change should overwrite a residual
     * an approved assessment set at all. It does, as it always did; the
     * movement is now on the risk's audit trail.
     *
     * @param  int|null  $userId  the actor the audit entry names; null for a
     *                            system caller — never a stand-in user
     */
    public function recalculateForRisk(Risk $risk, ?int $userId = null): Risk
    {
        $effectiveness = $this->aggregate($risk);
        $scoring = app(RiskScoringService::class);

        $updates = [
            'control_effectiveness_pct' => $effectiveness['overall'],
            'residual_likelihood' => null,
            'residual_impact' => null,
            'residual_score' => null,
            'residual_rating' => null,
        ];

        if ($risk->inherent_score) {
            $profile = $scoring->profileForRisk($risk);
            $likelihood = (int) $risk->inherent_likelihood;
            $impact = (int) $risk->inherent_impact;

            if ($likelihood > 0 && $impact > 0) {
                // `overall` is never null here and the inherent score is
                // positive, so the derivation always returns a residual.
                $derived = $this->chain()->deriveResidual($likelihood, $impact, (int) $risk->inherent_score, $effectiveness, $profile);

                $updates = array_merge($updates, [
                    'residual_likelihood' => $derived['likelihood'],
                    'residual_impact' => $derived['impact'],
                    'residual_score' => $derived['score'],
                    'residual_rating' => $derived['rating'],
                ]);
            } else {
                $score = $scoring->calculateResidualScore((int) $risk->inherent_score, $effectiveness['overall'], $profile);

                $updates['residual_score'] = $score;
                $updates['residual_rating'] = $scoring->calculateRating($score, $profile);
            }
        }

        $before = $risk->only(self::AUDITED);

        $risk->update($updates);

        // A residual that moves is a change to the risk, so it goes on the
        // risk's trail — but only when a value actually moved: ControlService::
        // update() recalculates and then fires ControlUpdated, whose listener
        // recalculates again, and the second pass must not write a duplicate.
        // Compared on the cast values rather than wasChanged(): the decimal
        // column reports 0 → 0.0 as a change.
        $after = $risk->only(self::AUDITED);

        if ($after !== $before) {
            // Written here rather than through AuditTrailService::record(),
            // which takes its actor from auth(): this runs from a listener that
            // may one day be queued, and from console callers, where the
            // session is not the actor. The caller names the actor instead.
            RiskAuditTrail::create([
                'entity_type' => $risk->getMorphClass(),
                'entity_id' => $risk->id,
                'organization_id' => $risk->organization_id,
                'action_type' => 'residual_recalculated',
                'field_changed' => 'residual',
                'old_value' => json_encode($before),
                'new_value' => json_encode($after),
                'changed_by' => $userId,
                'changed_at' => now(),
            ]);
        }

        return $risk->fresh();
    }

    /**
     * The mapped controls' weighted effectiveness, overall and per axis.
     *
     * Overall is 0.0 for a risk with no controls: an uncontrolled risk keeps
     * its inherent score as its residual. A per-axis figure is null when no
     * control acts on that axis, which is what deriveResidual()'s axis split
     * expects.
     *
     * @return array{overall: float, likelihood: float|null, impact: float|null}
     */
    private function aggregate(Risk $risk): array
    {
        $controls = $risk->controls()->withPivot(['control_weight', 'is_key_control'])->get();

        if ($controls->isEmpty()) {
            return ['overall' => 0.0, 'likelihood' => null, 'impact' => null];
        }

        // The bands are per organization (config/risk.php, overridable via the
        // organization's settings). Resolved once for the whole risk rather
        // than per control: a bulk recalculation walks thousands of controls.
        $effectivenessMap = RiskCalculationSettings::effectivenessMap($risk->organization_id);

        $byAxis = $controls->groupBy(fn ($control) => $this->chain()->axisFor($control->control_type));

        return [
            'overall' => $this->weighted($controls, $effectivenessMap),
            'likelihood' => $byAxis->has('likelihood') ? $this->weighted($byAxis->get('likelihood'), $effectivenessMap) : null,
            'impact' => $byAxis->has('impact') ? $this->weighted($byAxis->get('impact'), $effectivenessMap) : null,
        ];
    }

    /**
     * SUM(weight × effectiveness) / SUM(weight).
     *
     * @param  array<string, int|float>  $effectivenessMap
     */
    private function weighted(Collection $controls, array $effectivenessMap): float
    {
        $totalWeightedEffectiveness = 0;
        $totalWeight = 0;

        foreach ($controls as $control) {
            $weight = $control->pivot->control_weight ?? 1.0;
            $effectiveness = $control->effectiveness_pct ?? ($effectivenessMap[$control->effectiveness_rating] ?? 0);

            $totalWeightedEffectiveness += $weight * $effectiveness;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($totalWeightedEffectiveness / $totalWeight, 2) : 0.0;
    }

    private function chain(): AssessmentChainService
    {
        return $this->chain ??= app(AssessmentChainService::class);
    }
}
