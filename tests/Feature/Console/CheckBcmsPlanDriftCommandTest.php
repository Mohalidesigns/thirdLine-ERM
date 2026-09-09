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
        // Bank B gets its OWN drifted plan, not just a process. Without one it
        // has nothing for the sweep to find, so "no findings for B" would be
        // true whether the command visited B or skipped it entirely — which is
        // exactly how the first version of this test passed while asserting
        // nothing about the second tenant at all.
        $processB = $this->processWithApprovedBia('BCP-B', rto: 2, mtpd: 8, unit: $unitB, author: $authorB, approver: $approverB);
        $planB = $this->approvedPlan('Plan B', unit: $unitB, author: $authorB, approver: $approverB);
        $this->approvedAssessment($processB, rto: 6, mtpd: 24, author: $authorB, approver: $approverB);
        TenantContext::clear();

        $this->artisan('bcms:check-plan-drift')->assertSuccessful();

        TenantContext::set($this->organization->id);
        $sectionA = $planA->sections()->where('section_key', 'recovery_objectives')->sole();
        TenantContext::clear();

        TenantContext::set($orgB->id);
        $sectionB = $planB->sections()->where('section_key', 'recovery_objectives')->sole();
        TenantContext::clear();

        // BOTH tenants must be swept. A wrapper that set no context, or that
        // broke out of its loop after the first organisation, flags only one.
        $this->assertTrue((bool) $sectionA->refresh()->needs_review, "Bank A's drifted plan was not flagged.");
        $this->assertTrue((bool) $sectionB->refresh()->needs_review, "Bank B's drifted plan was not flagged — the sweep did not reach the second tenant.");

        // And each finding must be ATTRIBUTED to the tenant it belongs to.
        // Scopes are bypassed deliberately: with them on, a finding stamped
        // with the wrong organization_id is invisible rather than wrong, which
        // is the failure mode this assertion exists to catch.
        $findingsA = Finding::query()->withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)->where('affected_plan_id', $planA->getKey())->count();
        $findingsB = Finding::query()->withoutGlobalScopes()
            ->where('organization_id', $orgB->id)->where('affected_plan_id', $planB->getKey())->count();
        $findingsTotal = Finding::query()->withoutGlobalScopes()
            ->whereIn('organization_id', [$this->organization->id, $orgB->id])->count();

        $this->assertSame(1, $findingsA, "Bank A's finding is missing or was stamped with another tenant's id.");
        $this->assertSame(1, $findingsB, "Bank B's finding is missing or was stamped with another tenant's id.");
        $this->assertSame(2, $findingsTotal, 'Findings were duplicated or cross-attributed between the two tenants.');
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
    private function process(string $code, array $attributes = [], ?BusinessUnit $unit = null, ?User $author = null): Process
    {
        return Process::query()->create(array_merge([
            'code' => $code, 'name' => "Process {$code}", 'status' => 'active',
            'business_unit_id' => ($unit ?? $this->unit)->id, 'owner_id' => ($author ?? $this->author)->id,
        ], $attributes));
    }

    private function processWithApprovedBia(
        string $code,
        float $rto,
        float $mtpd,
        int $tier = 1,
        ?BusinessUnit $unit = null,
        ?User $author = null,
        ?User $approver = null,
    ): Process {
        $process = $this->process($code, ['criticality_tier' => $tier], $unit, $author);
        $this->approvedAssessment($process, $rto, $mtpd, $tier, $author, $approver);

        return $process->refresh();
    }

    private function approvedAssessment(
        Process $process,
        float $rto,
        float $mtpd,
        int $tier = 1,
        ?User $author = null,
        ?User $approver = null,
    ): BiaAssessment {
        $assessments = app(BiaAssessmentService::class);

        $assessment = $assessments->start($process, ($author ?? $this->author)->id);

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

        return $assessments->approve($assessment->refresh(), ($approver ?? $this->approver)->id, $tier);
    }

    private function templatedPlan(string $title = 'Group Business Continuity Plan', ?BusinessUnit $unit = null, ?User $author = null): Plan
    {
        $unit ??= $this->unit;
        $author ??= $this->author;

        return app(PlanService::class)->create(
            PlanType::Bcp,
            $title,
            ['business_unit_id' => $unit->id, 'owner_id' => $author->id, 'review_frequency_months' => 12],
            'bcp_group',
            $author->id,
        );
    }

    private function approvedPlan(
        string $title = 'Group Business Continuity Plan',
        ?BusinessUnit $unit = null,
        ?User $author = null,
        ?User $approver = null,
    ): Plan {
        $author ??= $this->author;
        $approver ??= $this->approver;

        $plan = $this->templatedPlan($title, $unit, $author);
        app(PlanAssembler::class)->assemble($plan, $author->id);
        app(PlanService::class)->submitForReview($plan, $author->id);

        return app(PlanService::class)->approve($plan, $approver, now()->toDateString(), 12);
    }
}
