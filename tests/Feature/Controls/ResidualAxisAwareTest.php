<?php

namespace Tests\Feature\Controls;

use App\Models\Risk;
use App\Models\RiskAuditTrail;
use App\Models\ScoringProfile;
use App\Services\AssessmentChainService;
use App\Services\ControlEffectivenessService;
use App\Services\RiskScoringService;
use App\Support\RiskCalculationSettings;
use App\Support\Scoring\ScoringProfileTemplates;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

/**
 * QA gate additions for the axis-aware control recalculation: parity with the
 * approval path, the uncontrolled and unscored cases, the tenant scoring
 * profile, and the actor on the two mapping paths the audit-trail suite does
 * not name one for.
 */
class ResidualAxisAwareTest extends ControlsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('risk.edit');
        $this->actor->givePermissionTo('risk.edit');

        $this->risk->update([
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'residual_likelihood' => 4,
            'residual_impact' => 5,
            'residual_score' => 20,
            'residual_rating' => 'Critical',
        ]);
    }

    private function entryCount(Risk $risk): int
    {
        return RiskAuditTrail::where('entity_type', $risk->getMorphClass())
            ->where('entity_id', $risk->id)
            ->where('action_type', 'residual_recalculated')
            ->count();
    }

    #[Test]
    public function an_uncontrolled_risk_keeps_its_inherent_cell_and_writes_no_entry(): void
    {
        $risk = $this->makeRisk([
            'inherent_likelihood' => 3,
            'inherent_impact' => 4,
            'inherent_score' => 12,
            'residual_likelihood' => 3,
            'residual_impact' => 4,
            'residual_score' => 12,
            'residual_rating' => 'High',
            'control_effectiveness_pct' => 0,
        ]);

        $updated = app(ControlEffectivenessService::class)->recalculateForRisk($risk);

        $this->assertSame([3, 4, 12], [$updated->residual_likelihood, $updated->residual_impact, $updated->residual_score]);
        $this->assertSame(0, $this->entryCount($risk));
    }

    #[Test]
    public function an_unscored_risk_has_every_residual_column_cleared(): void
    {
        $risk = $this->makeRisk([
            'inherent_score' => null,
            'inherent_likelihood' => null,
            'inherent_impact' => null,
            'residual_likelihood' => 2,
            'residual_impact' => 2,
            'residual_score' => 4,
            'residual_rating' => 'Low',
        ]);
        $this->attachControl($risk, $this->control);

        $updated = app(ControlEffectivenessService::class)->recalculateForRisk($risk);

        $this->assertNull($updated->residual_likelihood);
        $this->assertNull($updated->residual_impact);
        $this->assertNull($updated->residual_score);
        $this->assertNull($updated->residual_rating);
    }

    #[Test]
    public function the_control_path_lands_on_the_same_cell_as_the_approval_arithmetic_for_a_mixed_control_set(): void
    {
        $map = RiskCalculationSettings::effectivenessMap($this->organization->id);

        $preventive = $this->makeControl(['control_type' => 'preventive', 'effectiveness_rating' => 'effective', 'effectiveness_pct' => null]);
        $detective = $this->makeControl(['control_type' => 'detective', 'effectiveness_rating' => 'partially_effective', 'effectiveness_pct' => null]);
        $this->attachControl($this->risk, $preventive);
        $this->attachControl($this->risk, $detective);

        $updated = app(ControlEffectivenessService::class)->recalculateForRisk($this->risk);

        $p = (float) $map['effective'];
        $d = (float) $map['partially_effective'];
        $expected = app(AssessmentChainService::class)->deriveResidual(4, 5, 20, [
            'overall' => round(($p + $d) / 2, 2),
            'likelihood' => $p,
            'impact' => $d,
        ], app(RiskScoringService::class)->profileForRisk($this->risk));

        $this->assertSame(
            [$expected['likelihood'], $expected['impact'], $expected['score'], $expected['rating']],
            [$updated->residual_likelihood, $updated->residual_impact, $updated->residual_score, $updated->residual_rating],
        );
        // Both axes were reduced, so the control path is not just the inherent cell.
        $this->assertLessThan(4, $updated->residual_likelihood);
        $this->assertLessThan(5, $updated->residual_impact);
    }

    #[Test]
    public function a_tenant_matrix_is_honoured_on_the_control_path(): void
    {
        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'four-by-four',
            'is_system' => false,
            'matrix_rows' => 4,
            'matrix_cols' => 4,
        ]));
        ScoringProfile::flushResolutionCache();

        $risk = $this->makeRisk([
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'inherent_score' => 16,
            'residual_likelihood' => 4,
            'residual_impact' => 4,
            'residual_score' => 16,
        ]);
        $control = $this->makeControl(['control_type' => 'preventive', 'effectiveness_rating' => 'effective', 'effectiveness_pct' => null]);
        $this->attachControl($risk, $control);

        $profile = app(RiskScoringService::class)->profileForRisk($risk);
        $this->assertSame(4, $profile->matrix_rows, 'fixture: the tenant profile must be the one in force');

        $updated = app(ControlEffectivenessService::class)->recalculateForRisk($risk);

        $this->assertLessThanOrEqual(4, $updated->residual_likelihood);
        $this->assertLessThanOrEqual(4, $updated->residual_impact);
        $this->assertSame(
            app(RiskScoringService::class)->calculateScore($updated->residual_likelihood, $updated->residual_impact, $profile),
            $updated->residual_score,
        );
    }

    #[Test]
    public function a_tenant_residual_formula_drives_the_control_path(): void
    {
        ScoringProfile::create(ScoringProfileTemplates::default('NGN', [
            'organization_id' => $this->organization->id,
            'code' => 'house-formula',
            'is_system' => false,
            'residual_formula' => 'inherent * (1 - effectiveness / 200)',
        ]));
        ScoringProfile::flushResolutionCache();

        $control = $this->makeControl(['control_type' => 'preventive', 'effectiveness_rating' => 'effective', 'effectiveness_pct' => null]);
        $this->attachControl($this->risk, $control);

        $updated = app(ControlEffectivenessService::class)->recalculateForRisk($this->risk);

        // Target 20 × (1 − 0.475) = 10.5 → 11; reduction 0.55. Likelihood
        // round(4 × 0.55^0.85) = 2, impact round(5 × 0.55^0.15) = 5, cell 10.
        // The platform default would have landed on 1 × 3 = 3.
        $this->assertSame([2, 5, 10], [$updated->residual_likelihood, $updated->residual_impact, $updated->residual_score]);
    }

    #[Test]
    public function linking_a_risk_from_the_control_page_names_the_user(): void
    {
        $control = $this->makeControl(['control_type' => 'preventive', 'effectiveness_rating' => 'effective', 'effectiveness_pct' => null]);

        $this->actingAs($this->actor)
            ->post(route('risk.controls.link-risk', $control), ['risk_id' => $this->risk->id])
            ->assertSessionHasNoErrors();

        $entry = RiskAuditTrail::where('entity_id', $this->risk->id)->where('action_type', 'residual_recalculated')->sole();
        $this->assertSame($this->actor->id, (int) $entry->changed_by);
    }

    #[Test]
    public function creating_a_control_with_risk_ids_names_the_user(): void
    {
        $this->actingAs($this->actor)
            ->post(route('risk.controls.store'), $this->validControl([
                'risk_ids' => [$this->risk->id],
                'control_type' => 'preventive',
                'effectiveness_rating' => 'effective',
            ]))
            ->assertSessionHasNoErrors();

        $entry = RiskAuditTrail::where('entity_id', $this->risk->id)->where('action_type', 'residual_recalculated')->sole();
        $this->assertSame($this->actor->id, (int) $entry->changed_by);
    }

    #[Test]
    public function the_old_two_argument_event_still_constructs_with_no_actor(): void
    {
        $event = new \App\Events\ControlUpdated($this->control, ['x']);

        $this->assertNull($event->userId);
    }
}
