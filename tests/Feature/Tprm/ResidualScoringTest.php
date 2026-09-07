<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\FindingStatus;
use App\Enums\Tprm\RiskBand;
use App\Enums\Tprm\RiskTier;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\RiskAcceptance;
use App\Models\Tprm\ScoreRun;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Findings\FindingService;
use App\Services\Tprm\Findings\RiskAcceptanceService;
use App\Services\Tprm\Integration\ErmBridge;
use App\Services\Tprm\Scoring\ResidualScoringService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The scoring loop end to end — AC-15 and the phase's acceptance criteria.
 *
 * The pure arithmetic is pinned in `Tests\Unit\Tprm\Scoring`; this is about
 * everything around it. Does a finding actually move the score in the same
 * request. Does the stored explanation let two people see the same derivation.
 * Does a lapsed risk acceptance reopen its finding and put the weight back.
 * Does any of it reach the ERM register, which is the thing this whole module
 * was asked for.
 */
class ResidualScoringTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->user = $this->userWith([
            'tprm.view', 'tprm.finding.view', 'tprm.finding.manage', 'tprm.finding.accept_risk',
        ], 'cro@khb.test', 'tprm-cro');

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The score moves in the same request */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function raising_a_finding_moves_the_residual_score_immediately(): void
    {
        // The phase acceptance: "the residual score updates within the same
        // request cycle for a single engagement". A queued recomputation means
        // a user closes a finding, watches the score not move, and refreshes.
        app(ResidualScoringService::class)->score($this->engagement, 'test');
        $before = (float) $this->engagement->fresh()->residual_score;

        app(FindingService::class)->raise(
            $this->engagement,
            'manual',
            FindingSeverity::High,
            'Privileged access is not reviewed',
            [],
            $this->user->id,
        );

        $after = (float) $this->engagement->fresh()->residual_score;

        $this->assertGreaterThan($before, $after);
        $this->assertSame(7.0, round($after - $before, 2));
    }

    #[Test]
    public function a_finding_moving_from_in_sla_to_overdue_raises_the_uplift_by_the_multiplier(): void
    {
        // The named acceptance criterion. In-SLA with a plan is ×0.5, overdue
        // beyond twice the SLA is ×1.5 — a swing of a full base penalty.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->user->id,
        );

        app(FindingService::class)->recordPlan($finding, 'The vendor will deploy MFA by March.', null, $this->user->id);
        app(ResidualScoringService::class)->score($this->engagement, 'test');
        $inSla = (float) $this->engagement->fresh()->residual_score;

        // Push it past twice its SLA by moving when it was identified.
        $finding->fresh()->forceFill([
            'identified_at' => now()->subDays($finding->sla_days * 2 + 5),
            'target_date' => now()->subDays($finding->sla_days + 5)->toDateString(),
        ])->save();

        app(ResidualScoringService::class)->score($this->engagement->fresh(), 'test');
        $overdue = (float) $this->engagement->fresh()->residual_score;

        // 7 × 0.5 = 3.5 becomes 7 × 1.5 = 10.5.
        $this->assertSame(7.0, round($overdue - $inSla, 2));
    }

    #[Test]
    public function closing_a_finding_takes_its_weight_back_out(): void
    {
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Medium, 'A gap', [], $this->user->id,
        );

        $raised = (float) $this->engagement->fresh()->residual_score;

        // Walks the lifecycle, because the machine refuses a jump from open to
        // closed — which is the control, not an obstacle.
        app(FindingService::class)->recordPlan($finding->fresh(), 'Access review reinstated.', null, $this->user->id);
        app(FindingService::class)->submitForVerification($finding->fresh(), null, $this->user->id);
        $result = app(FindingService::class)->close($finding->fresh(), 'remediated', $this->user->id, null, $this->user->id);

        $this->assertTrue($result['closed'], (string) $result['reason']);
        $this->assertLessThan($raised, (float) $this->engagement->fresh()->residual_score);
    }

    /* ------------------------------------------------------------------ */
    /*  AC-15 — two users, one derivation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_explanation_is_stored_and_read_rather_than_recomputed(): void
    {
        // The whole basis of AC-15. If the panel recomputed on read, two users
        // opening it either side of a change would see different derivations
        // for the same displayed score.
        $run = app(ResidualScoringService::class)->score($this->engagement, 'test');

        $first = ScoreRun::query()->find($run->getKey())->explanation;
        $second = ScoreRun::query()->find($run->getKey())->explanation;

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first['headline']);
        $this->assertNotEmpty($first['arithmetic']);
        $this->assertArrayHasKey('inherent', $first);
        $this->assertArrayHasKey('mitigation', $first);
        $this->assertArrayHasKey('findings', $first);
        $this->assertArrayHasKey('signals', $first);
        $this->assertArrayHasKey('data_confidence', $first);
        // The versions are part of the derivation, not metadata beside it:
        // "the model changed" and "the vendor changed" are different
        // conversations.
        $this->assertSame(config('tprm.engine_version'), $first['versions']['engine_version']);
    }

    #[Test]
    public function a_recomputation_writes_a_new_run_and_never_edits_the_old_one(): void
    {
        // TRD §7.9: scores are never recomputed retrospectively. A ruleset
        // change produces a new run and a reportable diff.
        $first = app(ResidualScoringService::class)->score($this->engagement, 'test');

        app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'A serious gap', [], $this->user->id,
        );

        $runs = ScoreRun::query()->where('engagement_id', $this->engagement->id)->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(2, $runs->count());
        // The original is untouched.
        $this->assertSame(
            (float) $first->rr,
            (float) ScoreRun::query()->find($first->getKey())->rr,
        );
        $this->assertGreaterThan((float) $first->rr, (float) $runs->last()->rr);
    }

    #[Test]
    public function the_run_carries_the_inputs_needed_to_reproduce_it(): void
    {
        app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->user->id,
        );

        $run = ScoreRun::query()->where('engagement_id', $this->engagement->id)->latest('id')->firstOrFail();

        $this->assertArrayHasKey('coefficients', $run->inputs);
        $this->assertEquals(7, $run->inputs['coefficients']['severity_penalties']['high']);
        $this->assertCount(1, $run->inputs['findings']);
        $this->assertNotEmpty($run->engine_version);
    }

    /* ------------------------------------------------------------------ */
    /*  Risk acceptance */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function accepting_a_risk_halves_its_weight_rather_than_removing_it(): void
    {
        // A score that dropped to zero on acceptance would make acceptance the
        // cheapest way to improve a rating.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'A serious gap', [], $this->user->id,
        );

        $withFinding = (float) $this->engagement->fresh()->residual_score;

        $result = app(RiskAcceptanceService::class)->accept($finding->fresh(), $this->user, [
            'justification' => 'The vendor is being replaced in the next quarter and the exposure is bounded.',
            'expires_at' => now()->addMonths(3)->toDateString(),
        ]);

        $this->assertTrue($result['accepted'], (string) $result['reason']);

        $accepted = (float) $this->engagement->fresh()->residual_score;

        // 12 becomes 6.
        $this->assertSame(6.0, round($withFinding - $accepted, 2));
    }

    #[Test]
    public function a_critical_acceptance_needs_the_risk_functions_permission(): void
    {
        // A register in which any analyst can accept a Critical finding is a
        // register with no Critical findings in it.
        $analyst = $this->userWith(['tprm.finding.manage'], 'analyst@khb.test', 'tprm-analyst');

        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'A serious gap', [], $this->user->id,
        );

        $result = app(RiskAcceptanceService::class)->accept($finding, $analyst, [
            'justification' => 'A justification long enough to be meaningful to a committee.',
            'expires_at' => now()->addMonths(3)->toDateString(),
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('tprm.finding.accept_risk', (string) $result['reason']);
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    #[Test]
    public function a_critical_risk_cannot_be_accepted_for_longer_than_six_months(): void
    {
        // A risk accepted for three years is not a decision, it is a decision
        // avoided.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'A serious gap', [], $this->user->id,
        );

        $result = app(RiskAcceptanceService::class)->accept($finding, $this->user, [
            'justification' => 'A justification long enough to be meaningful to a committee.',
            'expires_at' => now()->addYears(3)->toDateString(),
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertStringContainsString('at most 6 months', (string) $result['reason']);
    }

    #[Test]
    public function a_lapsed_acceptance_reopens_the_finding_and_restores_its_full_weight(): void
    {
        // The whole point of the mandatory expiry. Without it, the finding
        // leaves the board pack and two years later the institution is
        // carrying a risk nobody decided to keep.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->user->id,
        );

        app(RiskAcceptanceService::class)->accept($finding->fresh(), $this->user, [
            'justification' => 'Accepted while the replacement is procured.',
            'expires_at' => now()->addMonths(2)->toDateString(),
        ]);

        $accepted = (float) $this->engagement->fresh()->residual_score;

        // Lapse it.
        RiskAcceptance::query()->where('finding_id', $finding->id)
            ->update(['expires_at' => now()->subDay()->toDateString()]);

        $reopened = app(RiskAcceptanceService::class)->reopenLapsed();

        $this->assertCount(1, $reopened);
        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
        $this->assertSame(RiskAcceptance::STATUS_EXPIRED, RiskAcceptance::query()
            ->where('finding_id', $finding->id)->value('status'));

        // Back to full weight: 3.5 becomes 7.
        $this->assertSame(3.5, round((float) $this->engagement->fresh()->residual_score - $accepted, 2));
    }

    #[Test]
    public function the_nightly_sweep_reopens_lapsed_acceptances_and_rescores(): void
    {
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->user->id,
        );

        app(RiskAcceptanceService::class)->accept($finding->fresh(), $this->user, [
            'justification' => 'Accepted while the replacement is procured.',
            'expires_at' => now()->addMonths(2)->toDateString(),
        ]);

        RiskAcceptance::query()->where('finding_id', $finding->id)
            ->update(['expires_at' => now()->subDay()->toDateString()]);

        $this->artisan('tprm:recompute-scores')
            ->expectsOutputToContain('1 lapsed acceptance(s) reopened')
            ->assertExitCode(0);

        $this->assertSame(FindingStatus::Open, $finding->fresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /*  Findings */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function raising_the_same_gap_twice_does_not_duplicate_it(): void
    {
        // Re-scoring an assessment, re-running a clause analysis and
        // re-confirming a corrected SOC 2 are all ordinary things to do twice.
        // A register that duplicated would double the residual score for a
        // reason nobody could see.
        $first = app(FindingService::class)->raise(
            $this->engagement, 'assessment', FindingSeverity::High, 'CBN-ACC-02 — MFA', ['source_id' => 42],
        );

        $second = app(FindingService::class)->raise(
            $this->engagement, 'assessment', FindingSeverity::High, 'CBN-ACC-02 — MFA', ['source_id' => 42],
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Finding::query()->count());
    }

    #[Test]
    public function the_target_date_comes_from_the_tier_policy_and_is_then_frozen(): void
    {
        // "We were compliant under the policy in force at the time" is a claim
        // a supervisor is entitled to test, and they cannot against a register
        // that silently re-dates itself.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'A gap', [], $this->user->id,
        );

        $original = $finding->target_date->toDateString();
        $slaDays = $finding->sla_days;

        \App\Models\Tprm\TierPolicy::query()
            ->where('tier', $this->engagement->effectiveTier()->value)
            ->update(['remediation_sla' => json_encode(['critical' => 1])]);

        $this->assertSame($original, $finding->fresh()->target_date->toDateString());
        $this->assertSame($slaDays, $finding->fresh()->sla_days);
    }

    #[Test]
    public function a_high_finding_cannot_be_closed_as_remediated_without_evidence(): void
    {
        // A Critical or High finding closed on an assertion is the commonest
        // way a remediation register becomes fiction.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->user->id,
        );

        app(FindingService::class)->recordPlan($finding, 'A plan.', null, $this->user->id);
        app(FindingService::class)->submitForVerification($finding->fresh(), null, $this->user->id);

        $result = app(FindingService::class)->close($finding->fresh(), 'remediated', $this->user->id, null, $this->user->id);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('needs evidence', (string) $result['reason']);
    }

    #[Test]
    public function a_remediated_closure_needs_a_verifier(): void
    {
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Low, 'A small gap', [], $this->user->id,
        );

        app(FindingService::class)->recordPlan($finding, 'A plan.', null, $this->user->id);
        app(FindingService::class)->submitForVerification($finding->fresh(), null, $this->user->id);

        $result = app(FindingService::class)->close($finding->fresh(), 'remediated', null, null, $this->user->id);

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('verified by somebody', (string) $result['reason']);
    }

    /* ------------------------------------------------------------------ */
    /*  The ERM bridge — the reason this module was asked for */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_finding_appears_in_the_erm_issue_register(): void
    {
        // Without the mirror, a bank runs two remediation registers and the
        // board pack from one does not reconcile to the other.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'Privileged access is not reviewed', [], $this->user->id,
        );

        $this->assertNotNull($finding->erm_issue_id);

        $issue = Issue::query()->find($finding->erm_issue_id);
        $this->assertSame('high', $issue->priority);
        $this->assertSame('ISS-'.$finding->reference, $issue->issue_reference);
        // Reconcilable by eye: an examiner holding both reports can see they
        // are the same thing without a lookup table.
        $this->assertStringContainsString($finding->reference, $issue->description);
    }

    #[Test]
    public function closing_the_finding_closes_the_mirrored_issue(): void
    {
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Low, 'A small gap', [], $this->user->id,
        );

        app(FindingService::class)->recordPlan($finding->fresh(), 'Fixed.', null, $this->user->id);
        app(FindingService::class)->submitForVerification($finding->fresh(), null, $this->user->id);
        app(FindingService::class)->close($finding->fresh(), 'remediated', $this->user->id, null, $this->user->id);

        $this->assertSame('CLOSED', Issue::query()->find($finding->fresh()->erm_issue_id)->issue_status);
    }

    #[Test]
    public function closing_the_issue_in_the_erm_register_closes_the_finding(): void
    {
        // The direction worth having: somebody who has never opened the TPRM
        // module should not leave a third-party finding open for another year.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Medium, 'A gap', [], $this->user->id,
        );

        Issue::query()->find($finding->erm_issue_id)->forceFill(['issue_status' => 'CLOSED'])->save();

        $closed = app(ErmBridge::class)->pullClosedIssues();

        $this->assertCount(1, $closed);
        $this->assertFalse($finding->fresh()->isOpen());
    }

    #[Test]
    public function a_high_tier_engagement_appears_in_the_erm_risk_register(): void
    {
        app(ResidualScoringService::class)->score($this->engagement, 'test');

        $engagement = $this->engagement->fresh();
        $this->assertNotNull($engagement->erm_risk_id);

        $risk = Risk::query()->find($engagement->erm_risk_id);
        $this->assertStringContainsString('Third-party dependency', $risk->title);
        $this->assertSame('TPR-'.$engagement->reference, $risk->risk_code);
        $this->assertSame(
            RiskCategory::query()->where('code', ErmBridge::CATEGORY_CODE)->value('id'),
            $risk->category_id,
        );
        // The scores travel across, so the ERM register shows the same number
        // the third-party register does.
        $this->assertSame((int) round((float) $engagement->residual_score), $risk->residual_score);
    }

    #[Test]
    public function a_low_tier_engagement_stays_out_of_the_risk_register(): void
    {
        // A risk register holding four hundred stationery suppliers is one
        // nobody reads.
        $low = $this->makeEngagement('ENG-2026-0002', RiskTier::Low, inherentScore: 20);

        app(ResidualScoringService::class)->score($low, 'test');

        $this->assertNull($low->fresh()->erm_risk_id);
    }

    #[Test]
    public function a_low_tier_engagement_with_a_critical_finding_does_appear(): void
    {
        $low = $this->makeEngagement('ENG-2026-0003', RiskTier::Low, inherentScore: 20);

        app(FindingService::class)->raise(
            $low, 'manual', FindingSeverity::Critical, 'A serious gap', [], $this->user->id,
        );

        $this->assertNotNull($low->fresh()->erm_risk_id);
    }

    #[Test]
    public function a_missing_erm_category_is_skipped_rather_than_fatal(): void
    {
        // TPRM working without the ERM link is a far smaller problem than TPRM
        // refusing to record a finding.
        RiskCategory::query()->where('code', ErmBridge::CATEGORY_CODE)->delete();

        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'A gap', [], $this->user->id,
        );

        $this->assertNotNull($finding->getKey());
        $this->assertNull($this->engagement->fresh()->erm_risk_id);
    }

    /* ------------------------------------------------------------------ */
    /*  Data confidence */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_engagement_with_nothing_recorded_scores_stale_and_cannot_close_a_review(): void
    {
        // No assessment, no evidence, never screened. The score is
        // arithmetically fine and worth nothing, and the badge says so.
        $run = app(ResidualScoringService::class)->score($this->engagement, 'test');

        $this->assertLessThan(0.5, (float) $run->dc);
        $this->assertSame('Stale', $run->explanation['data_confidence']['badge']);
        $this->assertFalse($run->explanation['data_confidence']['may_close_review']);
        $this->assertStringContainsString('may not be used', $run->explanation['headline']);
    }

    #[Test]
    public function an_unassessed_engagement_gets_no_mitigation_rather_than_a_default(): void
    {
        // A vendor nobody has assessed has demonstrated nothing. M = 0 leaves
        // the inherent score standing, which is the honest position.
        $run = app(ResidualScoringService::class)->score($this->engagement, 'test');

        $this->assertSame(0.0, (float) $run->m);
        $this->assertSame(70.0, (float) $run->rr);
        $this->assertSame(RiskBand::High, $run->band);
        $this->assertStringContainsString('demonstrated nothing', $run->explanation['mitigation']['note']);
    }

    /* ------------------------------------------------------------------ */

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->upper()->toString(),
            'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function makeEngagement(
        string $reference = 'ENG-2026-0001',
        RiskTier $tier = RiskTier::High,
        float $inherentScore = 70,
    ): Engagement {
        $vendor = ThirdParty::create([
            'legal_name' => 'Vendor '.Str::random(6), 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => 'Core banking hosting',
            'service_description' => 'Hosting and operation of the core banking platform.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->user->id,
        ]);

        $engagement->forceFill([
            'inherent_score' => $inherentScore,
            'inherent_tier' => $tier->value,
            'effective_tier' => $tier->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $engagement->id,
            'version' => 1,
            'ruleset_version' => '1.0.0',
            'raw_score' => $inherentScore,
            'resulting_tier' => $tier->value,
            'assessed_at' => now(),
            'is_current' => true,
            // Not nullable: the inherent assessment stores the answers it was
            // computed from, so a score can be replayed against them.
            'answers' => [],
            'factor_scores' => [],
            'weights' => [],
            'explanation' => [],
        ]);

        return $engagement->refresh();
    }
}
