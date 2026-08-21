<?php

namespace Tests\Feature\Treatments;

use App\Events\TreatmentCompleted;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\ScoringProfile;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\RiskScoringService;
use App\Services\Workflow\ModuleApprovals;
use App\Services\Workflow\WorkflowEngine;
use App\Support\RiskCalculationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CreatesDomainFixtures;
use Tests\Support\CreatesWorkflowFixtures;
use Tests\TestCase;

/**
 * Completing a treatment plan raises a reassessment; it does not move the risk.
 *
 * TriggerRiskReassessment used to multiply `risks.residual_score` by
 * `treatment_plans.expected_risk_reduction_percentage` — a column that exists
 * in no migration — and write the float result into a smallInteger column,
 * alongside two more columns and a service method that do not exist either.
 * With strict-mode Eloquent off the guard read null, the branch never ran, and
 * the listener was dead in a way no test and no exception would ever surface.
 *
 * What these tests defend is the shape of the replacement rather than its
 * arithmetic: the residual number reaches `risks` through an APPROVED
 * assessment and through nothing else. A future change that moves the risk at
 * completion time would still render every screen correctly and would fail
 * only here.
 */
class TreatmentCompletionReassessmentTest extends TestCase
{
    use CreatesDomainFixtures, CreatesWorkflowFixtures, RefreshDatabase;

    private RiskScoringService $scoring;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();

        $this->scoring = app(RiskScoringService::class);
    }

    protected function tearDown(): void
    {
        RiskCalculationSettings::flush();
        ScoringProfile::flushResolutionCache();
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The reassessment */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function completing_a_treatment_raises_one_reassessment_carrying_the_plans_expected_residual(): void
    {
        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);

        TreatmentCompleted::dispatch($plan, $risk);

        $assessments = RiskAssessment::where('risk_id', $risk->id)
            ->where('status', '!=', 'approved')
            ->get();

        $this->assertCount(1, $assessments, 'One completion raises exactly one reassessment.');

        $assessment = $assessments->first();

        $this->assertSame(2, (int) $assessment->residual_likelihood);
        $this->assertSame(3, (int) $assessment->residual_impact);
        $this->assertSame(6, (int) $assessment->residual_score);

        // Awaiting a decision, not decided. `in_review` is where the human
        // Submit button leaves an assessment when the tenant has published no
        // workflow definition, which is the state this fixture is in.
        $this->assertSame('in_review', $assessment->status);
        $this->assertSame('triggered', $assessment->assessment_type);

        // The plan's stated intent is judgement, not arithmetic derived from
        // control effectiveness, and the record has to say which it is.
        $this->assertSame(RiskAssessment::RESIDUAL_OVERRIDE, $assessment->residual_source);
        $this->assertNotEmpty($assessment->residual_justification);
        $this->assertStringContainsString($plan->treatment_code, $assessment->residual_justification);
    }

    #[Test]
    public function the_risks_residual_does_not_move_when_the_treatment_completes(): void
    {
        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);

        TreatmentCompleted::dispatch($plan, $risk);

        $risk->refresh();

        // The whole point. A treatment plan's own claim about what it would
        // deliver is not an approval, and until someone approves it the
        // register still says what it said.
        $this->assertSame(3, $risk->residual_likelihood);
        $this->assertSame(4, $risk->residual_impact);
        $this->assertSame(12, $risk->residual_score);
    }

    #[Test]
    public function approving_the_raised_reassessment_moves_the_risk_to_the_expected_residual(): void
    {
        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);

        TreatmentCompleted::dispatch($plan, $risk);

        $assessment = RiskAssessment::where('risk_id', $risk->id)->where('status', 'in_review')->firstOrFail();

        // The real approval path: RiskAssessmentController::approve calls
        // exactly this when no engine instance is running, and the binding it
        // reaches is the only writer of risks.residual_* in this flow.
        app(ModuleApprovals::class)->decideDirectly($assessment, 'approve', $this->actor, 'Reviewed.');

        $risk->refresh();

        $this->assertSame(2, $risk->residual_likelihood);
        $this->assertSame(3, $risk->residual_impact);
        $this->assertSame(6, $risk->residual_score);

        // Completing a treatment changes what is left after controls, not the
        // exposure before them. Approval re-derives the inherent side from the
        // assessment, so a reassessment that dropped the impact breakdown
        // would silently zero the risk's inherent score here.
        $this->assertSame(4, $risk->inherent_likelihood);
        $this->assertSame(5, $risk->inherent_impact);
        $this->assertSame(20, $risk->inherent_score);

        $this->assertSame('approved', $assessment->fresh()->status);
    }

    #[Test]
    public function the_reassessment_goes_through_the_published_workflow_where_the_tenant_has_one(): void
    {
        // There is no second approval path here. Where the tenant has
        // published a risk_assessment_approval definition, the completion
        // starts it exactly as the Submit button does, and the decision
        // reaches risks through RiskAssessmentBinding::onApproved either way.
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $riskManager = User::create([
            'name' => 'Risk Manager',
            'email' => 'rm-'.$this->organization->id.'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
        $riskManager->assignRole('risk-manager');

        $this->linearDefinition(['code' => 'risk_assessment_approval', 'name' => 'Risk assessment approval']);

        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);

        TreatmentCompleted::dispatch($plan, $risk);

        $assessment = RiskAssessment::where('risk_id', $risk->id)->where('status', 'in_review')->firstOrFail();

        $engine = app(WorkflowEngine::class);
        $instance = $engine->openInstanceFor($assessment);

        $this->assertNotNull($instance, 'The completion submits the reassessment into the tenant workflow.');
        $this->assertSame(12, $risk->fresh()->residual_score, 'A task is not an approval.');

        $engine->advance($instance->openTasks()->first(), 'approve', [], $riskManager);

        $risk->refresh();
        $this->assertSame(2, $risk->residual_likelihood);
        $this->assertSame(3, $risk->residual_impact);
        $this->assertSame(6, $risk->residual_score);
    }

    #[Test]
    public function a_repeated_delivery_of_the_event_does_not_raise_a_second_reassessment(): void
    {
        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);

        TreatmentCompleted::dispatch($plan, $risk);
        TreatmentCompleted::dispatch($plan, $risk);

        $this->assertSame(1, RiskAssessment::where('risk_id', $risk->id)->where('status', 'in_review')->count());

        // And the first delivery's record of what the plan delivered is not
        // overwritten by the second's "one was already open".
        $this->assertNull($plan->fresh()->actual_risk_reduction['not_raised_reason']);
    }

    /* ------------------------------------------------------------------ */
    /*  The honest gaps */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_plan_with_no_expected_residual_raises_nothing_and_does_not_throw(): void
    {
        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, [
            'expected_residual_likelihood' => null,
            'expected_residual_impact' => null,
        ]);

        TreatmentCompleted::dispatch($plan, $risk);

        // No claim was made, so no number is invented for one.
        $this->assertSame(0, RiskAssessment::where('risk_id', $risk->id)->where('status', '!=', 'approved')->count());
        $this->assertNull($plan->fresh()->actual_risk_reduction);

        $risk->refresh();
        $this->assertSame(12, $risk->residual_score);
    }

    #[Test]
    public function half_an_expected_residual_is_not_a_claim(): void
    {
        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, [
            'expected_residual_likelihood' => 2,
            'expected_residual_impact' => null,
        ]);

        TreatmentCompleted::dispatch($plan, $risk);

        $this->assertSame(0, RiskAssessment::where('risk_id', $risk->id)->where('status', '!=', 'approved')->count());
    }

    #[Test]
    public function a_risk_with_no_inherent_basis_raises_nothing(): void
    {
        // No prior assessment and no scores on the register: there is no
        // inherent side to carry forward, and manufacturing one would either
        // invent an impact breakdown or gut the risk's inherent score the
        // moment the reassessment was approved.
        $risk = $this->makeRisk();
        $plan = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);

        TreatmentCompleted::dispatch($plan, $risk);

        $this->assertSame(0, RiskAssessment::where('risk_id', $risk->id)->count());

        // The claim is still recorded against the plan, with the reason it
        // went nowhere, so a plan whose completion did nothing is visible.
        $this->assertSame('no_inherent_basis', $plan->fresh()->actual_risk_reduction['not_raised_reason']);
    }

    #[Test]
    public function two_treatments_completing_on_one_risk_do_not_open_two_competing_reassessments(): void
    {
        $risk = $this->assessedRisk();

        $first = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);
        $second = $this->completedPlan($risk, ['expected_residual_likelihood' => 1, 'expected_residual_impact' => 2]);

        TreatmentCompleted::dispatch($first, $risk);
        TreatmentCompleted::dispatch($second, $risk);

        $open = RiskAssessment::where('risk_id', $risk->id)
            ->whereIn('status', ['draft', 'in_review'])
            ->get();

        // Two open assessments would be two competing residual numbers for one
        // risk and no defensible answer to which of them was approved.
        $this->assertCount(1, $open);

        // The first claim holds; the open assessment is not rewritten
        // underneath a review that has already started.
        $this->assertSame(2, (int) $open->first()->residual_likelihood);
        $this->assertSame(3, (int) $open->first()->residual_impact);

        // The second plan's claim is not lost — it is on the plan.
        $this->assertSame('reassessment_already_open', $second->fresh()->actual_risk_reduction['not_raised_reason']);
    }

    /* ------------------------------------------------------------------ */
    /*  What was delivered */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_completion_is_recorded_on_the_plans_actual_risk_reduction(): void
    {
        $risk = $this->assessedRisk();
        $plan = $this->completedPlan($risk, ['expected_residual_likelihood' => 2, 'expected_residual_impact' => 3]);

        TreatmentCompleted::dispatch($plan, $risk);

        $delivered = $plan->fresh()->actual_risk_reduction;

        // Same keys as expected_risk_reduction, so the two are comparable.
        $this->assertSame(1, $delivered['likelihood_reduction']);
        $this->assertSame(1, $delivered['impact_reduction']);
        $this->assertSame(['likelihood' => 3, 'impact' => 4], $delivered['from']);
        $this->assertSame(['likelihood' => 2, 'impact' => 3], $delivered['to']);

        // Claimed, not confirmed: the reassessment it points at has not been
        // approved yet, and calling that "actual" would be the old listener's
        // lie one column over.
        $this->assertFalse($delivered['confirmed']);
        $this->assertNull($delivered['not_raised_reason']);
        $this->assertSame(
            RiskAssessment::where('risk_id', $risk->id)->where('status', 'in_review')->value('id'),
            $delivered['risk_assessment_id'],
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The dead guard */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_column_the_old_guard_read_exists_nowhere_in_the_application(): void
    {
        $hits = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'expected_risk_reduction_percentage')) {
                $hits[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        // The guard evaluated to null on a column that has never existed, so
        // the listener could never throw and never ran. Nothing in app/ may
        // read it again.
        $this->assertSame([], $hits, 'expected_risk_reduction_percentage is not a column and must not be read.');

        $this->assertFalse(Schema::hasColumn('treatment_plans', 'expected_risk_reduction_percentage'));
        $this->assertFalse(Schema::hasColumn('risks', 'residual_likelihood_level'));
        $this->assertFalse(Schema::hasColumn('risks', 'last_updated_at'));
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    /**
     * A risk that has been through the chain once: scores on the register and
     * an approved assessment behind them carrying the impact breakdown.
     */
    private function assessedRisk(): Risk
    {
        $risk = $this->makeRisk([
            'risk_owner_id' => $this->actor->id,
            'inherent_likelihood' => 4,
            'inherent_impact' => 5,
            'inherent_score' => 20,
            'inherent_rating' => $this->scoring->calculateRating(20),
            'residual_likelihood' => 3,
            'residual_impact' => 4,
            'residual_score' => 12,
            'residual_rating' => $this->scoring->calculateRating(12),
        ]);

        RiskAssessment::create([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'assessment_type' => 'periodic',
            'assessment_date' => now()->subMonths(2)->toDateString(),
            'assessor_id' => $this->actor->id,
            'status' => 'approved',
            'likelihood_score' => 4,
            'impact_financial' => 5,
            'impact_score' => 5,
            'overall_score' => 20,
            'overall_rating' => $this->scoring->calculateRating(20),
            'residual_likelihood' => 3,
            'residual_impact' => 4,
            'residual_score' => 12,
            'residual_rating' => $this->scoring->calculateRating(12),
        ]);

        return $risk;
    }

    /** @param array<string, mixed> $attributes */
    private function completedPlan(Risk $risk, array $attributes = []): TreatmentPlan
    {
        $n = ++$this->planSequence;

        return TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $risk->id,
            'treatment_code' => 'TP-TEST-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'strategy' => 'mitigate',
            'action_title' => 'Deploy the control '.$n,
            'action_description' => 'Fixture treatment plan '.$n,
            'owner_id' => $this->actor->id,
            'target_date' => now()->subWeek()->toDateString(),
            'priority' => 'high',
            'status' => 'completed',
            'progress_pct' => 100,
            'completion_date' => now()->toDateString(),
            'expected_risk_reduction' => ['likelihood_reduction' => 1, 'impact_reduction' => 1],
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private int $planSequence = 0;
}
