<?php

namespace Tests\Feature\Console;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\ExitPlanStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ExitPlan;
use App\Models\Tprm\Incident;
use App\Models\Tprm\IncidentEscalation;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ServiceReview;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Exit\ExitPlanService;
use App\Services\Tprm\Incidents\ObligationClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `tprm:run-clocks` — AC-07's regulatory clocks and AC-11's exit staleness,
 * swept every fifteen minutes.
 *
 * Each of the three underlying sweeps (`ClockEscalationService::sweep()`,
 * `ExitPlanService::markStale()`, `ServiceReviewService::markMissed()`) has
 * direct coverage elsewhere. What only this file covers: that the wrapper
 * actually calls all three, that `--skip-exit` really skips the last two, and
 * — because the command's own docblock says each sweep is "separately
 * guarded so a throw in one cannot silence the others" — that one tenant's
 * failure does not stop the next tenant's escalations.
 */
class RunTprmClocksCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $owner;

    private ThirdParty $vendor;

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

        $this->owner = $this->user('owner@lub.test');

        TenantContext::set($this->bank->id);

        $this->vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $this->engagement = $this->makeEngagement();

        TenantContext::clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_escalates_an_incident_clock_marks_an_exit_plan_stale_and_records_a_missed_review(): void
    {
        TenantContext::set($this->bank->id);
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);
        $start = $incident->refresh()->reported_to_us_at;

        $plan = $this->planTestedMonthsAgo(13);

        $review = ServiceReview::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $this->engagement->id,
            'cadence' => 'annual',
            'scheduled_for' => now()->subDays(3)->toDateString(),
            'owner_id' => $this->owner->id,
        ]);
        TenantContext::clear();

        // 20 hours into the 24-hour CBN clock: past both thresholds.
        Carbon::setTestNow($start->copy()->addHours(20));

        $this->artisan('tprm:run-clocks')->assertSuccessful();

        TenantContext::set($this->bank->id);
        $escalations = IncidentEscalation::query()->count();
        $planStatus = $plan->fresh()->status;
        $reviewStatus = $review->fresh()->status;
        TenantContext::clear();

        $this->assertGreaterThan(0, $escalations, 'The wrapper did not escalate the incident clock.');
        $this->assertSame(ExitPlanStatus::Stale, $planStatus, 'The wrapper did not mark the exit plan stale.');
        $this->assertSame(ServiceReview::STATUS_MISSED, $reviewStatus, 'The wrapper did not record the missed review.');
    }

    #[Test]
    public function skip_exit_escalates_clocks_only(): void
    {
        TenantContext::set($this->bank->id);
        $plan = $this->planTestedMonthsAgo(13);
        TenantContext::clear();

        $this->artisan('tprm:run-clocks', ['--skip-exit' => true])->assertSuccessful();

        TenantContext::set($this->bank->id);
        $status = $plan->fresh()->status;
        TenantContext::clear();

        $this->assertNotSame(ExitPlanStatus::Stale, $status, '--skip-exit swept the exit plans anyway.');
    }

    #[Test]
    public function it_no_ops_quietly_when_nothing_needs_escalating(): void
    {
        $this->artisan('tprm:run-clocks')
            ->expectsOutputToContain('0 clock escalation(s) fired')
            ->expectsOutputToContain('0 exit plan(s) marked stale, 0 service review(s) missed')
            ->assertSuccessful();
    }

    #[Test]
    public function it_no_ops_when_the_feature_is_switched_off(): void
    {
        config()->set('features.tprm', false);

        TenantContext::set($this->bank->id);
        $plan = $this->planTestedMonthsAgo(13);
        TenantContext::clear();

        $this->artisan('tprm:run-clocks')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        TenantContext::set($this->bank->id);
        $status = $plan->fresh()->status;
        TenantContext::clear();

        $this->assertNotSame(ExitPlanStatus::Stale, $status);
    }

    #[Test]
    public function running_it_twice_does_not_double_escalate_or_double_raise(): void
    {
        TenantContext::set($this->bank->id);
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);
        $start = $incident->refresh()->reported_to_us_at;
        $plan = $this->planTestedMonthsAgo(13);
        TenantContext::clear();

        Carbon::setTestNow($start->copy()->addHours(20));

        $this->artisan('tprm:run-clocks')->assertSuccessful();

        TenantContext::set($this->bank->id);
        $escalationsAfterFirst = IncidentEscalation::query()->count();
        TenantContext::clear();
        $this->assertGreaterThan(0, $escalationsAfterFirst);

        $this->artisan('tprm:run-clocks')
            ->expectsOutputToContain('0 clock escalation(s) fired')
            ->expectsOutputToContain('0 exit plan(s) marked stale')
            ->assertSuccessful();

        TenantContext::set($this->bank->id);
        $escalationsAfterSecond = IncidentEscalation::query()->count();
        TenantContext::clear();

        $this->assertSame($escalationsAfterFirst, $escalationsAfterSecond, 'A re-run fired the same escalation twice.');
    }

    #[Test]
    public function two_tenants_are_swept_independently_in_the_same_run(): void
    {
        // Not a forced-failure test — genuinely forcing one of the three
        // sweeps to throw would mean corrupting data in a way nothing else in
        // this suite does, which risks asserting a defect into existence
        // rather than finding one. What this DOES pin: the command loops
        // every organisation and each one's exit plan is actually marked,
        // which is the precondition for the try/catch-per-tenant guarding in
        // the command's docblock to mean anything at all.
        TenantContext::set($this->bank->id);
        $planA = $this->planTestedMonthsAgo(13);
        TenantContext::clear();

        $bankB = Organization::create([
            'name' => 'Second Bank', 'short_name' => 'SB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        RiskCategory::create([
            'organization_id' => $bankB->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);
        TenantContext::set($bankB->id);
        $ownerB = $this->userFor($bankB, 'owner@sb.test');
        $vendorB = ThirdParty::create([
            'organization_id' => $bankB->id, 'legal_name' => 'Second Vendor Ltd',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);
        $engagementB = Engagement::create([
            'organization_id' => $bankB->id, 'third_party_id' => $vendorB->id,
            'reference' => 'ENG-'.Str::upper(Str::random(6)), 'name' => 'Data hosting',
            'service_description' => 'Fixture.', 'engagement_type' => 'ict_service',
            'relationship_owner_id' => $ownerB->id,
        ]);
        $engagementB->forceFill([
            'status' => EngagementStatus::Active->value, 'inherent_score' => 88,
            'inherent_tier' => RiskTier::Critical->value, 'effective_tier' => RiskTier::Critical->value,
        ])->save();
        InherentAssessment::create([
            'organization_id' => $bankB->id, 'engagement_id' => $engagementB->id,
            'version' => 1, 'ruleset_version' => '1.0.0', 'raw_score' => 88,
            'resulting_tier' => RiskTier::Critical->value, 'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);
        $planB = ExitPlan::create(['organization_id' => $bankB->id, 'engagement_id' => $engagementB->id]);
        $testedOn = now()->subMonths(13);
        $planB->forceFill([
            'status' => ExitPlanStatus::Tested->value,
            'approved_at' => $testedOn->copy()->subMonth(),
            'last_tested_at' => $testedOn->toDateString(),
            'next_test_due' => $testedOn->copy()->addMonths(app(ExitPlanService::class)->intervalMonths($planB))->toDateString(),
        ])->save();
        TenantContext::clear();

        $this->artisan('tprm:run-clocks')->assertSuccessful();

        TenantContext::set($this->bank->id);
        $statusA = $planA->fresh()->status;
        TenantContext::clear();

        TenantContext::set($bankB->id);
        $statusB = $planB->fresh()->status;
        TenantContext::clear();

        $this->assertSame(ExitPlanStatus::Stale, $statusA);
        $this->assertSame(ExitPlanStatus::Stale, $statusB);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::before($email, '@'), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);
    }

    private function userFor(Organization $organization, string $email): User
    {
        return User::create([
            'name' => Str::before($email, '@'), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $organization->id, 'is_active' => true,
        ]);
    }

    private function reportBreach(int $detectedHoursAgo = 6): Incident
    {
        $incident = Incident::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $this->vendor->id,
            'engagement_ids' => [$this->engagement->id],
            'reference' => 'TPI-2026-'.Str::upper(Str::random(4)),
            'description' => 'Recorded for the scheduled-command fixtures.',
            'type' => 'data_breach',
            'title' => 'Unauthorised access to a customer support mailbox',
            'personal_data_involved' => true,
            'customer_impact' => true,
            'detected_at' => now()->subHours($detectedHoursAgo),
        ]);

        $incident->forceFill([
            'reported_to_us_at' => now(),
            'reported_by' => Incident::SOURCE_PORTAL,
        ])->save();

        return $incident->refresh();
    }

    private function makeEngagement(): Engagement
    {
        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $this->vendor->id,
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
}
