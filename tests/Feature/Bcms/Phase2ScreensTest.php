<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Models\Bcms\Application;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Process;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Bia\BiaAssessmentService;
use App\Services\Bcms\Bia\BiaCampaignService;
use App\Services\Bcms\Bia\DependencyService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 2 screens: that they render, that they are gated, and that the props
 * they hand the page are the ones it reads.
 *
 * As always, these stop at the props boundary — no JavaScript runs in this suite
 * (development standard §10), so they prove the server hands the page what it
 * needs and gates who reaches it, not that the page draws it.
 */
class Phase2ScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

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
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email = 'bc@khb.test', ?BusinessUnit $unit = null): User
    {
        $unit ??= $this->unit;

        $user = User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $unit?->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('bcms-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }

            $user->assignRole($role);
        }

        if ($unit !== null) {
            DB::table('business_unit_user')->insert([
                'organization_id' => $this->organization->id, 'user_id' => $user->id,
                'business_unit_id' => $unit->id, 'includes_descendants' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function process(string $code, array $attributes = []): Process
    {
        return Process::query()->create(array_merge([
            'code' => $code, 'name' => "Process {$code}", 'status' => 'active',
            'business_unit_id' => $this->unit->id,
        ], $attributes));
    }

    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_bia_section_replaced_its_shell_and_kept_its_route_name(): void
    {
        $user = $this->userWith(['bcms.bia.view']);

        $this->actingAs($user)
            ->get(route('bcms.bia.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Bcms/Bia/Index'));
    }

    #[Test]
    public function a_user_without_bia_view_is_forbidden_across_the_phase(): void
    {
        $user = $this->userWith([]);

        foreach ([
            'bcms.bia.index', 'bcms.bia-campaigns.index', 'bcms.dependencies.index', 'bcms.bia-report.index',
        ] as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }

    #[Test]
    public function the_workspace_hands_the_page_the_grid_the_validation_and_the_derived_mtpd(): void
    {
        $user = $this->userWith(['bcms.bia.view', 'bcms.bia.complete']);
        $process = $this->process('BCP-001', ['owner_id' => $user->id]);
        $assessment = app(BiaAssessmentService::class)->start($process, $user->id);

        app(BiaAssessmentService::class)->scoreImpact($assessment, ImpactCategory::Regulatory, ImpactHorizon::H4, 4);
        app(BiaAssessmentService::class)->save($assessment->refresh(), ['mtpd_hours' => 4, 'rto_hours' => 8]);

        $this->actingAs($user)
            ->get(route('bcms.bia.show', $assessment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Bia/Workspace')
                ->has('grid.categories', count(ImpactCategory::cases()))
                ->has('grid.horizons', count(ImpactHorizon::cases()))
                ->where('grid.cells.regulatory.4h.severity', 4)
                ->where('derived_mtpd.hours', 4)
                // Blocking and warnings are separate props: one stops the
                // submit button and the other never does.
                ->has('validation.blocking', 1)
                ->where('ai.available', false)
                ->has('ai.reason')
            );
    }

    #[Test]
    public function the_workspace_does_not_offer_approval_to_the_assessor(): void
    {
        // Shown on the screen as well as enforced in the service, so the button
        // is not offered to somebody who will be refused.
        $assessor = $this->userWith(['bcms.bia.view', 'bcms.bia.complete', 'bcms.bia.approve'], 'assessor@khb.test');
        $reviewer = $this->userWith(['bcms.bia.view', 'bcms.bia.approve'], 'reviewer@khb.test');

        $process = $this->process('BCP-001', ['owner_id' => $assessor->id]);
        $assessment = app(BiaAssessmentService::class)->start($process, $assessor->id);

        $this->actingAs($assessor)->get(route('bcms.bia.show', $assessment))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can.approve', true)->where('can.approve_this', false));

        $this->actingAs($reviewer)->get(route('bcms.bia.show', $assessment))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can.approve_this', true));
    }

    #[Test]
    public function the_full_assessment_flow_runs_through_the_screens(): void
    {
        $assessor = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'assessor@khb.test');
        $approver = $this->userWith(['bcms.bia.view', 'bcms.bia.approve'], 'approver@khb.test');

        $process = $this->process('BCP-001', ['owner_id' => $assessor->id]);

        $this->actingAs($assessor)->post(route('bcms.bia.store', $process))->assertRedirect();
        $assessment = BiaAssessment::query()->firstOrFail();

        $this->actingAs($assessor)->post(route('bcms.bia.impacts.store', $assessment), [
            'impact_category' => 'regulatory', 'horizon' => '4h', 'severity_score' => 4,
            'narrative' => 'CBN returns miss their window.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // The grid proposed 4h; accepting it is a deliberate act.
        $this->actingAs($assessor)->post(route('bcms.bia.accept-mtpd', $assessment))->assertRedirect();
        $this->assertSame(4.0, (float) $assessment->refresh()->mtpd_hours);

        $this->actingAs($assessor)->put(route('bcms.bia.update', $assessment), [
            'mtpd_hours' => 4, 'rto_hours' => 2, 'rpo_minutes' => 15,
            'mbco_description' => 'Settlement of retail values only.',
            'is_critical_service' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($assessor)->post(route('bcms.bia.submit', $assessment))->assertRedirect();
        $this->assertSame('submitted', $assessment->refresh()->status->value);

        // The assessor cannot approve their own, even holding the grant.
        $this->actingAs($approver)->post(route('bcms.bia.approve', $assessment))->assertRedirect();
        $this->assertSame('approved', $assessment->refresh()->status->value);
        $this->assertSame(1, $process->refresh()->criticality_tier);
    }

    #[Test]
    public function submitting_an_assessment_with_a_blocking_issue_explains_itself(): void
    {
        $user = $this->userWith(['bcms.bia.view', 'bcms.bia.complete']);
        $process = $this->process('BCP-001', ['owner_id' => $user->id]);
        $assessment = app(BiaAssessmentService::class)->start($process, $user->id);

        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 4, 'rto_hours' => 8]);

        $this->actingAs($user)
            ->post(route('bcms.bia.submit', $assessment))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('in_progress', $assessment->refresh()->status->value);
    }

    #[Test]
    public function a_dependency_cannot_be_both_a_single_point_of_failure_and_have_an_alternative(): void
    {
        // The commonest way a SPOF register under-counts: somebody ticks both
        // because each sounds true on its own.
        $user = $this->userWith(['bcms.bia.view', 'bcms.bia.complete']);
        $process = $this->process('BCP-001', ['owner_id' => $user->id]);
        $assessment = app(BiaAssessmentService::class)->start($process, $user->id);
        $application = Application::query()->create(['code' => 'APP-CORE', 'name' => 'Core']);

        $this->actingAs($user)->post(route('bcms.bia.dependencies.store', $assessment), [
            'dependable_type' => 'bcms_application',
            'dependable_id' => $application->id,
            'dependency_type' => 'upstream',
            'criticality' => 'critical',
            'single_point_of_failure' => true,
            'alternative_available' => true,
        ])->assertSessionHasErrors('single_point_of_failure');
    }

    #[Test]
    public function the_campaign_dashboard_shows_the_overdue_list_with_chase_state(): void
    {
        $coordinator = $this->userWith(['bcms.bia.view', 'bcms.bia.campaign.manage']);
        $owner = $this->userWith(['bcms.bia.complete'], 'owner@khb.test');
        $this->process('BCP-001', ['owner_id' => $owner->id]);

        $campaigns = app(BiaCampaignService::class);
        $campaign = $campaigns->create(['name' => 'Annual BIA', 'closes_at' => now()->subDay()]);
        $campaigns->distribute($campaign);
        $campaigns->chase($campaign);

        $this->actingAs($coordinator)
            ->get(route('bcms.bia-campaigns.index', ['campaign' => $campaign->getKey()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Bia/Campaigns')
                ->where('progress.counts.total', 1)
                ->has('progress.overdue', 1)
                ->where('progress.overdue.0.chase_count', 1)
                ->where('can.manage', true)
            );
    }

    #[Test]
    public function a_campaign_without_a_deadline_is_refused(): void
    {
        // Without one nothing is ever overdue and nobody is ever chased.
        $user = $this->userWith(['bcms.bia.view', 'bcms.bia.campaign.manage']);

        $this->actingAs($user)
            ->post(route('bcms.bia-campaigns.store'), ['name' => 'Annual BIA', 'cycle' => 'annual'])
            ->assertSessionHasErrors('closes_at');
    }

    #[Test]
    public function the_dependency_explorer_carries_the_spof_register_and_the_shared_view(): void
    {
        $user = $this->userWith(['bcms.bia.view']);
        $core = Application::query()->create(['code' => 'APP-CORE', 'name' => 'Core Banking Platform']);

        foreach (['BCP-001' => 1, 'BCP-002' => 2] as $code => $tier) {
            $process = $this->process($code, ['criticality_tier' => $tier, 'owner_id' => $user->id]);
            $assessment = app(BiaAssessmentService::class)->start($process, $user->id);
            app(DependencyService::class)->attach($assessment, $core, [
                'criticality' => 'critical', 'single_point_of_failure' => true,
            ]);
        }

        $this->actingAs($user)
            ->get(route('bcms.dependencies.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Bia/Dependencies')
                ->has('spof_register', 1)
                ->where('spof_register.0.process_count', 2)
                ->has('shared', 1)
                ->has('graph.nodes')
                ->has('graph.edges', 2)
            );
    }

    #[Test]
    public function the_reverse_impact_screen_renders_for_a_dependency(): void
    {
        $user = $this->userWith(['bcms.bia.view']);
        $core = Application::query()->create(['code' => 'APP-CORE', 'name' => 'Core Banking Platform']);

        $process = $this->process('BCP-001', ['criticality_tier' => 1, 'owner_id' => $user->id]);
        $assessment = app(BiaAssessmentService::class)->start($process, $user->id);
        app(DependencyService::class)->attach($assessment, $core, ['criticality' => 'critical']);

        $this->actingAs($user)
            ->get(route('bcms.dependencies.impact-of', ['bcms_application', $core->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Bia/ReverseImpact')
                ->where('impact.target.name', 'Core Banking Platform')
                ->where('impact.process_count', 1)
            );
    }

    #[Test]
    public function an_unknown_dependency_type_is_a_404(): void
    {
        $user = $this->userWith(['bcms.bia.view']);

        $this->actingAs($user)->get(route('bcms.dependencies.impact-of', ['not_a_type', 1]))->assertNotFound();
    }

    #[Test]
    public function the_report_states_its_coverage_and_lists_what_it_does_not_cover(): void
    {
        // A report over one approved assessment out of three processes is a
        // report about one process, and a reader not told that will circulate
        // it as the whole picture.
        $assessor = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'assessor@khb.test');
        $approver = $this->userWith(['bcms.bia.view', 'bcms.report.export'], 'approver@khb.test');

        $covered = $this->process('BCP-001', ['owner_id' => $assessor->id]);
        $this->process('BCP-002');
        $this->process('BCP-003');

        $assessment = app(BiaAssessmentService::class)->start($covered, $assessor->id);
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        app(BiaAssessmentService::class)->submit($assessment->refresh());
        app(BiaAssessmentService::class)->approve($assessment->refresh(), $approver->id);

        $this->actingAs($approver)
            ->get(route('bcms.bia-report.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Bia/Report')
                ->has('rows', 1)
                ->has('gaps', 2)
                ->where('coverage.approved', 1)
                ->where('coverage.in_scope', 3)
                ->where('coverage.rate', 33.3)
            );
    }

    #[Test]
    public function the_report_export_carries_the_gaps_as_well_as_the_rows(): void
    {
        $assessor = $this->userWith(['bcms.bia.view', 'bcms.bia.complete'], 'assessor@khb.test');
        $exporter = $this->userWith(['bcms.bia.view', 'bcms.report.export'], 'exporter@khb.test');

        $covered = $this->process('BCP-001', ['owner_id' => $assessor->id]);
        $this->process('BCP-GAP');

        $assessment = app(BiaAssessmentService::class)->start($covered, $assessor->id);
        app(BiaAssessmentService::class)->save($assessment, ['mtpd_hours' => 24, 'rto_hours' => 4]);
        app(BiaAssessmentService::class)->submit($assessment->refresh());
        app(BiaAssessmentService::class)->approve($assessment->refresh(), $exporter->id);

        $csv = $this->actingAs($exporter)->get(route('bcms.bia-report.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('BCP-001', $csv);
        $this->assertStringContainsString('Processes with no approved BIA', $csv);
        $this->assertStringContainsString('BCP-GAP', $csv);
    }

    #[Test]
    public function a_branch_manager_sees_only_their_own_branchs_assessments(): void
    {
        $other = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAG', 'name' => 'Lagos', 'is_active' => true,
        ]);

        $manager = $this->userWith(['bcms.bia.view'], 'kano@khb.test', $this->unit);

        $mine = $this->process('BCP-MINE');
        $theirs = Process::query()->create([
            'code' => 'BCP-THEIRS', 'name' => 'Theirs', 'status' => 'active', 'business_unit_id' => $other->id,
        ]);

        app(BiaAssessmentService::class)->start($mine, $manager->id);
        app(BiaAssessmentService::class)->start($theirs, $manager->id);

        $this->actingAs($manager)
            ->get(route('bcms.bia.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $codes = array_column($page->toArray()['props']['assessments']['data'], 'code');

                $this->assertSame(['BCP-MINE'], $codes);
            });
    }
}
