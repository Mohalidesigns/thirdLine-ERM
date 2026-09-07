<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\IntakeService;
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
 * The Phase 1 screens: that they render, that they are gated, and that the
 * props they hand the page are the ones it reads.
 *
 * Standard §10 is blunt that no JavaScript runs in this suite, so these
 * assertions stop at the props boundary — they prove the server hands the page
 * what it needs, not that the page draws it. `npm run build` and a browser are
 * the only checks on the other side of that line.
 */
class RegisterScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

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

        $this->user = User::create([
            'name' => 'Risk Manager', 'email' => 'rm@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-tester', 'web');
        foreach (['tprm.view', 'tprm.create', 'tprm.edit', 'tprm.intake.approve', 'tprm.report.export'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->user->assignRole($role);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The feature flag                                                   */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_tprm_route_is_invisible_while_the_flag_is_off(): void
    {
        // 404, not 403: a disabled surface should be indistinguishable from
        // one that does not exist (config/features.php's own rule).
        config()->set('features.tprm', false);

        foreach ([
            route('tprm.third-parties.index'),
            route('tprm.engagements.index'),
            route('tprm.intake.create'),
            route('tprm.intake.index'),
        ] as $url) {
            $this->actingAs($this->user)->get($url)->assertNotFound();
        }
    }

    #[Test]
    public function the_navigation_hides_tprm_while_the_flag_is_off(): void
    {
        // A menu entry that 404s is worse than no menu entry.
        config()->set('features.tprm', false);

        $sections = collect(\App\Presenters\NavPresenter::sections())
            ->firstWhere('key', 'tprm');

        $visible = collect($sections['items'] ?? [])
            ->reject(fn (array $item) => ($item['feature'] ?? null) !== null && ! config('features.'.$item['feature']));

        $this->assertCount(0, $visible);
    }

    /* ------------------------------------------------------------------ */
    /*  The register                                                       */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_third_party_register_renders_with_its_grid_and_counters(): void
    {
        $this->makeThirdParty('Interlink Systems Limited', 'interlink');

        $this->actingAs($this->user)
            ->get(route('tprm.third-parties.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/ThirdParties/Index')
                ->has('grid.columns')
                ->has('grid.rows')
                ->where('summary.total', 1)
                ->where('can.create', true)
            );
    }

    #[Test]
    public function the_register_grid_filters_by_tier_through_the_engagement(): void
    {
        // The register lists ENTITIES and this filter is about ENGAGEMENTS —
        // "Critical vendors" means "vendors with a Critical engagement". The
        // filter has to reach through the relationship without multiplying the
        // vendor row by its engagements.
        $critical = $this->makeThirdParty('Critical Vendor', 'critical-vendor');
        $this->makeThirdParty('Quiet Vendor', 'quiet-vendor');

        $this->makeEngagement($critical, RiskTier::Critical);

        $response = $this->actingAs($this->user)->get(
            route('tprm.third-parties.index').'?filters[tier]=critical'
        );

        // `grid.rows` is the paginator envelope — data, links, meta — so the
        // row count lives one level down. Asserting on `grid.rows` counts its
        // three keys and passes for any filter at all.
        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('grid.rows.data', 1)
            ->where('grid.rows.data.0.cells.legal_name.text', 'Critical Vendor')
        );
    }

    #[Test]
    public function the_tier_column_and_the_critical_counter_speak_for_the_same_engagements(): void
    {
        // Found in the browser: the column counted only `isLive()`
        // engagements, so a register of vendors still in onboarding showed "—"
        // for every tier while the counter above it said four were Critical.
        // Two figures contradicting each other on one screen, and the column
        // was the wrong one — a Critical vendor being onboarded is exactly the
        // row the register exists to surface.
        $vendor = $this->makeThirdParty('Onboarding Vendor', 'onboarding-vendor');

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-7001',
            'name' => 'Core banking support',
            'engagement_type' => 'ict_service',
            // Still in intake — not live, but very much a current exposure.
            'status' => 'intake_submitted',
        ]);
        $engagement->forceFill(['effective_tier' => 'critical'])->save();

        $response = $this->actingAs($this->user)->get(route('tprm.third-parties.index'));

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.critical', 1)
            ->where('grid.rows.data.0.cells.tier_rank.text', 'Critical')
            ->where('grid.rows.data.0.cells.engagement_count.text', '1')
        );

        // And the filter agrees with both.
        $this->actingAs($this->user)
            ->get(route('tprm.third-parties.index').'?filters[tier]=critical')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('grid.rows.data', 1));
    }

    #[Test]
    public function a_terminated_engagement_stops_counting_towards_the_tier(): void
    {
        // The other half: a vendor whose only engagement ended last year is
        // not a current Critical exposure.
        $vendor = $this->makeThirdParty('Former Vendor', 'former-vendor');

        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-2026-7002',
            'name' => 'Retired service',
            'engagement_type' => 'ict_service',
            'status' => 'terminated',
        ]);
        $engagement->forceFill(['effective_tier' => 'critical'])->save();

        $this->actingAs($this->user)
            ->get(route('tprm.third-parties.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('grid.rows.data.0.cells.tier_rank.text', '—')
                ->where('grid.rows.data.0.cells.engagement_count.text', '0')
            );
    }

    #[Test]
    public function the_register_list_does_not_scale_its_query_count_with_its_rows(): void
    {
        // The phase acceptance asks for a query-count assertion proving no
        // N+1. Twenty vendors each with an engagement must cost the same
        // number of queries as five.
        foreach (range(1, 5) as $i) {
            $this->makeEngagement($this->makeThirdParty("Vendor {$i}", "vendor-{$i}"), RiskTier::High);
        }

        $baseline = $this->countQueriesFor(route('tprm.third-parties.index'));

        foreach (range(6, 20) as $i) {
            $this->makeEngagement($this->makeThirdParty("Vendor {$i}", "vendor-{$i}"), RiskTier::High);
        }

        $larger = $this->countQueriesFor(route('tprm.third-parties.index'));

        // NOT exact equality. The measured request includes work that has
        // nothing to do with the grid — the period lookup, the unread
        // notification count — and one of those can vary by a query between
        // runs, which would make an exact assertion flaky for a reason that is
        // not the defect being guarded against.
        //
        // The invariant that matters is that the count does not GROW with the
        // rows. An N+1 over fifteen extra vendors would add roughly fifteen
        // queries, so a tolerance of one catches it with room to spare.
        $this->assertLessThanOrEqual(
            $baseline + 1,
            $larger,
            "The register costs {$larger} queries for 20 rows and {$baseline} for 5. The count is scaling with the "
            .'row count, which means the list has an N+1.'
        );

        // And an absolute ceiling, so that a future change adding a per-row
        // query cannot pass merely by also making the 5-row case expensive.
        $this->assertLessThan(20, $larger, "The register costs {$larger} queries for one page of 20 rows.");
    }

    #[Test]
    public function the_engagement_register_renders_and_counts_untiered_rows(): void
    {
        $vendor = $this->makeThirdParty('Interlink Systems Limited', 'interlink');
        $this->makeEngagement($vendor, RiskTier::Critical);
        $this->makeEngagement($vendor, null, 'ENG-2026-9002');

        $this->actingAs($this->user)
            ->get(route('tprm.engagements.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Engagements/Index')
                ->where('summary.total', 2)
                ->where('summary.critical', 1)
                ->where('summary.untiered', 1)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The profile and the workspace                                      */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_third_party_profile_renders_its_engagements(): void
    {
        $vendor = $this->makeThirdParty('Interlink Systems Limited', 'interlink');
        $this->makeEngagement($vendor, RiskTier::High);

        $this->actingAs($this->user)
            ->get(route('tprm.third-parties.show', $vendor))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/ThirdParties/Show')
                ->where('thirdParty.legal_name', 'Interlink Systems Limited')
                ->has('engagements', 1)
                ->where('engagements.0.tier', 'high')
                // An unscored vendor has no residual. Null, so the page can
                // render "Not scored" rather than a fabricated 0.
                ->where('thirdParty.aggregate_residual', null)
            );
    }

    #[Test]
    public function the_engagement_workspace_renders_the_stored_derivation(): void
    {
        // AC-15's precondition: the panel reads one stored explanation rather
        // than recomputing, so two users see the same figures.
        $vendor = $this->makeThirdParty('Interlink Systems Limited', 'interlink');
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');

        $result = app(IntakeService::class)->submit(
            [
                'organization_id' => $this->organization->id,
                'third_party_id' => $vendor->id,
                'name' => 'Payroll processing',
                'engagement_type' => 'outsourcing',
            ],
            [$hr],
            $this->answers(['A5' => 'privileged']),
            $this->user->id,
        );

        $this->actingAs($this->user)
            ->get(route('tprm.engagements.show', $result->engagement))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Engagements/Show')
                ->where('engagement.effective_tier', 'critical')
                ->where('derivation.decided_by', 'knockout')
                ->has('derivation.knockouts_fired')
                ->has('derivation.inherent.factors', 7)
                ->has('inherentVersion')
                ->has('history', 1)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Intake                                                             */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_intake_wizard_offers_only_answers_the_validator_accepts(): void
    {
        // Standard §10: what the schema OFFERS must be a subset of what the
        // validator ACCEPTS. Both come from DefaultRuleset here, so this
        // asserts they have not been allowed to drift apart.
        $response = $this->actingAs($this->user)->get(route('tprm.intake.create'));

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tprm/Intake/Create')
            ->has('questionnaire.A1.options')
            ->has('options.businessFunctions')
        );

        $questionnaire = $response->viewData('page')['props']['questionnaire'];
        $factors = \App\Support\Tprm\DefaultRuleset::factors();

        foreach (['A1' => 'DATA', 'A3' => 'GEO', 'A5' => 'ACCESS', 'A12' => 'SUB', 'A14' => 'FIN'] as $code => $factor) {
            $offered = array_column($questionnaire[$code]['options'], 'value');
            $accepted = array_column($factors[$factor]['options'], 'value');

            $this->assertSame(
                [],
                array_diff($offered, $accepted),
                "The intake form offers answers to {$code} that the {$factor} factor does not define."
            );
        }
    }

    #[Test]
    public function the_wizard_marks_the_functions_that_may_not_be_outsourced(): void
    {
        // The client-side warning is a courtesy — the block is server-side —
        // but the flag has to reach the page or the requester finds out only
        // after filling in eighteen questions.
        $response = $this->actingAs($this->user)->get(route('tprm.intake.create'));

        $functions = collect($response->viewData('page')['props']['options']['businessFunctions']);
        $prohibited = $functions->where('is_prohibited_outsourcing', true);

        $this->assertCount(3, $prohibited);
        $this->assertNotNull($prohibited->first()['prohibition_citation']);
    }

    #[Test]
    public function the_live_preview_endpoint_returns_a_tier_and_writes_nothing(): void
    {
        $hr = BusinessFunction::where('function_code', 'BF-HR-01')->value('id');

        $response = $this->actingAs($this->user)->postJson(route('tprm.intake.preview'), [
            'business_function_ids' => [$hr],
            'answers' => $this->answers(['A5' => 'privileged']),
        ]);

        $response->assertOk()
            ->assertJsonPath('effective_tier', 'critical')
            ->assertJsonPath('raised_by_knockout', true)
            ->assertJsonStructure(['score', 'tier_from_score', 'knockouts', 'factors']);

        $this->assertSame(0, Engagement::count());
    }

    #[Test]
    public function submitting_an_intake_for_a_prohibited_function_returns_the_citation_inline(): void
    {
        // AC-01 through the HTTP layer: a flash the page renders, not a 403.
        // The user is permitted to raise intakes; what is refused is this
        // arrangement, and the difference matters to whoever reads it.
        $vendor = $this->makeThirdParty('Outsourcing Co', 'outsourcing-co');
        $audit = BusinessFunction::where('function_code', 'BF-AUD-01')->value('id');

        $this->actingAs($this->user)
            ->from(route('tprm.intake.create'))
            ->post(route('tprm.intake.store'), [
                'third_party_id' => $vendor->id,
                'name' => 'Internal audit outsourcing',
                'engagement_type' => 'outsourcing',
                'business_function_ids' => [$audit],
                'answers' => $this->answers(),
            ])
            ->assertRedirect(route('tprm.intake.create'))
            ->assertSessionHas('prohibitedFunctions', fn (array $functions) => $functions[0]['citation']
                === 'CBN Corporate Governance Guidelines 2023 §13.1, §3.6.2');

        $this->assertSame(0, Engagement::count());
    }

    #[Test]
    public function a_user_without_the_create_permission_cannot_reach_the_wizard(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('tprm-viewer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.view', 'web'));
        $viewer->assignRole($role);

        $this->actingAs($viewer)->get(route('tprm.third-parties.index'))->assertOk();
        $this->actingAs($viewer)->get(route('tprm.intake.create'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Queries for one render of $url.
     *
     * The first request in a process warms the permission cache and the
     * saved-view lookup, so it costs an order of magnitude more than every
     * request after it. Measuring a cold request against a warm one measures
     * the cache rather than the N+1, which is why the warm-up below is not
     * optional.
     */
    private function countQueriesFor(string $url): int
    {
        $this->actingAs($this->user)->get($url)->assertOk();

        $count = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($this->user)->get($url)->assertOk();

        \Illuminate\Support\Facades\DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $count;
    }

    /** Incremented per created engagement, so no two share a reference. */
    private int $engagementSequence = 0;

    private function makeThirdParty(string $name, string $slug): ThirdParty
    {
        return ThirdParty::create([
            'legal_name' => $name, 'slug' => $slug, 'entity_type' => 'company',
            'status' => 'active', 'country_of_incorporation' => 'NG',
        ]);
    }

    /**
     * References are SEQUENTIAL, not random.
     *
     * They were `random_int(1000, 8999)` until the full suite caught it: the
     * N+1 test creates twenty engagements in one run, and twenty draws from
     * eight thousand values collide about one time in forty by the birthday
     * bound. The unique index then failed the test for a reason that had
     * nothing to do with what it was guarding, and only in a full-suite run —
     * the worst kind of flake to chase.
     */
    private function makeEngagement(ThirdParty $vendor, ?RiskTier $tier, ?string $reference = null): Engagement
    {
        $engagement = Engagement::create([
            'third_party_id' => $vendor->id,
            'reference' => $reference ?? sprintf('ENG-2026-%04d', ++$this->engagementSequence),
            'name' => 'Managed service',
            'engagement_type' => 'ict_service',
            'status' => 'active',
        ]);

        if ($tier !== null) {
            $engagement->forceFill(['effective_tier' => $tier->value, 'inherent_tier' => $tier->value])->save();
        }

        return $engagement;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function answers(array $overrides = []): array
    {
        return $overrides + [
            'A1' => 'internal', 'A2' => '1k_100k', 'A3' => 'domestic', 'A5' => 'read_only',
            'A8' => 'over_72h', 'A10' => ['cbn_cyber'], 'A12' => 'many',
            'A13' => 'under_1m', 'A14' => 'under_10m',
        ];
    }
}
