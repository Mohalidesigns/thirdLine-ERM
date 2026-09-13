<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\BiaAssessmentStatus;
use App\Enums\Bcms\DependencyType;
use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Models\Bcms\Application;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Dependency;
use App\Models\Bcms\Process;
use App\Models\Bcms\Site;
use App\Models\BusinessUnit;
use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\BcmsSettings;
use App\Services\Bcms\Bia\BiaAssessmentService;
use App\Services\Bcms\Bia\BiaCampaignService;
use App\Services\Bcms\Bia\BiaValidator;
use App\Services\Bcms\Bia\DependencyService;
use App\Services\Bcms\Bia\MtpdDeriver;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 2 acceptance criteria.
 *
 * Criterion 3 is worth reading before the test that answers it: the prompt asks
 * that a dependency on an EA application "resolve to the live EA record — no
 * duplicated application row exists anywhere in `bcms_*`". **This product has no
 * Enterprise Architecture module** (ADR 0001), so `bcms_applications` IS the
 * application register — a named seam, not a duplicate. What is testable, and
 * what the criterion is actually protecting against, is that exactly ONE
 * application register exists, that the morph resolves to it, and that nothing
 * shadows it. That is what `the_application_register_is_not_duplicated()` asserts.
 */
class Phase2BiaEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $assessor;

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

        $this->assessor = $this->user('assessor@khb.test');
        $this->approver = $this->user('approver@khb.test');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = $this->user($email);

        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function process(string $code, array $attributes = []): Process
    {
        return Process::query()->create(array_merge([
            'code' => $code, 'name' => "Process {$code}", 'status' => 'active',
            'business_unit_id' => $this->unit->id, 'owner_id' => $this->assessor->id,
        ], $attributes));
    }

    private function assessment(Process $process, array $attributes = []): BiaAssessment
    {
        return app(BiaAssessmentService::class)->start($process, $this->assessor->id, null, $attributes);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 1 — a campaign distributes, collects and reports */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_campaign_distributes_to_twenty_process_owners_and_reports_its_progress(): void
    {
        $owners = [];

        for ($i = 1; $i <= 20; $i++) {
            $owners[$i] = $this->user("owner{$i}@khb.test");
            $this->process('BCP-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ['owner_id' => $owners[$i]->id]);
        }

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->addWeeks(2)]);

        $result = $campaigns->distribute($campaign);

        $this->assertSame(20, $result['created']);
        $this->assertSame([], $result['without_owner']);
        $this->assertSame(20, BiaAssessment::query()->where('campaign_id', $campaign->getKey())->count());

        // Every owner was actually asked — not just twenty rows created.
        $this->assertSame(
            collect($owners)->pluck('id')->sort()->values()->all(),
            BiaAssessment::query()->where('campaign_id', $campaign->getKey())->pluck('assessor_id')->sort()->values()->all(),
        );

        // Re-distributing is idempotent: the unique (campaign, process) pair
        // means a coordinator can safely press it again after adding a process.
        $again = $campaigns->distribute($campaign);
        $this->assertSame(0, $again['created']);
        $this->assertSame(20, $again['existing']);

        $progress = $campaigns->progress($campaign);
        $this->assertSame(20, $progress['counts']['total']);
        $this->assertSame(20, $progress['counts']['not_started']);
        $this->assertSame(0.0, $progress['response_rate']);
    }

    #[Test]
    public function a_process_with_no_owner_is_named_rather_than_silently_skipped(): void
    {
        // A campaign that reports full coverage having never asked anybody is
        // the failure this reports.
        $this->process('BCP-001');
        $this->process('BCP-002', ['owner_id' => null]);

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->addWeek()]);

        $result = $campaigns->distribute($campaign);

        $this->assertSame(2, $result['created']);
        $this->assertCount(1, $result['without_owner']);
        $this->assertSame('BCP-002', $result['without_owner'][0]['code']);
    }

    #[Test]
    public function a_closed_campaign_freezes_its_response_rate(): void
    {
        $this->process('BCP-001');
        $this->process('BCP-002');

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->addWeek()]);
        $campaigns->distribute($campaign);

        $first = BiaAssessment::query()->firstOrFail();
        $this->completeAndSubmit($first);

        $closed = $campaigns->close($campaign);
        $this->assertSame(50.0, (float) $closed->response_rate);

        // Adding a process afterwards must not move a published figure.
        $this->process('BCP-003');
        $this->assertSame(50.0, (float) $closed->refresh()->response_rate);
    }

    #[Test]
    public function a_response_rate_over_no_assessments_is_null_rather_than_zero(): void
    {
        $campaign = app(BiaCampaignService::class)->create(['name' => 'Empty', 'closes_at' => now()->addWeek()]);

        $this->assertNull(app(BiaCampaignService::class)->progress($campaign)['response_rate']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 2 — validation that bites */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_rto_longer_than_the_mtpd_blocks_submission(): void
    {
        $assessment = $this->assessment($this->process('BCP-001'));

        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 4, 'rto_hours' => 8]);

        $result = app(BiaValidator::class)->check($assessment->refresh());

        $this->assertCount(1, $result['blocking']);
        $this->assertStringContainsString('longer than the maximum tolerable period', $result['blocking'][0]['message']);

        $this->expectException(InvalidArgumentException::class);
        app(BiaAssessmentService::class)->submit($assessment->refresh());
    }

    #[Test]
    public function an_open_banking_process_over_thirty_minutes_warns_and_cites_the_cbn_but_does_not_block(): void
    {
        // WARNS RATHER THAN BLOCKS, deliberately. Whether the Open Banking
        // guidelines bind this institution depends on its licence, and a system
        // that refused to record a bank's actual RTO would stop holding the truth.
        $process = $this->process('BCP-API', ['regulatory_flags' => ['open_banking']]);
        $assessment = $this->assessment($process);

        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 8, 'rto_hours' => 2]);

        $result = app(BiaValidator::class)->check($assessment->refresh());

        $this->assertSame([], $result['blocking']);

        $warning = collect($result['warnings'])->firstWhere('clause_ref', 'cbn.open_banking.threshold');
        $this->assertNotNull($warning, 'An open-banking process with a two-hour RTO did not raise the CBN threshold warning.');
        $this->assertStringContainsString('30 minutes', $warning['message']);
        $this->assertStringContainsString('CBN Operational Guidelines', $warning['citation']);

        // And it can still be submitted, because it is the truth.
        app(BiaAssessmentService::class)->submit($assessment->refresh());
        $this->assertSame(BiaAssessmentStatus::Submitted, $assessment->refresh()->status);
    }

    #[Test]
    public function an_open_banking_process_inside_thirty_minutes_raises_nothing(): void
    {
        $process = $this->process('BCP-API', ['regulatory_flags' => ['open_banking']]);
        $assessment = $this->assessment($process);

        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 8, 'rto_hours' => 0.5]);

        $warnings = collect(app(BiaValidator::class)->check($assessment->refresh())['warnings']);

        $this->assertNull($warnings->firstWhere('clause_ref', 'cbn.open_banking.threshold'));
    }

    #[Test]
    public function a_critical_service_above_the_tenants_configured_ceiling_is_blocked(): void
    {
        app(BcmsSettings::class)->update(['critical_service_rto_ceiling_hours' => 4]);
        app(BcmsSettings::class)->forget();

        $process = $this->process('BCP-PAY', [
            'is_critical_service' => true,
            'critical_service_justification' => 'Part of the national payments system.',
        ]);
        $assessment = $this->assessment($process);

        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 8]);

        $blocking = app(BiaValidator::class)->check($assessment->refresh())['blocking'];

        $this->assertCount(1, $blocking);
        $this->assertStringContainsString('ceiling', $blocking[0]['message']);

        // Inside the ceiling it passes.
        app(BiaAssessmentService::class)->save($assessment->refresh(), ['rto_hours' => 3]);
        $this->assertSame([], app(BiaValidator::class)->check($assessment->refresh())['blocking']);
    }

    #[Test]
    public function a_child_process_cannot_recover_more_slowly_than_its_approved_parent(): void
    {
        $parent = $this->process('BCP-PARENT');
        $child = $this->process('BCP-CHILD', ['parent_process_id' => $parent->id]);

        $parentAssessment = $this->assessment($parent);
        app(BiaAssessmentService::class)->save($parentAssessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        $this->approve($parentAssessment);

        $childAssessment = $this->assessment($child);
        app(BiaAssessmentService::class)->save($childAssessment, ['mtpd_hours' => 24, 'rto_hours' => 8]);

        $blocking = app(BiaValidator::class)->check($childAssessment->refresh())['blocking'];

        $this->assertCount(1, $blocking);
        $this->assertStringContainsString('one of the two numbers is wrong', $blocking[0]['message']);
    }

    #[Test]
    public function a_parent_whose_own_bia_is_only_a_draft_does_not_block_its_child(): void
    {
        // Blocking a child on a number that may change tomorrow would stall a
        // campaign for a reason the assessor cannot fix.
        $parent = $this->process('BCP-PARENT');
        $child = $this->process('BCP-CHILD', ['parent_process_id' => $parent->id]);

        $draft = $this->assessment($parent);
        app(BiaAssessmentService::class)->save($draft, ['mtpd_hours' => 24, 'rto_hours' => 4]);

        $childAssessment = $this->assessment($child);
        app(BiaAssessmentService::class)->save($childAssessment, ['mtpd_hours' => 24, 'rto_hours' => 8]);

        $this->assertSame([], app(BiaValidator::class)->check($childAssessment->refresh())['blocking']);
    }

    /* ------------------------------------------------------------------ */
    /*  The impact grid and the derived MTPD */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_mtpd_is_derived_from_the_first_horizon_any_category_becomes_intolerable(): void
    {
        $assessment = $this->assessment($this->process('BCP-001'));
        $service = app(BiaAssessmentService::class);

        // Reputationally survivable for a week, regulatorily intolerable at 4h.
        // ANY category, not all — averaging would hide the one that matters.
        $service->scoreImpact($assessment, ImpactCategory::Reputational, ImpactHorizon::H1, 1);
        $service->scoreImpact($assessment, ImpactCategory::Reputational, ImpactHorizon::W1, 2);
        $service->scoreImpact($assessment, ImpactCategory::Regulatory, ImpactHorizon::H1, 2);
        $service->scoreImpact($assessment, ImpactCategory::Regulatory, ImpactHorizon::H4, 4);

        $derived = app(MtpdDeriver::class)->derive($assessment->refresh());

        $this->assertSame(4.0, $derived['hours']);
        $this->assertSame('4h', $derived['horizon']);
        $this->assertSame(['regulatory'], $derived['categories']);
        $this->assertStringContainsString('regulatory', $derived['rationale']);

        // Proposed, never imposed: `mtpd_hours` is still the assessor's to set.
        $this->assertSame('4.00', (string) $assessment->refresh()->derived_mtpd_hours);
        $this->assertNull($assessment->refresh()->mtpd_hours);
    }

    #[Test]
    public function a_grid_that_never_crosses_the_threshold_proposes_nothing(): void
    {
        // Null, not the longest horizon: "tolerable for two weeks" means the
        // grid does not answer the question, not that the MTPD is two weeks.
        $assessment = $this->assessment($this->process('BCP-001'));

        app(BiaAssessmentService::class)->scoreImpact($assessment, ImpactCategory::Customer, ImpactHorizon::W2, 2);

        $derived = app(MtpdDeriver::class)->derive($assessment->refresh());

        $this->assertNull($derived['hours']);
        $this->assertStringContainsString('does not answer the question', $derived['rationale']);
    }

    #[Test]
    public function the_threshold_is_the_tenants_and_moving_it_moves_the_proposal(): void
    {
        $assessment = $this->assessment($this->process('BCP-001'));
        $service = app(BiaAssessmentService::class);

        $service->scoreImpact($assessment, ImpactCategory::Customer, ImpactHorizon::H8, 3);
        $service->scoreImpact($assessment, ImpactCategory::Customer, ImpactHorizon::H24, 4);

        $this->assertSame(24.0, app(MtpdDeriver::class)->derive($assessment->refresh())['hours']);

        // One bank's 4-out-of-5 is another's 3.
        app(BcmsSettings::class)->update(['impact_intolerable_score' => 3]);
        app(BcmsSettings::class)->forget();

        $this->assertSame(8.0, app(MtpdDeriver::class)->derive($assessment->refresh())['hours']);
    }

    #[Test]
    public function a_financial_amount_on_a_non_monetary_category_is_refused(): void
    {
        // Reputational damage has no naira figure, and a grid that accepts one
        // produces a total somebody will later put in a board pack.
        $assessment = $this->assessment($this->process('BCP-001'));

        $this->expectException(InvalidArgumentException::class);

        app(BiaAssessmentService::class)->scoreImpact(
            $assessment, ImpactCategory::Reputational, ImpactHorizon::H24, 4, 500_000_00
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 3 — one application register, and the morph resolves */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_application_register_is_not_duplicated(): void
    {
        // The prompt asks for "no duplicated application row anywhere in
        // bcms_*". This product has no EA module, so `bcms_applications` IS the
        // register (ADR 0001) — what matters is that there is exactly one of it.
        // Scoped to this database. Unscoped, `getTableListing()` returns every
        // schema on the server, and this assertion saw six copies of
        // `bcms_applications` on a machine that hosts several BCMS databases.
        $applicationTables = array_values(array_filter(
            Schema::getTableListing(DB::connection()->getDatabaseName(), false),
            fn (string $t) => str_starts_with($t, 'bcms_') && str_contains($t, 'application')
        ));

        $this->assertSame(['bcms_applications'], $applicationTables);

        $application = Application::query()->create(['code' => 'APP-CORE', 'name' => 'Core Banking Platform']);
        $assessment = $this->assessment($this->process('BCP-001'));

        app(DependencyService::class)->attach($assessment, $application, ['criticality' => 'critical']);

        $dependency = Dependency::query()->firstOrFail();

        // The short alias, resolving to the live row — not a copied name.
        $this->assertSame(DependencyType::Applications->value, $dependency->dependable_type);
        $this->assertTrue($dependency->dependable->is($application));

        $application->update(['name' => 'Core Banking Platform (Flexcube)']);
        $this->assertSame('Core Banking Platform (Flexcube)', $dependency->refresh()->dependableLabel());
    }

    #[Test]
    public function every_dependency_type_can_be_attached_to_an_assessment(): void
    {
        $assessment = $this->assessment($this->process('BCP-001'));
        $service = app(DependencyService::class);

        $targets = [
            Application::query()->create(['code' => 'APP-1', 'name' => 'An application']),
            Site::query()->create(['code' => 'SITE-1', 'name' => 'A site']),
            \App\Models\Bcms\Equipment::query()->create(['code' => 'EQ-1', 'name' => 'A generator']),
            \App\Models\Bcms\DataSet::query()->create(['code' => 'DS-1', 'name' => 'A data set']),
            $this->process('BCP-OTHER'),
            $this->assessor,
            \App\Models\Tprm\ThirdParty::create([
                'legal_name' => 'Interlink Systems Limited', 'slug' => 'interlink-'.uniqid(),
                'entity_type' => 'company', 'country_of_incorporation' => 'NG',
            ]),
        ];

        foreach ($targets as $target) {
            $service->attach($assessment, $target, ['criticality' => 'medium']);
        }

        $this->assertSame(7, Dependency::query()->count());
        $this->assertCount(7, Dependency::query()->get()->pluck('dependable_type')->unique());
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 4 — the reverse view */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function failing_one_application_lists_every_dependent_process_with_its_aggregate_exposure(): void
    {
        $core = Application::query()->create(['code' => 'APP-CORE', 'name' => 'Core Banking Platform']);
        $service = app(DependencyService::class);
        $assessments = app(BiaAssessmentService::class);

        $profiles = [
            ['BCP-PY', 1, 0.5],
            ['BCP-CASH', 2, 4.0],
            ['BCP-LN', 3, 24.0],
        ];

        foreach ($profiles as [$code, $tier, $rto]) {
            $process = $this->process($code, ['criticality_tier' => $tier]);
            $assessment = $this->assessment($process);
            $assessments->save($assessment, ['mtpd_hours' => 72, 'rto_hours' => $rto]);
            $service->attach($assessment->refresh(), $core, ['criticality' => 'critical', 'dependency_type' => 'upstream']);
            $this->approve($assessment->refresh(), $tier);
        }

        // One that only degrades — counted as affected, not as halting.
        $degrades = $this->process('BCP-REPORT', ['criticality_tier' => 4]);
        $degradesAssessment = $this->assessment($degrades);
        $assessments->save($degradesAssessment, ['mtpd_hours' => 168, 'rto_hours' => 72]);
        $service->attach($degradesAssessment->refresh(), $core, ['criticality' => 'low', 'dependency_type' => 'supporting']);
        $this->approve($degradesAssessment->refresh(), 4);

        $impact = $service->impactOf($core);

        $this->assertSame(4, $impact['process_count']);
        $this->assertSame(3, $impact['halting_count']);
        $this->assertSame(1, $impact['tier1_count']);

        // THE SHORTEST RTO among the processes that STOP — the time before the
        // first commitment is breached. Not the sum, not the longest, and not
        // dragged out by the one that merely degrades.
        $this->assertSame(0.5, $impact['aggregate_rto_hours']);

        // Most critical first.
        $this->assertSame('BCP-PY', $impact['processes'][0]['code']);
    }

    #[Test]
    public function a_dependent_process_with_no_approved_bia_contributes_no_recovery_time(): void
    {
        // Null, not zero: a process with no approved BIA has no stated RTO, and
        // zero would claim instant recovery.
        $core = Application::query()->create(['code' => 'APP-CORE', 'name' => 'Core']);
        $assessment = $this->assessment($this->process('BCP-001'));

        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 2]);
        app(DependencyService::class)->attach($assessment->refresh(), $core, ['criticality' => 'critical']);

        $impact = app(DependencyService::class)->impactOf($core);

        $this->assertSame(1, $impact['process_count']);
        $this->assertNull($impact['processes'][0]['rto_hours']);
        $this->assertNull($impact['aggregate_rto_hours']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 6 — the SPOF register */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_spof_register_lists_each_one_with_its_downstream_process_count(): void
    {
        $network = Application::query()->create(['code' => 'APP-NET', 'name' => 'Network Management']);
        $switch = Application::query()->create(['code' => 'APP-SWITCH', 'name' => 'Payments Switch']);
        $service = app(DependencyService::class);

        foreach (['BCP-PY' => 1, 'BCP-CHAN' => 1, 'BCP-CASH' => 2] as $code => $tier) {
            $assessment = $this->assessment($this->process($code, ['criticality_tier' => $tier]));
            $service->attach($assessment, $network, ['criticality' => 'critical', 'single_point_of_failure' => true]);
        }

        $one = $this->assessment($this->process('BCP-CARD', ['criticality_tier' => 2]));
        $service->attach($one, $switch, ['criticality' => 'high', 'single_point_of_failure' => true]);

        $register = app(DependencyService::class)->spofRegister();

        $this->assertCount(2, $register);

        // Ordered by consequence: critical and three processes beats high and one.
        $this->assertSame('Network Management', $register[0]['name']);
        $this->assertSame(3, $register[0]['process_count']);
        $this->assertSame(2, $register[0]['tier1_count']);
        $this->assertTrue($register[0]['is_finding']);

        $this->assertSame('Payments Switch', $register[1]['name']);
        $this->assertSame(1, $register[1]['process_count']);
    }

    #[Test]
    public function a_shared_dependency_shows_the_concentration_nobody_chose(): void
    {
        $core = Application::query()->create(['code' => 'APP-CORE', 'name' => 'Core Banking Platform']);
        $only = Application::query()->create(['code' => 'APP-HR', 'name' => 'HR and Payroll']);
        $service = app(DependencyService::class);

        foreach (['BCP-PY' => 1, 'BCP-CASH' => 2, 'BCP-LN' => 3] as $code => $tier) {
            $assessment = $this->assessment($this->process($code, ['criticality_tier' => $tier]));
            $service->attach($assessment, $core, ['criticality' => 'critical']);
        }

        $service->attach($this->assessment($this->process('BCP-HR')), $only, ['criticality' => 'low']);

        $shared = $service->sharedDependencies();

        $this->assertCount(1, $shared, 'Only the dependency more than one process relies on is a concentration.');
        $this->assertSame('Core Banking Platform', $shared[0]['name']);
        $this->assertSame(3, $shared[0]['process_count']);
        $this->assertSame(1, $shared[0]['tier1_count']);
    }

    #[Test]
    public function a_process_dependency_cycle_is_refused(): void
    {
        // A→B→A means neither can be recovered first, which is not a plan.
        $a = $this->process('BCP-A');
        $b = $this->process('BCP-B');

        $service = app(DependencyService::class);

        $service->attach($this->assessment($a), $b, ['criticality' => 'high']);

        $this->expectException(InvalidArgumentException::class);
        $service->attach($this->assessment($b), $a, ['criticality' => 'high']);
    }

    #[Test]
    public function a_process_cannot_depend_on_itself(): void
    {
        $process = $this->process('BCP-001');

        $this->expectException(InvalidArgumentException::class);

        app(DependencyService::class)->attach($this->assessment($process), $process);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 5 — AI drafting is a draft */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function ai_drafting_is_unavailable_by_default_and_says_why(): void
    {
        // A fresh install has no model and every capability off, so every
        // workflow completes manually. That is a requirement, not a limitation.
        $drafter = app(\App\Services\Bcms\Bia\BiaAiDrafter::class);
        $assessment = $this->assessment($this->process('BCP-001'));

        $this->assertFalse($drafter->available($assessment));
        $this->assertNotNull($drafter->unavailableReason($assessment));

        $result = $drafter->draft($assessment);
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['filled']);
    }

    #[Test]
    public function the_ai_kill_switch_is_checked_in_one_place(): void
    {
        $client = app(\App\Services\Bcms\Ai\BcmsLlmClient::class);
        $organizationId = $this->organization->id;

        config()->set('services.llm.enabled', true);
        config()->set('bcms.ai.capabilities.bia_draft', true);

        // The tenant switch alone is enough to stop it.
        $this->assertFalse($client->enabled(\App\Services\Bcms\Ai\BcmsLlmClient::BIA_DRAFT, $organizationId));
        $this->assertStringContainsString('switched off for this organisation', $client->unavailableReason(\App\Services\Bcms\Ai\BcmsLlmClient::BIA_DRAFT, $organizationId));

        app(BcmsSettings::class)->update(['ai_enabled' => true]);
        app(BcmsSettings::class)->forget();

        $this->assertTrue($client->enabled(\App\Services\Bcms\Ai\BcmsLlmClient::BIA_DRAFT, $organizationId));

        // And the per-capability switch alone is enough too.
        config()->set('bcms.ai.capabilities.bia_draft', false);
        $this->assertFalse($client->enabled(\App\Services\Bcms\Ai\BcmsLlmClient::BIA_DRAFT, $organizationId));
    }

    #[Test]
    public function an_assessment_can_never_be_approved_by_its_own_assessor(): void
    {
        // Standing rule 4's "nothing is approved without a human action" and the
        // separation of duties are the same assertion here: `approved_by` is the
        // record that a second person existed.
        $assessment = $this->assessment($this->process('BCP-001'));
        $this->completeAndSubmit($assessment);

        $this->expectException(InvalidArgumentException::class);
        app(BiaAssessmentService::class)->approve($assessment->refresh(), $this->assessor->id);
    }

    #[Test]
    public function approving_fixes_the_numbers_and_writes_the_processs_criticality_tier(): void
    {
        $process = $this->process('BCP-001');
        $this->assertNull($process->criticality_tier);

        $assessment = $this->assessment($process);
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 2]);
        app(BiaAssessmentService::class)->submit($assessment->refresh());
        app(BiaAssessmentService::class)->approve($assessment->refresh(), $this->approver->id);

        // An RTO of two hours is a tier-1 process.
        $this->assertSame(1, $process->refresh()->criticality_tier);

        // And the assessment is now immutable.
        $this->expectException(InvalidArgumentException::class);
        app(BiaAssessmentService::class)->save($assessment->refresh(), ['rto_hours' => 8]);
    }

    #[Test]
    public function a_critical_service_is_floored_at_tier_two_however_long_its_rto(): void
    {
        // A regulatory designation is not something an RTO can argue away.
        $process = $this->process('BCP-SLOW', [
            'is_critical_service' => true,
            'critical_service_justification' => 'A designated critical service.',
        ]);

        $assessment = $this->assessment($process);
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 336, 'rto_hours' => 100]);
        app(BiaAssessmentService::class)->submit($assessment->refresh());
        app(BiaAssessmentService::class)->approve($assessment->refresh(), $this->approver->id);

        $this->assertSame(2, $process->refresh()->criticality_tier);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 7 — chasing and escalation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_non_responding_owner_is_chased_and_then_escalated_to_their_manager(): void
    {
        $manager = $this->user('manager@khb.test');
        $this->unit->update(['head_id' => $manager->id]);

        $this->process('BCP-001');

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->subDay()]);
        $campaigns->distribute($campaign);

        // Past the deadline but inside the grace period: chased, not escalated.
        $first = $campaigns->chase($campaign);
        $this->assertSame(1, $first['chased']);
        $this->assertSame(0, $first['escalated']);

        $assessment = BiaAssessment::query()->firstOrFail();
        $this->assertSame(1, (int) $assessment->chase_count);
        $this->assertNull($assessment->escalated_at);

        // Chasing again inside the interval does nothing — forty people taught
        // to filter a daily reminder will not read the escalation either.
        $this->assertSame(0, $campaigns->chase($campaign)['chased']);

        // Past the grace period, and with the interval elapsed.
        $campaign->update(['closes_at' => now()->subDays(BiaCampaignService::ESCALATION_GRACE_DAYS + 1)]);
        $assessment->forceFill(['chased_at' => now()->subDays(BiaCampaignService::CHASE_INTERVAL_DAYS + 1)])->save();

        $second = $campaigns->chase($campaign->refresh());

        $this->assertSame(1, $second['escalated']);
        $this->assertSame($manager->id, (int) $assessment->refresh()->escalated_to_user_id);
        $this->assertNotNull($assessment->refresh()->escalated_at);
        $this->assertSame(2, (int) $assessment->refresh()->chase_count);
    }

    #[Test]
    public function escalation_happens_once_and_an_owner_with_no_manager_is_reported(): void
    {
        $this->process('BCP-001');

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create([
            'name' => 'Annual BIA',
            'closes_at' => now()->subDays(BiaCampaignService::ESCALATION_GRACE_DAYS + 1),
        ]);
        $campaigns->distribute($campaign);

        // No manager on the roster and no unit head: reported, not swallowed.
        $result = $campaigns->chase($campaign);
        $this->assertSame(0, $result['escalated']);
        $this->assertSame(['BCP-001'], $result['no_manager']);

        // Now with a manager on the contact roster.
        $manager = $this->user('manager@khb.test');
        DB::table('bcms_contacts')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'user_id' => $this->assessor->id,
            'manager_user_id' => $manager->id,
            'source' => 'manual',
            'full_name' => $this->assessor->name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        BiaAssessment::query()->firstOrFail()
            ->forceFill(['chased_at' => now()->subDays(BiaCampaignService::CHASE_INTERVAL_DAYS + 1)])->save();

        $this->assertSame(1, $campaigns->chase($campaign)['escalated']);

        // Once. A second sweep does not escalate again.
        BiaAssessment::query()->firstOrFail()
            ->forceFill(['chased_at' => now()->subDays(BiaCampaignService::CHASE_INTERVAL_DAYS + 1)])->save();

        $this->assertSame(0, $campaigns->chase($campaign)['escalated']);
    }

    #[Test]
    public function the_chase_command_runs_across_open_campaigns(): void
    {
        $this->unit->update(['head_id' => $this->approver->id]);
        $this->process('BCP-001');

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->subDay()]);
        $campaigns->distribute($campaign);

        $this->artisan('bcms:chase-bia')->assertExitCode(0);

        $this->assertSame(1, (int) BiaAssessment::query()->firstOrFail()->chase_count);
    }

    #[Test]
    public function the_chase_command_does_nothing_when_the_module_is_off(): void
    {
        config()->set('features.bcms', false);

        $this->process('BCP-001');
        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->subDay()]);
        $campaigns->distribute($campaign);

        $this->artisan('bcms:chase-bia')->expectsOutputToContain('switched off')->assertExitCode(0);

        $this->assertSame(0, (int) BiaAssessment::query()->firstOrFail()->chase_count);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 1 retrospective — Set B */
    /* ------------------------------------------------------------------ */

    /**
     * Set B defect 1: `MtpdDeriver` used to treat a horizon as fully assessed
     * the moment ANY category at it breached the threshold, even when another
     * category at that SAME horizon (or an earlier one) had never been
     * scored. The exact reproducer from the retrospective: threshold 4;
     * `financial` scored 1 at 1h, 2 at 4h, 3 at 24h; `regulatory` scored 5 at
     * 24h but left BLANK at 1h and 4h.
     *
     * The old code proposed `hours = 24.0` with the rationale "the first
     * horizon at which any category crosses it" — false, because regulatory
     * at 4h (and 1h) was simply never assessed, not tolerable. Whether it had
     * already crossed the threshold at 4h is unknown, so 24h cannot be
     * trusted as the FIRST breach.
     *
     * MUTATION: remove the `$gaps` check in `MtpdDeriver::derive()` (i.e.
     * propose the first breaching horizon without checking every earlier
     * horizon was scored for that category) and this test's `hours`
     * assertion fails: it comes back 24.0 instead of null.
     */
    #[Test]
    public function a_partially_scored_horizon_is_not_treated_as_a_trustworthy_breach(): void
    {
        $assessment = $this->assessment($this->process('BCP-001'));
        $service = app(BiaAssessmentService::class);

        $service->scoreImpact($assessment, ImpactCategory::Financial, ImpactHorizon::H1, 1);
        $service->scoreImpact($assessment, ImpactCategory::Financial, ImpactHorizon::H4, 2);
        $service->scoreImpact($assessment, ImpactCategory::Financial, ImpactHorizon::H24, 3);
        // Regulatory is scored ONLY at 24h — 1h and 4h are left blank.
        $service->scoreImpact($assessment, ImpactCategory::Regulatory, ImpactHorizon::H24, 5);

        $derived = app(MtpdDeriver::class)->derive($assessment->refresh());

        $this->assertNull($derived['hours'], 'A gap in an earlier horizon was walked past instead of refusing the proposal.');
        $this->assertNull($derived['horizon']);
        $this->assertStringContainsString('regulatory', $derived['rationale']);
        $this->assertStringContainsString('not scored', $derived['rationale']);

        // apply() persists the refusal too — a later reader of the column
        // sees no proposal rather than the false 24h one.
        app(MtpdDeriver::class)->apply($assessment->refresh());
        $this->assertNull($assessment->refresh()->derived_mtpd_hours);
    }

    /**
     * FIXED (Gate 1 retrospective, defect 6): `BiaController::acceptDerivedMtpd()`
     * used to flash a hardcoded "nothing to accept" string whenever
     * `derived_mtpd_hours` was null, which is true of BOTH a grid that was
     * fully scored and never crossed the threshold AND a grid refused for a
     * scoring gap (Set B defect 1, above) — two completely different states
     * for an assessor to be in. It now re-derives live and flashes
     * `MtpdDeriver::derive()`'s own rationale, which is worded differently
     * for each. "We checked and it is fine" must be distinguishable from
     * "nobody looked" — a message that merely exists proves nothing.
     *
     * MUTATION: replace the `$this->deriver->derive($assessment)['rationale']`
     * call in `BiaController::acceptDerivedMtpd()` with a fixed string (the
     * pre-fix behaviour) and this fails on the second assertion — both flashed
     * messages become identical.
     */
    #[Test]
    public function the_accept_mtpd_route_flashes_a_message_that_tells_the_two_null_cases_apart(): void
    {
        $manager = User::create([
            'organization_id' => $this->organization->id, 'name' => 'BIA Manager',
            'email' => 'bia-manager@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('bia-manager', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bcms.bia.complete', 'web'));
        $manager->assignRole($role);

        // Case A: fully scored, nothing crosses the threshold — "we checked
        // and it is fine".
        $toleratedAssessment = $this->assessment($this->process('BCP-MTPD-TOLERATED'));
        app(BiaAssessmentService::class)->scoreImpact(
            $toleratedAssessment, ImpactCategory::Customer, ImpactHorizon::W2, 2
        );
        $this->assertNull($toleratedAssessment->refresh()->derived_mtpd_hours, 'Precondition: nothing derived yet.');

        $toleratedResponse = $this->actingAs($manager)
            ->post(route('bcms.bia.accept-mtpd', $toleratedAssessment));
        $toleratedResponse->assertSessionHas('error');
        $toleratedMessage = session('error');

        // Case B: refused for a scoring gap (the exact Set B defect 1
        // reproducer) — "nobody looked", not "checked and fine".
        $gapAssessment = $this->assessment($this->process('BCP-MTPD-GAP'));
        $gapService = app(BiaAssessmentService::class);
        $gapService->scoreImpact($gapAssessment, ImpactCategory::Financial, ImpactHorizon::H1, 1);
        $gapService->scoreImpact($gapAssessment, ImpactCategory::Financial, ImpactHorizon::H4, 2);
        $gapService->scoreImpact($gapAssessment, ImpactCategory::Financial, ImpactHorizon::H24, 3);
        $gapService->scoreImpact($gapAssessment, ImpactCategory::Regulatory, ImpactHorizon::H24, 5);
        $this->assertNull($gapAssessment->refresh()->derived_mtpd_hours, 'Precondition: refused, not derived.');

        $gapResponse = $this->actingAs($manager)
            ->post(route('bcms.bia.accept-mtpd', $gapAssessment));
        $gapResponse->assertSessionHas('error');
        $gapMessage = session('error');

        // Both leave derived_mtpd_hours null, and BOTH routes flash an
        // 'error' — the two situations must not be told apart, if at all,
        // by anything other than the message text.
        $this->assertNotSame(
            $toleratedMessage,
            $gapMessage,
            'A grid that was fully scored and tolerable flashed the same message as one refused for a scoring gap.'
        );
        $this->assertStringContainsString('does not answer the question', $toleratedMessage);
        $this->assertStringContainsString('not scored', $gapMessage);
        $this->assertStringContainsString('regulatory', $gapMessage);

        // Neither call actually recorded an mtpd_hours answer — a message was
        // flashed instead of an acceptance going through.
        $this->assertNull($toleratedAssessment->refresh()->mtpd_hours);
        $this->assertNull($gapAssessment->refresh()->mtpd_hours);
    }

    /**
     * Set B defect 2, the direction the existing coverage did not have: a
     * CHILD approved first at a longer RTO, then its PARENT approved at a
     * shorter one. `parentBreaches()` (checked on the child) only fires when
     * the parent is already approved; here the parent is the one being
     * checked, which is `childBreaches()`.
     *
     * MUTATION: delete the `childBreaches()` call from `BiaValidator::check()`
     * and this blocks nothing — the register would say the parent resumes in
     * 4 hours while a step inside it is still down for 8, silently.
     */
    #[Test]
    public function a_parent_approved_after_its_child_cannot_claim_a_shorter_rto(): void
    {
        $parent = $this->process('BCP-PARENT2');
        $child = $this->process('BCP-CHILD2', ['parent_process_id' => $parent->id]);

        $childAssessment = $this->assessment($child);
        app(BiaAssessmentService::class)->save($childAssessment, ['mtpd_hours' => 24, 'rto_hours' => 8]);
        $this->approve($childAssessment);

        $parentAssessment = $this->assessment($parent);
        app(BiaAssessmentService::class)->save($parentAssessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);

        $blocking = app(BiaValidator::class)->check($parentAssessment->refresh())['blocking'];

        $this->assertCount(1, $blocking);
        $this->assertStringContainsString('one of the two numbers is wrong', $blocking[0]['message']);

        $this->expectException(InvalidArgumentException::class);
        app(BiaAssessmentService::class)->submit($parentAssessment->refresh());
    }

    /**
     * Set B defect 3: the reverse-impact headline must carry its coverage
     * denominator, and the screen must caveat rather than present a bare
     * number when any halting process is unassessed. This is the service-side
     * half — `assessed_count`/`unassessed_count` — the JSX caveat itself is
     * verified by reading `resources/js/Pages/Bcms/Bia/ReverseImpact.jsx`.
     *
     * MUTATION: change `'assessed_count' => count($rtos)` to
     * `count($halting)` (i.e. claim everything was assessed) and this test's
     * `unassessed_count` assertion fails — it would report 0 instead of 2.
     */
    #[Test]
    public function the_reverse_impact_headline_carries_its_coverage_denominator(): void
    {
        $core = Application::query()->create(['code' => 'APP-PARTIAL', 'name' => 'Partially Assessed Platform']);
        $service = app(DependencyService::class);
        $assessments = app(BiaAssessmentService::class);

        // One halting process WITH an approved BIA...
        $assessed = $this->process('BCP-ASSESSED', ['criticality_tier' => 2]);
        $assessedAssessment = $this->assessment($assessed);
        $assessments->save($assessedAssessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        $service->attach($assessedAssessment->refresh(), $core, ['criticality' => 'critical', 'dependency_type' => 'upstream']);
        $this->approve($assessedAssessment->refresh(), 2);

        // ...and two halting processes with NO approved BIA at all.
        foreach (['BCP-GAP-1', 'BCP-GAP-2'] as $code) {
            $gap = $this->process($code, ['criticality_tier' => 3]);
            $gapAssessment = $this->assessment($gap);
            $service->attach($gapAssessment->refresh(), $core, ['criticality' => 'medium', 'dependency_type' => 'upstream']);
        }

        $impact = $service->impactOf($core);

        $this->assertSame(3, $impact['halting_count']);
        $this->assertSame(1, $impact['assessed_count']);
        $this->assertSame(2, $impact['unassessed_count']);
        $this->assertSame(4.0, $impact['aggregate_rto_hours']);

        // No percentage anywhere in the payload — a ratio over a partly
        // assessed register reads as a confidence it is not.
        $this->assertArrayNotHasKey('coverage_percentage', $impact);
        $this->assertArrayNotHasKey('assessed_percentage', $impact);
    }

    /**
     * Set B defect 4: `close()` had no already-closed guard, so a "frozen"
     * clause 8.2.2 response rate could be silently rewritten. Fixed to throw,
     * consistently with `distribute()`'s own already-closed guard.
     *
     * MUTATION: remove the `if ($campaign->status === 'closed')` guard from
     * `BiaCampaignService::close()` and the second call below succeeds and
     * rewrites `response_rate` instead of throwing — silently, since nothing
     * else in the campaign changed to explain a new figure.
     */
    #[Test]
    public function closing_an_already_closed_campaign_is_refused_consistently_with_distribute(): void
    {
        $this->process('BCP-CLOSE-1');
        $this->process('BCP-CLOSE-2');

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->addWeek()]);
        $campaigns->distribute($campaign);

        $first = BiaAssessment::query()->firstOrFail();
        $this->completeAndSubmit($first);

        $closed = $campaigns->close($campaign);
        $this->assertSame(50.0, (float) $closed->response_rate);

        // A second process answers AFTER close — if the guard did not exist,
        // re-closing now would move the frozen figure to 100%.
        $second = BiaAssessment::query()->where('id', '!=', $first->id)->firstOrFail();
        $this->completeAndSubmit($second);

        try {
            $campaigns->close($campaign->refresh());
            $this->fail('An already-closed campaign was closed again instead of being refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already closed', $e->getMessage());
        }

        $this->assertSame(
            50.0,
            (float) $campaign->refresh()->response_rate,
            'The frozen response rate moved after the campaign was already closed.'
        );

        // distribute() and close() must behave the SAME way when the
        // campaign they are given is already closed — both refuse.
        try {
            $campaigns->distribute($campaign->refresh());
            $this->fail('A closed campaign accepted a distribute() call.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('closed campaign', $e->getMessage());
        }
    }

    /**
     * FIXED (Gate 1 retrospective, defect 6): `BiaCampaignController::close()`
     * now catches the `InvalidArgumentException` `close()` throws on a second
     * close, exactly as `distribute()`'s controller action already did.
     * Re-closing a campaign from the UI now redirects with a flash `error`
     * carrying the service layer's "already closed" message, instead of
     * falling through to Laravel's default 500.
     *
     * MUTATION: remove the `try { ... } catch (\InvalidArgumentException $e)`
     * around `$this->campaigns->close(...)` in `BiaCampaignController::close()`
     * and this goes back to an uncaught 500 with no flash message at all.
     */
    #[Test]
    public function the_close_route_now_catches_the_already_closed_exception_consistently_with_distribute(): void
    {
        config()->set('app.debug', false);

        $this->process('BCP-ROUTE-CLOSE');

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Route Campaign', 'closes_at' => now()->addWeek()]);
        $campaigns->distribute($campaign);
        $campaigns->close($campaign);

        $manager = User::create([
            'organization_id' => $this->organization->id, 'name' => 'Campaign Manager',
            'email' => 'campaign-manager@khb.test', 'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('bia-campaign-manager', 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bcms.bia.campaign.manage', 'web'));
        $manager->assignRole($role);

        $response = $this->actingAs($manager)->post(route('bcms.bia-campaigns.close', $campaign));

        // A redirect with the service's own "already closed" message, not
        // a bare 500 — the same shape distribute() already had.
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('already closed', session('error'));
    }

    /**
     * Gate 1 retrospective: this class never created a second organisation
     * before this test, so the BIA engine — which carries an MTPD, an RTO and
     * a dependency graph, all of it board-level material — had no
     * cross-tenant assertion anywhere in Phase 2.
     */
    #[Test]
    public function a_bia_assessment_and_its_dependencies_do_not_resolve_across_a_tenant_boundary(): void
    {
        $other = Organization::create([
            'name' => 'A Different Bank', 'short_name' => 'ADB2',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($other->id);
        $theirUnit = BusinessUnit::create([
            'organization_id' => $other->id, 'code' => 'BU-OTHER', 'name' => 'Other Ops', 'is_active' => true,
        ]);
        $theirOwner = User::create([
            'name' => 'Their Owner', 'email' => 'their-owner@adb2.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $other->id, 'is_active' => true,
        ]);
        $theirProcess = Process::query()->create([
            'code' => 'BCP-OTHER', 'name' => 'Their Process', 'status' => 'active',
            'business_unit_id' => $theirUnit->id, 'owner_id' => $theirOwner->id,
            'organization_id' => $other->id,
        ]);
        $theirAssessment = app(BiaAssessmentService::class)->start($theirProcess, $theirOwner->id, null, []);
        $theirApp = Application::query()->create(['code' => 'APP-OTHER', 'name' => 'Their Platform']);
        app(DependencyService::class)->attach($theirAssessment->refresh(), $theirApp, ['criticality' => 'critical']);
        TenantContext::clear();

        TenantContext::set($this->organization->id);

        $this->assertNull(
            BiaAssessment::query()->find($theirAssessment->id),
            "Another tenant's BIA assessment leaked across the boundary."
        );
        $this->assertNull(Process::query()->find($theirProcess->id), "Another tenant's process leaked across the boundary.");
        $this->assertSame(0, BiaAssessment::query()->count());

        // The reverse-impact view specifically: it must not silently sum in
        // another tenant's dependency rows for an application row it can
        // still see (bcms_applications is per-tenant, so this also confirms
        // the application itself does not leak).
        $this->assertNull(Application::query()->find($theirApp->id));
    }

    /* ------------------------------------------------------------------ */
    /*  The 2026-09-13 route-key defect (BCMS twin of the TPRM one, 9e9f1de) */
    /* ------------------------------------------------------------------ */

    /**
     * `BiaAssessment` route-binds on its `uuid` (`HasBcmsUuid`), but
     * `Bia/Workspace.jsx` built every action button — Draft with AI, Submit,
     * Approve, "Use this as the MTPD" — from the row's numeric `assessment.id`,
     * and the impact-cell editor and the add-dependency form from an
     * `assessmentId` prop fed by that same `.id` — a 404 verified over HTTP.
     * `BiaWorkspacePresenter` now ships every one of these URLs itself.
     */
    #[Test]
    public function the_bia_workspace_ships_action_urls_bound_to_the_assessments_own_uuid(): void
    {
        $assessment = $this->assessment($this->process('BCP-URLS'));
        $viewer = $this->userWith(['bcms.bia.view'], 'bia-viewer@khb.test', 'bcms-bia-viewer');

        $this->actingAs($viewer)
            ->get(route('bcms.bia.show', $assessment))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($assessment) {
                $page->component('Bcms/Bia/Workspace');

                $row = $page->toArray()['props']['assessment'];

                $this->assertSame(route('bcms.bia.ai-draft', $assessment), $row['ai_draft_url']);
                $this->assertSame(route('bcms.bia.submit', $assessment), $row['submit_url']);
                $this->assertSame(route('bcms.bia.approve', $assessment), $row['approve_url']);
                $this->assertSame(route('bcms.bia.accept-mtpd', $assessment), $row['accept_mtpd_url']);
                $this->assertSame(route('bcms.bia.impacts.store', $assessment), $row['impacts_url']);
                $this->assertSame(route('bcms.bia.dependencies.store', $assessment), $row['dependencies_url']);

                // The defect this test exists to catch: the numeric id the
                // row also carries is NOT what any of these routes resolve
                // against.
                $this->assertNotSame((string) $assessment->getKey(), (string) $assessment->uuid);
                foreach (['ai_draft_url', 'submit_url', 'approve_url', 'accept_mtpd_url', 'impacts_url', 'dependencies_url'] as $key) {
                    $this->assertStringContainsString((string) $assessment->uuid, $row[$key]);
                    $this->assertStringNotContainsString('/'.$assessment->getKey().'/', $row[$key]);
                }
            });
    }

    #[Test]
    public function posting_to_the_props_submit_url_submits_the_assessment(): void
    {
        $assessment = $this->assessment($this->process('BCP-SUBMIT-URL'));
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        $completer = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'submit-url-user@khb.test', 'bcms-submit-url-user');

        $submitUrl = $this->actingAs($completer)
            ->get(route('bcms.bia.show', $assessment))
            ->viewData('page')['props']['assessment']['submit_url'];

        $this->actingAs($completer)
            ->post($submitUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(BiaAssessmentStatus::Submitted, $assessment->fresh()->status);

        $audit = \App\Models\Bcms\AuditLog::where('auditable_type', BiaAssessment::class)
            ->where('auditable_id', $assessment->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(BiaAssessmentStatus::Submitted->value, $audit->after['status'] ?? null);
    }

    #[Test]
    public function posting_to_the_props_impacts_url_scores_an_impact(): void
    {
        $assessment = $this->assessment($this->process('BCP-IMPACTS-URL'));
        $completer = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'impacts-url-user@khb.test', 'bcms-impacts-url-user');

        $impactsUrl = $this->actingAs($completer)
            ->get(route('bcms.bia.show', $assessment))
            ->viewData('page')['props']['assessment']['impacts_url'];

        $this->actingAs($completer)
            ->post($impactsUrl, [
                'impact_category' => ImpactCategory::Customer->value,
                'horizon' => ImpactHorizon::W2->value,
                'severity_score' => 3,
                'narrative' => 'Customers notice within two weeks.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $impact = $assessment->impacts()->where('impact_category', ImpactCategory::Customer->value)
            ->where('horizon', ImpactHorizon::W2->value)->first();
        $this->assertNotNull($impact);
        $this->assertSame(3, $impact->severity_score);

        // Documented gap, not an oversight of this test: `BiaImpact` carries
        // no `BcmsAuditable` (see app/Models/Bcms/BiaImpact.php), and a single
        // sub-threshold score does not move `BiaAssessment::derived_mtpd_hours`
        // either, so scoring an impact writes NO audit row anywhere today —
        // tracked follow-up: add BcmsAuditable to BiaImpact, Dependency,
        // ProgrammeObligation.
        $this->assertSame(
            0,
            \App\Models\Bcms\AuditLog::where('auditable_type', \App\Models\Bcms\BiaImpact::class)->count(),
            'BiaImpact has no BcmsAuditable — asserted explicitly so a future audit trail add updates this test.'
        );
    }

    #[Test]
    public function posting_to_the_props_dependencies_url_attaches_a_dependency(): void
    {
        $assessment = $this->assessment($this->process('BCP-DEPS-URL'));
        $application = Application::query()->create(['code' => 'APP-DEP-URL', 'name' => 'Core Banking']);
        $completer = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'deps-url-user@khb.test', 'bcms-deps-url-user');

        $dependenciesUrl = $this->actingAs($completer)
            ->get(route('bcms.bia.show', $assessment))
            ->viewData('page')['props']['assessment']['dependencies_url'];

        $this->actingAs($completer)
            ->post($dependenciesUrl, [
                'dependable_type' => DependencyType::Applications->value,
                'dependable_id' => $application->getKey(),
                'dependency_type' => 'upstream',
                'criticality' => 'critical',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            1,
            Dependency::query()->where('assessment_id', $assessment->getKey())
                ->where('dependable_type', DependencyType::Applications->value)
                ->where('dependable_id', $application->getKey())
                ->count()
        );

        // Documented gap, not an oversight of this test: `Dependency` carries
        // no `BcmsAuditable` (see app/Models/Bcms/Dependency.php), and
        // `DependencyService::attach()` touches nothing else, so attaching a
        // dependency writes NO audit row anywhere today — tracked follow-up:
        // add BcmsAuditable to BiaImpact, Dependency, ProgrammeObligation.
        $this->assertSame(
            0,
            \App\Models\Bcms\AuditLog::where('auditable_type', Dependency::class)->count(),
            'Dependency has no BcmsAuditable — asserted explicitly so a future audit trail add updates this test.'
        );
    }

    /**
     * `bia.approve` had no props-driven positive test before this cycle —
     * only the ships-the-urls assertion and the "cannot approve your own
     * assessment" service-level test existed.
     */
    #[Test]
    public function posting_to_the_props_approve_url_approves_the_assessment(): void
    {
        $assessment = $this->assessment($this->process('BCP-APPROVE-URL'));
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        app(BiaAssessmentService::class)->submit($assessment->refresh());
        $approver = $this->userWith(['bcms.bia.view', 'bcms.bia.approve'], 'approve-url-user@khb.test', 'bcms-approve-url-user');

        $approveUrl = $this->actingAs($approver)
            ->get(route('bcms.bia.show', $assessment))
            ->viewData('page')['props']['assessment']['approve_url'];

        $this->actingAs($approver)
            ->post($approveUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $assessment->refresh();
        $this->assertSame(BiaAssessmentStatus::Approved, $assessment->status);
        $this->assertSame($approver->id, (int) $assessment->approved_by);

        $audit = \App\Models\Bcms\AuditLog::where('auditable_type', BiaAssessment::class)
            ->where('auditable_id', $assessment->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(BiaAssessmentStatus::Approved->value, $audit->after['status'] ?? null);
    }

    /**
     * `accept-mtpd` had no props-driven positive test before this cycle —
     * `the_accept_mtpd_route_flashes_a_message_that_tells_the_two_null_cases_
     * apart` above exercises only the two REFUSAL paths, both via `route()`
     * directly.
     */
    #[Test]
    public function posting_to_the_props_accept_mtpd_url_records_the_derived_mtpd_as_the_answer(): void
    {
        $assessment = $this->assessment($this->process('BCP-ACCEPT-MTPD-URL'));
        $completer = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'accept-mtpd-url-user@khb.test', 'bcms-accept-mtpd-url-user');

        // Regulatory reaches the tenant's intolerable score (4) at 4h — the
        // same recipe as the_mtpd_is_derived_from_the_first_horizon test.
        app(BiaAssessmentService::class)->scoreImpact($assessment, ImpactCategory::Regulatory, ImpactHorizon::H4, 4);
        $assessment->refresh();
        $this->assertSame('4.00', (string) $assessment->derived_mtpd_hours, 'Precondition: the grid derived a proposal.');
        $this->assertNull($assessment->mtpd_hours, 'Precondition: nothing accepted yet.');

        $acceptMtpdUrl = $this->actingAs($completer)
            ->get(route('bcms.bia.show', $assessment))
            ->viewData('page')['props']['assessment']['accept_mtpd_url'];

        $this->actingAs($completer)
            ->post($acceptMtpdUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $assessment->refresh();
        $this->assertSame('4.00', (string) $assessment->mtpd_hours);

        $audit = \App\Models\Bcms\AuditLog::where('auditable_type', BiaAssessment::class)
            ->where('auditable_id', $assessment->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('4.00', (string) ($audit->after['mtpd_hours'] ?? null));
    }

    /**
     * `ai_draft_url` was asserted equal to `route()` but never posted to.
     * Faking the gateway's HTTP calls the way `BcmsLlmGatewayTest` does, so
     * this exercises the full controller → `BiaAiDrafter` → `BcmsLlmClient`
     * path rather than stubbing the drafter itself. `Http::preventStrayRequests()`
     * makes sure the fake above is the ONLY network path this test can take —
     * an unfaked endpoint fails loudly instead of this test silently talking
     * to a real host. The gateway's own ledger (ADR 0015 §9) is asserted too,
     * the same way `BcmsLlmGatewayTest::a_successful_call_returns_the_same_
     * shape_as_before` does: a draft that updates the assessment but leaves
     * no `LlmUsageEvent` row would be invisible to cost and usage reporting.
     */
    #[Test]
    public function posting_to_the_props_ai_draft_url_drafts_and_flags_it_as_ai_generated(): void
    {
        Http::preventStrayRequests();

        config()->set('services.llm.enabled', true);
        config()->set('bcms.ai.capabilities.bia_draft', true);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);
        app(BcmsSettings::class)->update(['ai_enabled' => true], $this->organization->id);

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'mtpd_hours' => 8, 'rto_hours' => 2, 'rpo_minutes' => 15,
                    'mbco' => 'Process cheque deposits only.',
                    'reasoning' => ['mtpd_hours' => 'Regulatory reporting fails after 8 hours.'],
                    'impacts' => [], 'challenge_questions' => ['Is 8 hours realistic given the manual workaround?'],
                ]),
                'prompt_eval_count' => 40, 'eval_count' => 20,
            ], 200),
        ]);

        $assessment = $this->assessment($this->process('BCP-AI-DRAFT-URL'));
        $completer = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'ai-draft-url-user@khb.test', 'bcms-ai-draft-url-user');

        $aiDraftUrl = $this->actingAs($completer)
            ->get(route('bcms.bia.show', $assessment))
            ->viewData('page')['props']['assessment']['ai_draft_url'];

        $this->actingAs($completer)
            ->post($aiDraftUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $assessment->refresh();
        $this->assertTrue((bool) $assessment->ai_generated);
        $this->assertNotNull($assessment->ai_drafted_at);
        $this->assertSame('8.00', (string) $assessment->mtpd_hours);

        $audit = \App\Models\Bcms\AuditLog::where('auditable_type', BiaAssessment::class)
            ->where('auditable_id', $assessment->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(true, $audit->after['ai_generated'] ?? null);

        // ADR 0015 §9 — BCMS's AI drafters run through the shared gateway,
        // which keeps its own usage ledger regardless of what the caller
        // does with the result.
        $usage = LlmUsageEvent::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($usage, 'A successful AI draft must leave a row in the gateway\'s usage ledger.');
        $this->assertSame('bcms', $usage->module);
        $this->assertSame('succeeded', $usage->outcome->value);
    }

    #[Test]
    public function a_user_of_another_tenant_posting_to_the_shipped_bia_workspace_urls_gets_404_and_changes_nothing(): void
    {
        $assessment = $this->assessment($this->process('BCP-CROSS-TENANT-URL'));
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        $statusBeforeForeignPosts = $assessment->refresh()->status;
        $viewer = $this->userWith(
            ['bcms.bia.view', 'bcms.bia.complete', 'bcms.bia.approve'],
            'cross-tenant-viewer@khb.test',
            'bcms-cross-tenant-viewer',
        );

        $row = $this->actingAs($viewer)
            ->get(route('bcms.bia.show', $assessment))
            ->viewData('page')['props']['assessment'];

        $foreignOrg = Organization::create([
            'name' => 'Another Bank PLC', 'short_name' => 'ABP2',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $foreignUser = User::create([
            'name' => 'Foreign Assessor', 'email' => 'assessor@abp2.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $foreignOrg->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('bcms-foreign-assessor', 'web');
        foreach (['bcms.bia.view', 'bcms.bia.complete', 'bcms.bia.approve'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $foreignUser->assignRole($role);

        $this->actingAs($foreignUser)->post($row['submit_url'])->assertNotFound();
        $this->actingAs($foreignUser)->post($row['approve_url'])->assertNotFound();
        $this->actingAs($foreignUser)->post($row['accept_mtpd_url'])->assertNotFound();
        $this->actingAs($foreignUser)->post($row['ai_draft_url'])->assertNotFound();
        $this->actingAs($foreignUser)->post($row['impacts_url'], [
            'impact_category' => ImpactCategory::Customer->value,
            'horizon' => ImpactHorizon::W2->value,
            'severity_score' => 3,
        ])->assertNotFound();
        $this->actingAs($foreignUser)->post($row['dependencies_url'], [
            'dependable_type' => DependencyType::Applications->value,
            'dependable_id' => 1,
            'dependency_type' => 'upstream',
            'criticality' => 'critical',
        ])->assertNotFound();

        $this->assertSame(
            $statusBeforeForeignPosts,
            $assessment->fresh()->status,
            'A cross-tenant post to any shipped BIA workspace URL must not change the assessment.'
        );
        $this->assertFalse((bool) $assessment->fresh()->ai_generated, 'A cross-tenant post to ai_draft_url must not draft the assessment.');
        $this->assertSame(0, $assessment->impacts()->count());
        $this->assertSame(0, Dependency::query()->where('assessment_id', $assessment->getKey())->count());
    }

    /**
     * `BiaCampaign` route-binds on its `uuid` (`HasBcmsUuid`), but
     * `Bia/Campaigns.jsx` built Distribute, Chase now and Close from the row's
     * numeric `campaign.id` — a 404 verified over HTTP.
     * `BiaCampaignController::index()` now ships all three URLs itself.
     */
    #[Test]
    public function the_campaign_dashboard_ships_distribute_chase_and_close_urls_bound_to_its_own_uuid(): void
    {
        $campaign = app(BiaCampaignService::class)->create(['name' => 'URL Campaign', 'closes_at' => now()->addWeeks(2)]);
        $viewer = $this->userWith(['bcms.bia.view'], 'campaign-viewer@khb.test', 'bcms-campaign-viewer');

        $this->actingAs($viewer)
            ->get(route('bcms.bia-campaigns.index', ['campaign' => $campaign->getKey()]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($campaign) {
                $page->component('Bcms/Bia/Campaigns');

                $row = collect($page->toArray()['props']['campaigns'])->firstWhere('id', $campaign->getKey());

                $this->assertSame(route('bcms.bia-campaigns.distribute', $campaign), $row['distribute_url']);
                $this->assertSame(route('bcms.bia-campaigns.chase', $campaign), $row['chase_url']);
                $this->assertSame(route('bcms.bia-campaigns.close', $campaign), $row['close_url']);

                $this->assertNotSame((string) $campaign->getKey(), (string) $campaign->uuid);
                $this->assertStringContainsString((string) $campaign->uuid, $row['distribute_url']);
                $this->assertStringNotContainsString('/bia-campaigns/'.$campaign->getKey().'/', $row['distribute_url']);
            });
    }

    #[Test]
    public function posting_to_the_props_distribute_url_distributes_the_campaign(): void
    {
        $this->process('BCP-CAMP-DIST', ['owner_id' => $this->assessor->id]);
        $campaign = app(BiaCampaignService::class)->create(['name' => 'Distribute URL Campaign', 'closes_at' => now()->addWeeks(2)]);
        $manager = $this->userWith(['bcms.bia.view', 'bcms.bia.campaign.manage'], 'distribute-manager@khb.test', 'bcms-distribute-manager');

        $distributeUrl = $this->actingAs($manager)
            ->get(route('bcms.bia-campaigns.index', ['campaign' => $campaign->getKey()]))
            ->viewData('page')['props']['campaigns'][0]['distribute_url'];

        $this->actingAs($manager)
            ->post($distributeUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertGreaterThan(0, BiaAssessment::query()->where('campaign_id', $campaign->getKey())->count());
        $this->assertSame('open', $campaign->fresh()->status);

        $audit = \App\Models\Bcms\AuditLog::where('auditable_type', \App\Models\Bcms\BiaCampaign::class)
            ->where('auditable_id', $campaign->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, 'BiaCampaign carries BcmsAuditable; distribute() updates its status and must write a row.');
        $this->assertSame('open', $audit->after['status'] ?? null);
    }

    #[Test]
    public function posting_to_the_props_chase_url_chases_the_campaign(): void
    {
        $owner = $this->user('chase-owner@khb.test');
        $this->process('BCP-CAMP-CHASE', ['owner_id' => $owner->id]);
        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Chase URL Campaign', 'closes_at' => now()->subDay()]);
        $campaigns->distribute($campaign);
        $manager = $this->userWith(['bcms.bia.view', 'bcms.bia.campaign.manage'], 'chase-manager@khb.test', 'bcms-chase-manager');

        $assessment = BiaAssessment::query()->where('campaign_id', $campaign->getKey())->firstOrFail();
        $this->assertSame(0, (int) $assessment->chase_count, 'Precondition: never chased yet.');
        $this->assertNull($assessment->chased_at, 'Precondition: never chased yet.');

        $chaseUrl = $this->actingAs($manager)
            ->get(route('bcms.bia-campaigns.index', ['campaign' => $campaign->getKey()]))
            ->viewData('page')['props']['campaigns'][0]['chase_url'];

        $this->actingAs($manager)
            ->post($chaseUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $assessment->refresh();
        $this->assertSame(1, (int) $assessment->chase_count, 'chase() must increment chase_count on the overdue assessment.');
        $this->assertNotNull($assessment->chased_at, 'chase() must stamp chased_at on the overdue assessment.');
        $this->assertTrue($assessment->chased_at->isToday());

        $audit = \App\Models\Bcms\AuditLog::where('auditable_type', BiaAssessment::class)
            ->where('auditable_id', $assessment->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, 'BiaAssessment carries BcmsAuditable; chase() changes chased_at/chase_count and must write a row.');
        $this->assertArrayHasKey('chase_count', $audit->after ?? []);
        $this->assertSame(1, (int) $audit->after['chase_count']);
    }

    /**
     * The only existing close-route test (`the_close_route_now_catches_the_
     * already_closed_exception_consistently_with_distribute`) exercises the
     * ALREADY-CLOSED error path and builds its URL with `route()` directly.
     * This is the positive path, through the shipped `close_url`.
     */
    #[Test]
    public function posting_to_the_props_close_url_closes_the_campaign_and_freezes_its_response_rate(): void
    {
        $this->process('BCP-CAMP-CLOSE-1');
        $this->process('BCP-CAMP-CLOSE-2');
        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Close URL Campaign', 'closes_at' => now()->addWeek()]);
        $campaigns->distribute($campaign);

        $first = BiaAssessment::query()->where('campaign_id', $campaign->getKey())->firstOrFail();
        $this->completeAndSubmit($first);

        $expectedRate = $campaigns->progress($campaign->refresh())['response_rate'];
        $this->assertNotNull($expectedRate, 'Precondition: at least one response, so the rate is not null.');

        $manager = $this->userWith(['bcms.bia.view', 'bcms.bia.campaign.manage'], 'close-manager@khb.test', 'bcms-close-manager');

        $closeUrl = $this->actingAs($manager)
            ->get(route('bcms.bia-campaigns.index', ['campaign' => $campaign->getKey()]))
            ->viewData('page')['props']['campaigns'][0]['close_url'];

        $this->actingAs($manager)
            ->post($closeUrl)
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign->refresh();
        $this->assertSame('closed', $campaign->status);
        $this->assertSame($expectedRate, (float) $campaign->response_rate);

        $audit = \App\Models\Bcms\AuditLog::where('auditable_type', \App\Models\Bcms\BiaCampaign::class)
            ->where('auditable_id', $campaign->getKey())
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, 'BiaCampaign carries BcmsAuditable; close() updates status and response_rate and must write a row.');
        $this->assertSame('closed', $audit->after['status'] ?? null);
        $this->assertArrayHasKey('response_rate', $audit->after ?? []);
    }

    #[Test]
    public function a_user_of_another_tenant_posting_to_the_shipped_campaign_urls_gets_404_and_changes_nothing(): void
    {
        $this->process('BCP-CAMP-CROSS', ['owner_id' => $this->assessor->id]);
        $campaign = app(BiaCampaignService::class)->create(['name' => 'Cross-tenant Campaign', 'closes_at' => now()->addWeeks(2)]);
        $viewer = $this->userWith(['bcms.bia.view'], 'campaign-cross-viewer@khb.test', 'bcms-campaign-cross-viewer');

        $statusBeforeForeignPosts = $campaign->status;
        $responseRateBeforeForeignPosts = $campaign->response_rate;
        $opensAtBeforeForeignPosts = $campaign->opens_at;
        $closesAtBeforeForeignPosts = $campaign->closes_at;

        $row = collect(
            $this->actingAs($viewer)
                ->get(route('bcms.bia-campaigns.index', ['campaign' => $campaign->getKey()]))
                ->viewData('page')['props']['campaigns']
        )->firstWhere('id', $campaign->getKey());

        $foreignOrg = Organization::create([
            'name' => 'Fourth Bank PLC', 'short_name' => 'FBP',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $foreignUser = User::create([
            'name' => 'Foreign Campaign Manager', 'email' => 'manager@fbp.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $foreignOrg->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('bcms-foreign-campaign-manager', 'web');
        $role->givePermissionTo(Permission::findOrCreate('bcms.bia.campaign.manage', 'web'));
        $foreignUser->assignRole($role);

        $this->actingAs($foreignUser)->post($row['distribute_url'])->assertNotFound();
        $this->actingAs($foreignUser)->post($row['chase_url'])->assertNotFound();
        $this->actingAs($foreignUser)->post($row['close_url'])->assertNotFound();

        $this->assertSame(
            0,
            BiaAssessment::query()->where('campaign_id', $campaign->getKey())->count(),
            'A cross-tenant post to the shipped distribute_url must not create any assessment.'
        );

        $campaign->refresh();
        $this->assertSame(
            $statusBeforeForeignPosts,
            $campaign->status,
            'A cross-tenant post to the shipped close_url must not change the campaign status.'
        );
        $this->assertEquals(
            $responseRateBeforeForeignPosts,
            $campaign->response_rate,
            'A cross-tenant post to the shipped close_url must not freeze a response rate.'
        );
        $this->assertEquals(
            $opensAtBeforeForeignPosts,
            $campaign->opens_at,
            'A cross-tenant post to the shipped distribute_url must not stamp opens_at.'
        );
        $this->assertEquals(
            $closesAtBeforeForeignPosts,
            $campaign->closes_at,
            'A cross-tenant post to the shipped close_url must not move closes_at.'
        );
    }

    /* ------------------------------------------------------------------ */

    private function completeAndSubmit(BiaAssessment $assessment): void
    {
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        app(BiaAssessmentService::class)->submit($assessment->refresh());
    }

    private function approve(BiaAssessment $assessment, ?int $tier = null): void
    {
        if ($assessment->status !== BiaAssessmentStatus::Submitted) {
            app(BiaAssessmentService::class)->submit($assessment);
        }

        app(BiaAssessmentService::class)->approve($assessment->refresh(), $this->approver->id, $tier);
    }
}
