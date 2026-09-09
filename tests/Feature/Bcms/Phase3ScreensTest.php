<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\ImpactCategory;
use App\Enums\Bcms\ImpactHorizon;
use App\Enums\Bcms\PlanType;
use App\Enums\Bcms\StrategyType;
use App\Models\Bcms\Plan;
use App\Models\Bcms\Process;
use App\Models\Bcms\Strategy;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Bia\BiaAssessmentService;
use App\Services\Bcms\Plans\PlanAssembler;
use App\Services\Bcms\Plans\PlanService;
use App\Services\Bcms\Strategy\StrategyService;
use App\Support\Bcms\ModuleSections;
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
 * The Phase 3 screens: that they render, that they are gated, and that the props
 * they hand the page are the ones it reads.
 *
 * As always these stop at the props boundary — no JavaScript runs in this suite
 * (development standard §10), so they prove the server hands the page what it
 * needs and gates who reaches it, not that the page draws it.
 *
 * THE OFFLINE BUNDLE IS GATED ON `bcms.contact.export`, NOT `bcms.plan.view`,
 * and that is asserted here rather than left to the route file to be right
 * about. It carries mobile numbers off the platform onto a phone — that is its
 * whole purpose — and a bulk personal-data export under the NDPA is what it is
 * however the button is labelled.
 */
class Phase3ScreensTest extends TestCase
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

    /* ------------------------------------------------------------------ */
    /*  The two shells this phase replaced */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_strategy_and_plan_sections_replaced_their_shells_and_kept_their_route_names(): void
    {
        foreach (['strategy', 'plans'] as $key) {
            $section = ModuleSections::find($key);

            $this->assertNotNull($section);
            $this->assertTrue($section['live'], "The {$key} section is still declared as a shell.");
        }

        $this->actingAs($this->userWith(['bcms.strategy.view']))
            ->get(route('bcms.strategy.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Bcms/Strategy/Index'));

        $this->actingAs($this->userWith(['bcms.plan.view'], 'planner@khb.test'))
            ->get(route('bcms.plans.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Bcms/Plans/Index'));
    }

    /* ------------------------------------------------------------------ */
    /*  Strategy screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_strategy_register_hands_the_page_cards_a_gap_and_a_scatter(): void
    {
        $process = $this->processWithBia('BCP-PY', rto: 0.5);
        $this->strategy($process, achievable: 1.5, cost: 18_000_000_00);

        $this->actingAs($this->userWith(['bcms.strategy.view', 'bcms.strategy.manage']))
            ->get(route('bcms.strategy.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Strategy/Index')
                ->has('cards', 1)
                ->where('cards.0.code', 'BCP-PY')
                ->where('cards.0.rto_required_hours', 0.5)
                ->has('cards.0.options', 1)
                ->has('scatter.points', 1)
                ->where('scatter.unplottable_count', 0)
                ->where('gap.summary.with_gap', 1)
                ->where('can.manage', true)
                ->where('can.approve', false)
            );
    }

    #[Test]
    public function an_option_with_no_cost_is_counted_as_unplottable_rather_than_dropped(): void
    {
        $process = $this->processWithBia('BCP-CORE', rto: 2);

        app(StrategyService::class)->propose($process, StrategyType::ManualWorkaround, [
            'title' => 'Paper ledger', 'rto_achievable_hours' => 8,
        ]);

        $this->actingAs($this->userWith(['bcms.strategy.view']))
            ->get(route('bcms.strategy.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('scatter.points', 0)
                ->where('scatter.unplottable_count', 1)
            );
    }

    #[Test]
    public function the_gap_analysis_exports_the_rows_the_screen_shows(): void
    {
        $process = $this->processWithBia('BCP-CARD', rto: 1);
        $this->strategy($process, achievable: 2, cost: 22_000_000_00);

        $response = $this->actingAs($this->userWith(['bcms.strategy.view', 'bcms.report.export']))
            ->get(route('bcms.strategy.gap.export'));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Shortfall (h)', $csv);
        $this->assertStringContainsString('BCP-CARD', $csv);
        $this->assertStringContainsString('1', $csv);
    }

    #[Test]
    public function the_gap_export_needs_the_report_export_grant(): void
    {
        $this->actingAs($this->userWith(['bcms.strategy.view']))
            ->get(route('bcms.strategy.gap.export'))
            ->assertForbidden();
    }

    #[Test]
    public function the_compare_screen_lists_every_option_against_the_required_rto(): void
    {
        $process = $this->processWithBia('BCP-PAY', rto: 1);
        $selected = $this->strategy($process, achievable: 2, cost: 18_000_000_00);

        app(StrategyService::class)->propose($process, StrategyType::ManualWorkaround, [
            'title' => 'Paper ledger', 'rto_achievable_hours' => 0.5,
        ], $this->author()->id);

        $this->actingAs($this->userWith(['bcms.strategy.view', 'bcms.strategy.manage']))
            ->get(route('bcms.strategy.show', $process))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Strategy/Compare')
                ->where('process.code', 'BCP-PAY')
                ->where('required.rto_hours', fn ($hours) => (float) $hours === 1.0)
                ->has('options', 2)
                ->where(
                    'options',
                    fn ($options) => collect($options)->firstWhere('id', $selected->id)['is_selected'] === true
                        && collect($options)->firstWhere('title', 'Paper ledger')['meets_requirement'] === true
                )
                ->where('can.manage', true)
                ->where('can.approve', false)
            );
    }

    #[Test]
    public function the_compare_screen_needs_the_strategy_view_permission(): void
    {
        $process = $this->processWithBia('BCP-NOVIEW', rto: 1);

        $this->actingAs($this->userWith([], 'nobody-strategy@khb.test'))
            ->get(route('bcms.strategy.show', $process))
            ->assertForbidden();
    }

    #[Test]
    public function the_compare_screen_does_not_reach_another_tenants_process(): void
    {
        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);
        $foreign = Process::factory()->create();
        TenantContext::set($this->organization->id);

        $this->actingAs($this->userWith(['bcms.strategy.view']))
            ->get(route('bcms.strategy.show', $foreign))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Plan screens */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_plan_library_reports_currency_without_inventing_a_percentage(): void
    {
        $this->actingAs($this->userWith(['bcms.plan.view']))
            ->get(route('bcms.plans.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('plans', 0)
                ->where('currency.approved', 0)
                // Null, not 100. An organisation with no plans has not achieved
                // perfect currency.
                ->where('currency.percentage', null)
                ->has('templates', 12)
                ->where('can.manage', false)
            );
    }

    #[Test]
    public function the_builder_hands_the_page_its_sections_with_their_live_data(): void
    {
        $this->processWithBia('BCP-CORE', rto: 2);

        $plan = $this->plan();
        app(PlanAssembler::class)->assemble($plan);

        $this->actingAs($this->userWith(['bcms.plan.view', 'bcms.plan.manage']))
            ->get(route('bcms.plans.show', $plan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Plans/Show')
                ->where('plan.version', '1.0')
                ->where('plan.immutable', false)
                ->has('sections', 16)
                ->has('sources', 8)
                // A draft renders live and has nothing frozen to compare with.
                ->where('live_sections', null)
                ->where('acknowledgement', null)
                ->where('can.manage', true)
                ->where('ai.available', false)
            );
    }

    #[Test]
    public function an_approved_plan_is_handed_both_the_frozen_render_and_the_live_one(): void
    {
        $this->processWithBia('BCP-CHAN', rto: 1);

        $plan = $this->approvedPlan();

        $this->actingAs($this->userWith(['bcms.plan.view']))
            ->get(route('bcms.plans.show', $plan))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('plan.immutable', true)
                ->has('sections', 16)
                // Both, because deciding whether a v2 is needed means seeing
                // them side by side.
                ->has('live_sections', 16)
                ->has('acknowledgement')
                ->where('has_acknowledged', false)
                ->where('can.manage', false)
            );
    }

    #[Test]
    public function the_stale_list_is_the_kri_drilled_into(): void
    {
        $plan = $this->approvedPlan();
        $plan->forceFill(['next_review_date' => now()->subDays(30)->toDateString()])->save();

        $this->actingAs($this->userWith(['bcms.plan.view']))
            ->get(route('bcms.plans.stale'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Plans/Stale')
                ->has('plans', 1)
                ->where('plans.0.days_overdue', 30)
                ->where('currency.percentage', 0)
            );
    }

    #[Test]
    public function stale_binds_as_a_route_and_not_as_a_plan_id(): void
    {
        // `plans/stale` is declared before `plans/{plan}`. Get that wrong and
        // the KRI's own list 404s, which is exactly the sort of thing nobody
        // notices until a demo.
        $this->actingAs($this->userWith(['bcms.plan.view']))
            ->get(route('bcms.plans.stale'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Bcms/Plans/Stale'));
    }

    #[Test]
    public function the_bulk_review_cycle_counts_from_the_effective_date_and_not_from_today(): void
    {
        // The whole design of this action. A bulk button that counted forward
        // from the moment it was pressed would let anybody clear the past-review
        // list by pressing it, which is exactly the gaming a staleness KRI
        // invites.
        $plan = $this->approvedPlan();
        $plan->forceFill(['effective_from' => now()->subMonths(18)->toDateString()])->save();

        $this->actingAs($this->userWith(['bcms.plan.view', 'bcms.plan.manage']))
            ->post(route('bcms.plans.review-cycle'), [
                'plan_ids' => [$plan->getKey()],
                'review_frequency_months' => 12,
            ])
            ->assertRedirect();

        $plan->refresh();

        $this->assertSame(12, $plan->review_frequency_months);
        $this->assertSame(
            now()->subMonths(18)->addMonths(12)->toDateString(),
            $plan->next_review_date->toDateString(),
        );
        $this->assertTrue($plan->next_review_date->isPast(), 'It is still overdue, which is the point.');
        $this->assertCount(1, app(PlanService::class)->staleQuery()->get());
    }

    #[Test]
    public function the_bulk_review_cycle_only_touches_plans_the_user_can_see(): void
    {
        $otherUnit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OTHER', 'name' => 'Elsewhere', 'is_active' => true,
        ]);

        $mine = $this->plan('Mine');
        $theirs = app(PlanService::class)->create(
            PlanType::Bcp,
            'Somewhere else',
            ['business_unit_id' => $otherUnit->id, 'review_frequency_months' => 12],
            'bcp_group',
            $this->author()->id,
        );

        $this->actingAs($this->userWith(['bcms.plan.view', 'bcms.plan.manage']))
            ->post(route('bcms.plans.review-cycle'), [
                'plan_ids' => [$mine->getKey(), $theirs->getKey()],
                'review_frequency_months' => 6,
            ])
            ->assertRedirect();

        $this->assertSame(6, $mine->refresh()->review_frequency_months);
        $this->assertSame(12, $theirs->refresh()->review_frequency_months, 'A bulk action is the easiest place to edit rows somebody cannot open.');
    }

    /* ------------------------------------------------------------------ */
    /*  Gating */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_offline_bundle_is_gated_on_the_contact_export_grant(): void
    {
        $plan = $this->approvedPlan();

        // Seeing a plan is not the same as taking its contact numbers away on a
        // phone.
        $this->actingAs($this->userWith(['bcms.plan.view'], 'reader@khb.test'))
            ->get(route('bcms.plans.bundle', $plan))
            ->assertForbidden();

        $this->actingAs($this->userWith(['bcms.plan.view', 'bcms.contact.export'], 'exporter@khb.test'))
            ->get(route('bcms.plans.bundle', $plan))
            ->assertOk()
            ->assertJsonPath('plan.uuid', $plan->uuid);
    }

    #[Test]
    public function acknowledging_needs_nothing_beyond_seeing_the_plan(): void
    {
        $plan = $this->approvedPlan();
        $user = $this->userWith(['bcms.plan.view'], 'reader@khb.test');

        $this->actingAs($user)
            ->post(route('bcms.plans.acknowledge', $plan))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('bcms.plans.show', $plan))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('has_acknowledged', true));
    }

    #[Test]
    public function editing_a_section_of_an_approved_plan_is_refused_with_an_explanation(): void
    {
        $plan = $this->approvedPlan();
        $section = $plan->sections()->first();

        $this->actingAs($this->userWith(['bcms.plan.view', 'bcms.plan.manage']))
            ->patch(route('bcms.plans.sections.update', [$plan, $section]), ['body' => 'Rewriting history.'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'cannot be edited'));

        $this->assertNotSame('Rewriting history.', $section->refresh()->body);
    }

    #[Test]
    public function a_section_of_another_plan_cannot_be_edited_through_this_one(): void
    {
        $mine = $this->plan('Mine');
        $theirs = $this->plan('Theirs');
        $section = $theirs->sections()->first();

        // Both plans belong to this tenant, so tenancy would not stop this. The
        // controller checks the section belongs to the plan in the URL.
        $this->actingAs($this->userWith(['bcms.plan.view', 'bcms.plan.manage']))
            ->patch(route('bcms.plans.sections.update', [$mine, $section]), ['body' => 'Not mine.'])
            ->assertNotFound();
    }

    #[Test]
    public function the_pdf_downloads_for_anybody_who_may_see_the_plan(): void
    {
        $plan = $this->approvedPlan();

        $response = $this->actingAs($this->userWith(['bcms.plan.view']))
            ->get(route('bcms.plans.pdf', $plan));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function every_phase_three_route_is_behind_a_permission(): void
    {
        $unprivileged = $this->userWith([], 'nobody@khb.test');
        $plan = $this->plan();

        foreach ([
            route('bcms.strategy.index'),
            route('bcms.strategy.gap'),
            route('bcms.plans.index'),
            route('bcms.plans.stale'),
            route('bcms.plans.show', $plan),
        ] as $url) {
            $this->actingAs($unprivileged)->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function the_whole_phase_is_behind_the_feature_flag(): void
    {
        config()->set('features.bcms', false);

        $this->actingAs($this->userWith(['bcms.strategy.view', 'bcms.plan.view']))
            ->get(route('bcms.plans.index'))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email = 'bc@khb.test'): User
    {
        $user = User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = Role::findOrCreate('bcms-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }

            $user->assignRole($role);
        }

        DB::table('business_unit_user')->insert([
            'organization_id' => $this->organization->id, 'user_id' => $user->id,
            'business_unit_id' => $this->unit->id, 'includes_descendants' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function processWithBia(string $code, float $rto): Process
    {
        $author = $this->author();
        $approver = $this->approver();

        $process = Process::query()->create([
            'code' => $code, 'name' => "Process {$code}", 'status' => 'active',
            'business_unit_id' => $this->unit->id, 'criticality_tier' => 1,
        ]);

        $assessments = app(BiaAssessmentService::class);
        $assessment = $assessments->start($process, $author->id);

        $assessments->save($assessment, [
            'mtpd_hours' => $rto * 8, 'rto_hours' => $rto, 'rpo_minutes' => 15,
            'mbco_description' => 'Minimum service.',
        ]);

        foreach (ImpactHorizon::cases() as $horizon) {
            $assessments->scoreImpact(
                $assessment, ImpactCategory::Regulatory, $horizon,
                $horizon->hours() >= $rto * 8 ? 5 : 2, null, 'Seeded.',
            );
        }

        $assessments->submit($assessment->refresh());
        $assessments->approve($assessment->refresh(), $approver->id, 1);

        return $process->refresh();
    }

    private function strategy(Process $process, float $achievable, int $cost): Strategy
    {
        $strategy = app(StrategyService::class)->propose($process, StrategyType::Recover, [
            'title' => 'Recover in place',
            'rto_achievable_hours' => $achievable,
            'cost_estimate_minor' => $cost,
            'currency' => 'NGN',
        ], $this->author()->id);

        app(StrategyService::class)->select($strategy, 'The funded option.');
        app(StrategyService::class)->approve($strategy->refresh(), $this->approver());

        return $strategy->refresh();
    }

    private function plan(string $title = 'Group Business Continuity Plan'): Plan
    {
        return app(PlanService::class)->create(
            PlanType::Bcp,
            $title,
            ['business_unit_id' => $this->unit->id, 'review_frequency_months' => 12],
            'bcp_group',
            $this->author()->id,
        );
    }

    private function approvedPlan(string $title = 'Group Business Continuity Plan'): Plan
    {
        $plan = $this->plan($title);
        app(PlanAssembler::class)->assemble($plan, $this->author()->id);
        app(PlanService::class)->submitForReview($plan, $this->author()->id);

        return app(PlanService::class)->approve($plan, $this->approver(), now()->toDateString(), 12);
    }

    private function author(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'plan-author@khb.test'],
            [
                'name' => 'Plan Author', 'password' => Hash::make(Str::random(32)),
                'email_verified_at' => now(), 'organization_id' => $this->organization->id,
                'business_unit_id' => $this->unit->id, 'is_active' => true,
            ]
        );
    }

    private function approver(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'plan-approver@khb.test'],
            [
                'name' => 'Plan Approver', 'password' => Hash::make(Str::random(32)),
                'email_verified_at' => now(), 'organization_id' => $this->organization->id,
                'business_unit_id' => $this->unit->id, 'is_active' => true,
            ]
        );
    }
}
