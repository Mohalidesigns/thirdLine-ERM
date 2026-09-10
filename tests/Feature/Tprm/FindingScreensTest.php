<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\FindingStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Findings\FindingService;
use App\Services\Tprm\Scoring\ResidualScoringService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 5 screens, at prop level per standard §10.
 *
 * The two that matter: the board has to hand the page the clock state per card
 * (overdue, overdue-beyond, in-SLA-with-plan) rather than only a status, and
 * the score panel has to render the STORED derivation — because AC-15 is only
 * true if two users read one record instead of each triggering a
 * recomputation.
 */
class FindingScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

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

        $this->manager = $this->userWith([
            'tprm.view', 'tprm.finding.view', 'tprm.finding.manage', 'tprm.finding.accept_risk',
        ], 'manager@khb.test', 'tprm-finding-manager');

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_board_renders_with_swimlanes_and_the_clock_state_per_card(): void
    {
        // A finding two days from its target and one three months past it are
        // both "in remediation", and only one needs a phone call today. The
        // card has to carry that, not just the status.
        $overdue = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'Badly overdue', [], $this->manager->id,
        );
        $overdue->forceFill([
            'identified_at' => now()->subDays($overdue->sla_days * 3),
            'target_date' => now()->subDays($overdue->sla_days * 2)->toDateString(),
        ])->save();

        app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Low, 'Comfortably in time', [], $this->manager->id,
        );

        $this->actingAs($this->manager)
            ->get(route('tprm.findings.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('Tprm/Findings/Index')
                    ->has('findings', 2)
                    ->has('columns')
                    ->has('severities', 4)
                    ->where('summary.open', 2)
                    ->where('summary.overdue', 1)
                    ->where('summary.critical_open', 1);

                $cards = collect($page->toArray()['props']['findings']);
                $late = $cards->firstWhere('title', 'Badly overdue');

                $this->assertTrue($late['is_overdue']);
                $this->assertTrue($late['is_overdue_beyond']);
                $this->assertGreaterThan(0, $late['escalation_level']);

                $fine = $cards->firstWhere('title', 'Comfortably in time');
                $this->assertFalse($fine['is_overdue']);
            });
    }

    #[Test]
    public function the_detail_screen_states_the_acceptance_limits_before_the_form_is_filled_in(): void
    {
        // A form that lets somebody write a justification and pick a
        // three-year expiry, then refuses on submit, has wasted their time and
        // taught them the tool is obstructive.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Critical, 'A serious gap', [], $this->manager->id,
        );

        $this->actingAs($this->manager)
            ->get(route('tprm.findings.show', $finding))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Findings/Show')
                ->where('acceptanceLimits.maximum_months', 6)
                ->where('acceptanceLimits.permission', 'tprm.finding.accept_risk')
                ->where('can.acceptRisk', true)
                // No "close" option on an open finding: the lifecycle is
                // walked, not jumped.
                ->where('finding.next_statuses', fn ($statuses) => ! collect($statuses)
                    ->pluck('value')->contains('closed_remediated'))
            );
    }

    #[Test]
    public function the_score_panel_renders_the_stored_derivation(): void
    {
        // AC-15. The panel reads one record; it does not recompute.
        app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->manager->id,
        );

        $this->actingAs($this->manager)
            ->get(route('tprm.engagements.show', $this->engagement))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertNotNull($props['score']);
                $this->assertEquals(70, $props['score']['ir']);
                $this->assertEquals(7, $props['score']['fu']);
                $this->assertNotEmpty($props['derivation']['headline']);
                $this->assertNotEmpty($props['derivation']['arithmetic']);
                // The Phase 1 keys survive, so the tiering half of the panel
                // keeps rendering on a residual run.
                $this->assertArrayHasKey('knockouts_fired', $props['derivation']);
                $this->assertArrayHasKey('data_confidence', $props['derivation']);
                // The per-finding contribution, not just a total.
                $this->assertCount(1, $props['derivation']['findings']['contributions']);
            });
    }

    #[Test]
    public function two_requests_read_the_same_derivation(): void
    {
        // The literal AC-15 claim: two users see identical derivations.
        app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->manager->id,
        );

        $other = $this->userWith(['tprm.view', 'tprm.finding.view'], 'other@khb.test', 'tprm-viewer');

        $first = $this->actingAs($this->manager)
            ->get(route('tprm.engagements.show', $this->engagement))
            ->viewData('page')['props']['derivation'];

        $second = $this->actingAs($other)
            ->get(route('tprm.engagements.show', $this->engagement))
            ->viewData('page')['props']['derivation'];

        $this->assertSame($first, $second);
    }

    #[Test]
    public function the_workspace_lists_the_findings_entering_the_score(): void
    {
        app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::Medium, 'A gap', [], $this->manager->id,
        );

        $this->actingAs($this->manager)
            ->get(route('tprm.engagements.show', $this->engagement))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('findings', 1));
    }

    #[Test]
    public function recording_a_plan_over_http_moves_it_into_remediation_and_discounts_it(): void
    {
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->manager->id,
        );

        $before = (float) $this->engagement->fresh()->residual_score;

        $this->actingAs($this->manager)
            ->post(route('tprm.findings.plan', $finding), [
                'remediation_plan' => 'The vendor will deploy multi-factor authentication by March.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(FindingStatus::InRemediation, $finding->fresh()->status);

        app(ResidualScoringService::class)->score($this->engagement->fresh(), 'test');

        // 7 at face value becomes 3.5 with a plan inside the SLA.
        $this->assertSame(3.5, round($before - (float) $this->engagement->fresh()->residual_score, 2));
    }

    #[Test]
    public function accepting_a_risk_over_http_needs_a_substantial_justification(): void
    {
        // An acceptance with no stated reason cannot be reviewed by the
        // committee it is reported to.
        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->manager->id,
        );

        $this->actingAs($this->manager)
            ->post(route('tprm.findings.accept-risk', $finding), [
                'justification' => 'too short',
                'expires_at' => now()->addMonths(3)->toDateString(),
            ])
            ->assertSessionHasErrors('justification');

        $this->actingAs($this->manager)
            ->post(route('tprm.findings.accept-risk', $finding), [
                'justification' => 'The vendor is being replaced next quarter and the exposure is bounded by '
                    .'the compensating monitoring already in place.',
                'expires_at' => now()->addMonths(3)->toDateString(),
                'approver_role' => 'Chief Risk Officer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(FindingStatus::ClosedRiskAccepted, $finding->fresh()->status);
    }

    #[Test]
    public function a_user_without_the_risk_permission_cannot_accept(): void
    {
        $analyst = $this->userWith(
            ['tprm.view', 'tprm.finding.view', 'tprm.finding.manage'],
            'analyst@khb.test',
            'tprm-analyst',
        );

        $finding = app(FindingService::class)->raise(
            $this->engagement, 'manual', FindingSeverity::High, 'A gap', [], $this->manager->id,
        );

        $this->actingAs($analyst)
            ->post(route('tprm.findings.accept-risk', $finding), [
                'justification' => 'A justification long enough to satisfy the minimum length requirement here.',
                'expires_at' => now()->addMonths(3)->toDateString(),
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->ucfirst()->toString(),
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

    private function makeEngagement(): Engagement
    {
        $vendor = ThirdParty::create([
            'legal_name' => 'Cloudspan Nigeria Limited', 'slug' => Str::random(10), 'entity_type' => 'company',
        ]);

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-0001',
            'name' => 'Core banking hosting',
            'service_description' => 'Hosting and operation of the core banking platform.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->manager->id,
        ]);

        $engagement->forceFill([
            'inherent_score' => 70,
            'inherent_tier' => RiskTier::High->value,
            'effective_tier' => RiskTier::High->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->organization->id,
            'engagement_id' => $engagement->id,
            'version' => 1,
            'ruleset_version' => '1.0.0',
            'raw_score' => 70,
            'resulting_tier' => RiskTier::High->value,
            'assessed_at' => now(),
            'is_current' => true,
            'answers' => [],
            'factor_scores' => [],
            'weights' => [],
            'explanation' => [],
        ]);

        return $engagement->refresh();
    }
}
