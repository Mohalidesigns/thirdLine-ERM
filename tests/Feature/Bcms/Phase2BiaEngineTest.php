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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
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
        $applicationTables = array_values(array_filter(
            array_map(
                fn (string $t) => str_contains($t, '.') ? substr(strrchr($t, '.'), 1) : $t,
                Schema::getTableListing()
            ),
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
