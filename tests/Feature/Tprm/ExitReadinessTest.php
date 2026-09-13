<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\ExitPlanStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ExitPlan;
use App\Models\Tprm\ExitTest;
use App\Models\Tprm\Finding;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Exit\ExitPlanService;
use App\Services\Tprm\Scoring\ResidualScoringService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * AC-11 — exit staleness.
 *
 * "A Critical engagement whose exit plan was last tested 13 months ago
 * (policy 12) shows `stale`, raises a High finding, and adds `SU = 4`."
 *
 * THE THREE CONSEQUENCES ARE ASSERTED TOGETHER because they are one act. A
 * status set by one job, a finding raised by another and an uplift computed
 * live would disagree the moment one of them failed — and the one that fails
 * silently is the score.
 */
class ExitReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $owner;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@lub.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->engagement = $this->makeCriticalEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-11, all three consequences */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_thirteen_month_old_test_is_stale_raises_a_high_finding_and_costs_four_points(): void
    {
        $plan = $this->planTestedMonthsAgo(13);

        $result = app(ExitPlanService::class)->markStale($this->bank->id);

        $this->assertSame(1, $result['marked']);
        $this->assertSame(1, $result['findings']);

        // 1. Shows stale.
        $this->assertSame(ExitPlanStatus::Stale, $plan->refresh()->status);

        // 2. A High finding.
        $finding = Finding::query()
            ->where('engagement_id', $this->engagement->id)
            ->where('title', 'Exit plan is past its test interval')
            ->firstOrFail();

        $this->assertSame('high', $finding->severity->value);
        $this->assertStringContainsString('13 months ago', $finding->description);

        // 3. SU = 4, and it is the exit plan that put it there.
        $run = app(ResidualScoringService::class)->score($this->engagement->fresh(), 'test');

        $signals = collect($run->explanation['signals']['contributions'] ?? []);
        $exitSignal = $signals->firstWhere('type', 'exit_plan_stale');

        $this->assertNotNull($exitSignal, 'The stale exit plan should contribute to SU.');
        $this->assertSame(4.0, (float) $exitSignal['penalty']);
    }

    #[Test]
    public function a_plan_tested_within_the_interval_is_not_stale(): void
    {
        $plan = $this->planTestedMonthsAgo(6);

        $result = app(ExitPlanService::class)->markStale($this->bank->id);

        $this->assertSame(0, $result['marked']);
        $this->assertNotSame(ExitPlanStatus::Stale, $plan->refresh()->status);

        $run = app(ResidualScoringService::class)->score($this->engagement->fresh(), 'test');

        $this->assertNull(
            collect($run->explanation['signals']['contributions'] ?? [])->firstWhere('type', 'exit_plan_stale'),
        );
    }

    #[Test]
    public function the_interval_comes_from_the_tier_policy_not_a_constant(): void
    {
        // "Critical is tested annually" is a decision a risk committee owns.
        \App\Models\Tprm\TierPolicy::query()
            ->where('organization_id', $this->bank->id)
            ->where('tier', RiskTier::Critical->value)
            ->update(['exit_test_frequency_months' => 6]);

        $plan = $this->planTestedMonthsAgo(8);

        // Eight months would be current under a twelve-month policy and is
        // stale under a six-month one.
        $this->assertSame(1, app(ExitPlanService::class)->markStale($this->bank->id)['marked']);
        $this->assertSame(ExitPlanStatus::Stale, $plan->refresh()->status);
    }

    #[Test]
    public function testing_a_stale_plan_clears_the_status_and_the_uplift_and_queues_the_finding(): void
    {
        $plan = $this->planTestedMonthsAgo(13);
        app(ExitPlanService::class)->markStale($this->bank->id);

        app(ExitPlanService::class)->recordTest($plan->refresh(), [
            'test_date' => now()->toDateString(),
            'test_type' => ExitTest::TYPE_DESKTOP,
            'outcome' => ExitTest::OUTCOME_SUCCESSFUL,
            'scenario' => 'Provider gives notice; migrate to the alternative in the plan.',
            'participants' => ['Head of Operations', 'CISO'],
        ], $this->owner->id);

        $plan->refresh();

        $this->assertSame(ExitPlanStatus::Tested, $plan->status);
        $this->assertEquals(now()->addMonths(12)->toDateString(), $plan->next_test_due->toDateString());

        $finding = Finding::query()
            ->where('engagement_id', $this->engagement->id)
            ->where('title', 'Exit plan is past its test interval')
            ->firstOrFail();

        /*
         * IT IS NOT CLOSED, and that is right. `FindingService::close()`
         * refuses a remediated High finding without a named verifier and
         * attached evidence — the guard that stops "the work was described,
         * not checked". A service closing its own findings to keep its own
         * screen tidy would be the first exception to it.
         *
         * It moves into verification instead, where a person closes it.
         */
        $this->assertSame('under_verification', $finding->status->value);
        $this->assertStringContainsString('was tested on', (string) $finding->remediation_plan);

        $run = app(ResidualScoringService::class)->score($this->engagement->fresh(), 'test');
        $this->assertNull(
            collect($run->explanation['signals']['contributions'] ?? [])->firstWhere('type', 'exit_plan_stale'),
        );
    }

    #[Test]
    public function a_failed_test_still_resets_the_interval(): void
    {
        $plan = $this->planTestedMonthsAgo(13);
        app(ExitPlanService::class)->markStale($this->bank->id);

        // A desktop walk that discovered the data could not be extracted in
        // the agreed format is the most valuable exercise a bank can run. A
        // model that only recognised successful tests would push people to
        // record failures as nothing at all.
        app(ExitPlanService::class)->recordTest($plan->refresh(), [
            'test_date' => now()->toDateString(),
            'test_type' => ExitTest::TYPE_DESKTOP,
            'outcome' => ExitTest::OUTCOME_FAILED,
            'gaps_identified' => ['The extract could not be produced in the agreed format.'],
        ], $this->owner->id);

        $this->assertSame(ExitPlanStatus::Tested, $plan->refresh()->status);
        $this->assertTrue($plan->next_test_due->isFuture());
    }

    #[Test]
    public function marking_stale_twice_does_not_raise_a_second_finding(): void
    {
        $this->planTestedMonthsAgo(13);

        app(ExitPlanService::class)->markStale($this->bank->id);
        $second = app(ExitPlanService::class)->markStale($this->bank->id);

        $this->assertSame(0, $second['marked']);
        $this->assertSame(1, Finding::query()
            ->where('title', 'Exit plan is past its test interval')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  The requirement gate */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_critical_engagement_requires_an_exit_plan(): void
    {
        $requirement = app(ExitPlanService::class)->requirement($this->engagement);

        $this->assertTrue($requirement['required']);
    }

    #[Test]
    public function an_engagement_supporting_a_critical_function_requires_one_whatever_its_tier(): void
    {
        $this->engagement->forceFill([
            'effective_tier' => RiskTier::Moderate->value,
            'supports_critical_function' => true,
        ])->save();

        $requirement = app(ExitPlanService::class)->requirement($this->engagement->fresh());

        // Tier is about the vendor; criticality is about what the bank would
        // lose. A Medium-tier engagement holding up clearing still needs a way
        // out.
        $this->assertTrue($requirement['required']);
        $this->assertStringContainsString('critical or important business function', $requirement['basis']);
    }

    /* ------------------------------------------------------------------ */

    private function planTestedMonthsAgo(int $months): ExitPlan
    {
        $plan = ExitPlan::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $this->engagement->id,
        ]);

        $testedOn = now()->subMonths($months);

        $plan->forceFill([
            'status' => ExitPlanStatus::Tested->value,
            'approved_at' => $testedOn->copy()->subMonth(),
            'last_tested_at' => $testedOn->toDateString(),
            'next_test_due' => $testedOn->copy()
                ->addMonths(app(ExitPlanService::class)->intervalMonths($plan))
                ->toDateString(),
        ])->save();

        return $plan->refresh();
    }

    private function makeCriticalEngagement(): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-'.Str::upper(Str::random(6)),
            'name' => 'Core banking hosting',
            'service_description' => 'Fixture.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->owner->id,
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
            'inherent_score' => 88,
            'inherent_tier' => RiskTier::Critical->value,
            'effective_tier' => RiskTier::Critical->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 88, 'resulting_tier' => RiskTier::Critical->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        return $engagement->refresh();
    }
}
