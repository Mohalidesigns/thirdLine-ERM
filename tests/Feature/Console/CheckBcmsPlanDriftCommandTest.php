<?php

namespace Tests\Feature\Console;

use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Enums\Bcms\PlanType;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Finding;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Bia\BiaAssessmentService;
use App\Services\Bcms\Plans\PlanAssembler;
use App\Services\Bcms\Plans\PlanService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `bcms:check-plan-drift` — the nightly re-resolve-and-compare sweep.
 *
 * `PlanDriftDetector::check()` has direct unit coverage in
 * Phase3PlanBuilderTest; this covers the wrapper's own responsibilities: the
 * per-organisation loop, the `--no-findings` flag, and — because
 * `raiseFinding()` refuses a second open finding for the same plan — that the
 * command is safe to run nightly without piling up duplicate findings.
 */
class CheckBcmsPlanDriftCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $author;

    private User $approver;

    private BusinessUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $this->author = $this->user('author@khb.test');
        $this->approver = $this->user('approver@khb.test');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_flags_a_drifted_section_on_an_approved_plan_and_raises_one_finding(): void
    {
        $process = $this->processWithApprovedBia('BCP-CORE', rto: 2, mtpd: 8);
        $plan = $this->approvedPlan();

        // A second, later approved assessment with a different RTO — the same
        // move Phase3PlanBuilderTest uses to drift a bound section.
        $this->approvedAssessment($process, rto: 6, mtpd: 24);

        TenantContext::clear();

        $this->artisan('bcms:check-plan-drift')->assertSuccessful();

        TenantContext::set($this->organization->id);
        $section = $plan->sections()->where('section_key', 'recovery_objectives')->sole();
        $findingCount = Finding::query()->where('affected_plan_id', $plan->getKey())->count();
        TenantContext::clear();

        $this->assertTrue((bool) $section->refresh()->needs_review, 'The wrapper did not flag the drifted section.');
        $this->assertSame(1, $findingCount, 'The wrapper did not raise the finding it owes for a drifted approved plan.');

        // Idempotent: a second nightly run must not raise a second finding for
        // the same plan.
        $this->artisan('bcms:check-plan-drift')->assertSuccessful();

        TenantContext::set($this->organization->id);
        $findingCountAfterSecondRun = Finding::query()->where('affected_plan_id', $plan->getKey())->count();
        TenantContext::clear();

        $this->assertSame(1, $findingCountAfterSecondRun, 'Running the sweep twice raised a second finding for the same plan.');
    }

    #[Test]
    public function it_no_ops_quietly_when_nothing_has_drifted(): void
    {
        $this->processWithApprovedBia('BCP-STABLE', rto: 4, mtpd: 24);
        $plan = $this->approvedPlan();

        $this->artisan('bcms:check-plan-drift')->assertSuccessful();

        TenantContext::set($this->organization->id);
        $needsReview = $plan->sections()->where('needs_review', true)->count();
        $findingCount = Finding::query()->where('affected_plan_id', $plan->getKey())->count();
        TenantContext::clear();

        $this->assertSame(0, $needsReview);
        $this->assertSame(0, $findingCount);
    }

    #[Test]
    public function no_findings_flag_flags_sections_but_raises_nothing(): void
    {
        $process = $this->processWithApprovedBia('BCP-NF', rto: 2, mtpd: 8);
        $plan = $this->approvedPlan();

        $this->approvedAssessment($process, rto: 9, mtpd: 24);

        TenantContext::clear();

        $this->artisan('bcms:check-plan-drift', ['--no-findings' => true])->assertSuccessful();

        TenantContext::set($this->organization->id);
        $section = $plan->sections()->where('section_key', 'recovery_objectives')->sole();
        $findingCount = Finding::query()->where('affected_plan_id', $plan->getKey())->count();
        TenantContext::clear();

        $this->assertTrue((bool) $section->refresh()->needs_review, '--no-findings must still flag the section.');
        $this->assertSame(0, $findingCount, '--no-findings raised a finding anyway.');
    }

    #[Test]
    public function each_organisation_is_swept_under_its_own_tenant_context(): void
    {
        $processA = $this->processWithApprovedBia('BCP-A', rto: 2, mtpd: 8);
        $planA = $this->approvedPlan('Plan A');
        $this->approvedAssessment($processA, rto: 6, mtpd: 24);
        TenantContext::clear();

        $orgB = Organization::create([
            'name' => 'Second Bank', 'short_name' => 'SB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        TenantContext::set($orgB->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($orgB->id);
        $unitB = BusinessUnit::create([
            'organization_id' => $orgB->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);
        $authorB = User::create([
            'name' => 'Author B', 'email' => 'author@sb.test', 'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(), 'organization_id' => $orgB->id, 'is_active' => true,
        ]);
        $approverB = User::create([
            'name' => 'Approver B', 'email' => 'approver@sb.test', 'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(), 'organization_id' => $orgB->id, 'is_active' => true,
        ]);
        $processB = Process::query()->create([
            'code' => 'BCP-B', 'name' => 'Process B', 'status' => 'active',
            'business_unit_id' => $unitB->id, 'owner_id' => $authorB->id, 'criticality_tier' => 1,
        ]);
        $assessments = app(BiaAssessmentService::class);
        $assessment = $assessments->start($processB, $authorB->id);
        $assessments->save($assessment, [
            'mtpd_hours' => 8, 'rto_hours' => 2, 'rpo_minutes' => 15,
            'mbco_description' => 'Minimum service for BCP-B.',
        ]);
        foreach (ImpactHorizon::cases() as $horizon) {
            $assessments->scoreImpact($assessment, ImpactCategory::Regulatory, $horizon, $horizon->hours() >= 8 ? 5 : 2, null, 'Seeded.');
        }
        $assessments->submit($assessment->refresh());
        $assessments->approve($assessment->refresh(), $approverB->id, 1);
        TenantContext::clear();

        $this->artisan('bcms:check-plan-drift')->assertSuccessful();

        TenantContext::set($this->organization->id);
        $sectionA = $planA->sections()->where('section_key', 'recovery_objectives')->sole();
        TenantContext::clear();

        $this->assertTrue((bool) $sectionA->refresh()->needs_review, "Bank A's drifted plan was not flagged.");
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function process(string $code, array $attributes = []): Process
    {
        return Process::query()->create(array_merge([
            'code' => $code, 'name' => "Process {$code}", 'status' => 'active',
            'business_unit_id' => $this->unit->id, 'owner_id' => $this->author->id,
        ], $attributes));
    }

    private function processWithApprovedBia(string $code, float $rto, float $mtpd, int $tier = 1): Process
    {
        $process = $this->process($code, ['criticality_tier' => $tier]);
        $this->approvedAssessment($process, $rto, $mtpd, $tier);

        return $process->refresh();
    }

    private function approvedAssessment(Process $process, float $rto, float $mtpd, int $tier = 1): BiaAssessment
    {
        $assessments = app(BiaAssessmentService::class);

        $assessment = $assessments->start($process, $this->author->id);

        $assessments->save($assessment, [
            'mtpd_hours' => $mtpd,
            'rto_hours' => $rto,
            'rpo_minutes' => 15,
            'mbco_description' => 'Minimum service for '.$process->code.'.',
        ]);

        foreach (ImpactHorizon::cases() as $horizon) {
            $assessments->scoreImpact(
                $assessment,
                ImpactCategory::Regulatory,
                $horizon,
                $horizon->hours() >= $mtpd ? 5 : 2,
                null,
                'Seeded for the test.',
            );
        }

        $assessments->submit($assessment->refresh());

        return $assessments->approve($assessment->refresh(), $this->approver->id, $tier);
    }

    private function templatedPlan(string $title = 'Group Business Continuity Plan'): Plan
    {
        return app(PlanService::class)->create(
            PlanType::Bcp,
            $title,
            ['business_unit_id' => $this->unit->id, 'owner_id' => $this->author->id, 'review_frequency_months' => 12],
            'bcp_group',
            $this->author->id,
        );
    }

    private function approvedPlan(string $title = 'Group Business Continuity Plan'): Plan
    {
        $plan = $this->templatedPlan($title);
        app(PlanAssembler::class)->assemble($plan, $this->author->id);
        app(PlanService::class)->submitForReview($plan, $this->author->id);

        return app(PlanService::class)->approve($plan, $this->approver, now()->toDateString(), 12);
    }
}
