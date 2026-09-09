<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\FindingSeverity;
use App\Enums\Tprm\FindingStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\EngagementFunction;
use App\Models\Tprm\Finding;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Widgets\ResolvedPeriods;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetQueryEngine;
use App\Services\Widgets\WidgetScope;
use App\Services\Widgets\WidgetSourceRegistry;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Database\Seeders\Tprm\TprmWidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * FR-RPT-08 — the third-party widgets, and the join that puts them on an org
 * node.
 *
 * TPRM TABLES CARRY NO `node_id`, WHICH IS THE WHOLE REASON THIS TEST EXISTS.
 * An engagement supports business FUNCTIONS, and a payments switch serves
 * treasury, operations and the branch network at once; denormalising one node
 * onto `tp_engagements` would have to pick one of them, and picking wrongly is
 * how a business unit stops seeing the vendor it depends on. So scope is
 * resolved through `tp_engagement_functions` — and the assertions below are
 * about which engagements that join includes and, more importantly, which it
 * must not silently drop.
 */
class TprmWidgetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $user;

    private BusinessUnit $treasury;

    private BusinessUnit $operations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Uyo Commercial Bank', 'short_name' => 'UCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->treasury = BusinessUnit::create([
            'organization_id' => $this->bank->id, 'name' => 'Treasury', 'code' => 'TRE', 'is_active' => true,
        ]);
        $this->operations = BusinessUnit::create([
            'organization_id' => $this->bank->id, 'name' => 'Operations', 'code' => 'OPS', 'is_active' => true,
        ]);

        $this->user = User::create([
            'name' => 'Analyst', 'email' => 'analyst@ucb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  The registry */
    /* ================================================================== */

    #[Test]
    public function every_tprm_source_scopes_through_the_engagement_join(): void
    {
        $registry = app(WidgetSourceRegistry::class);

        $expected = [
            'tprm_engagements' => 'engagement_functions',
            'tprm_findings' => 'engagement_functions',
            'tprm_contracts' => 'engagement_functions',
            'tprm_evidence' => 'engagement_functions',
            // An incident names a provider, not one engagement.
            'tprm_incidents' => 'third_party_engagements',
        ];

        foreach ($expected as $key => $kind) {
            $source = $registry->source($key);

            $this->assertNotNull($source, "Source [{$key}] is not registered.");
            $this->assertSame($kind, $source['node_column_kind'], "Source [{$key}] scopes the wrong way.");
            // No tp_ table has node_id; a source claiming one would fail at
            // query time rather than here, on somebody's HQ page.
            $this->assertNotSame('node_id', $source['node_column']);
        }
    }

    /* ================================================================== */
    /*  The join */
    /* ================================================================== */

    #[Test]
    public function an_engagement_is_in_scope_through_the_function_it_supports(): void
    {
        $treasuryEngagement = $this->engagement('ENG-TRE');
        $this->linkToFunction($treasuryEngagement, $this->businessFunction('BF-TRE', $this->treasury));

        $opsEngagement = $this->engagement('ENG-OPS');
        $this->linkToFunction($opsEngagement, $this->businessFunction('BF-OPS', $this->operations));

        $references = $this->scopedReferences($this->nodeFor($this->treasury));

        $this->assertSame(['ENG-TRE'], $references);
    }

    #[Test]
    public function an_engagement_with_no_function_link_falls_back_to_its_own_business_unit(): void
    {
        // Without the second limb this row would appear in the unscoped
        // register and vanish the moment somebody navigated into their own
        // unit — a row that silently disappears is worse than one that is
        // plainly unattributed.
        $engagement = $this->engagement('ENG-DIRECT');
        $engagement->forceFill(['business_unit_id' => $this->treasury->id])->save();

        $this->assertSame(['ENG-DIRECT'], $this->scopedReferences($this->nodeFor($this->treasury)));
    }

    #[Test]
    public function an_engagement_serving_two_units_is_in_scope_on_both(): void
    {
        $switch = $this->engagement('ENG-SWITCH');
        $this->linkToFunction($switch, $this->businessFunction('BF-TRE', $this->treasury));
        $this->linkToFunction($switch, $this->businessFunction('BF-OPS', $this->operations));

        // The reason there is no `node_id` column: picking one of these would
        // stop the other unit seeing the vendor it depends on.
        $this->assertSame(['ENG-SWITCH'], $this->scopedReferences($this->nodeFor($this->treasury)));
        $this->assertSame(['ENG-SWITCH'], $this->scopedReferences($this->nodeFor($this->operations)));
    }

    #[Test]
    public function an_unattributable_engagement_is_out_of_scope_on_every_node(): void
    {
        // No function link and no business unit. It is genuinely unattributed,
        // and the register shows that gap rather than this query hiding it.
        $this->engagement('ENG-ORPHAN');

        $this->assertSame([], $this->scopedReferences($this->nodeFor($this->treasury)));
        $this->assertSame([], $this->scopedReferences($this->nodeFor($this->operations)));
    }

    #[Test]
    public function a_finding_is_scoped_by_the_engagement_it_was_raised_against(): void
    {
        $engagement = $this->engagement('ENG-TRE');
        $this->linkToFunction($engagement, $this->businessFunction('BF-TRE', $this->treasury));

        $other = $this->engagement('ENG-OPS');
        $this->linkToFunction($other, $this->businessFunction('BF-OPS', $this->operations));

        $this->finding($engagement, 'FND-TRE');
        $this->finding($other, 'FND-OPS');

        $references = $this->scopedReferences(
            $this->nodeFor($this->treasury),
            'tprm_findings',
            'wg-tprm-findings-ageing',
        );

        $this->assertSame(['FND-TRE'], $references);
    }

    #[Test]
    public function an_unscoped_widget_sees_everything(): void
    {
        $this->engagement('ENG-A');
        $this->engagement('ENG-B');

        // WidgetScope::unrestricted() is the portfolio view — no node page,
        // no join.
        $engine = app(WidgetQueryEngine::class);

        $count = $engine->baseQuery(
            $this->definition('wg-tprm-tier-distribution'),
            new WidgetContext($this->user),
            WidgetScope::unrestricted(),
            new ResolvedPeriods(null),
        )->count();

        $this->assertSame(2, $count);
    }

    /* ================================================================== */
    /*  The shipped definitions */
    /* ================================================================== */

    #[Test]
    public function the_seeder_ships_nine_system_widgets(): void
    {
        $this->seed(TprmWidgetSeeder::class);

        $widgets = WidgetDefinition::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('code', 'like', 'wg-tprm-%')
            ->get();

        $this->assertCount(9, $widgets);

        // Nine ship, but they are not exactly the nine the prompt names.
        // CONCENTRATION GAUGE IS NOT AMONG THEM: the gauge resolver reads its
        // bands from a MEASURE's own threshold row, and the concentration
        // index becomes a measure only once a tenant adopts the TPRM KRIs
        // (TPRM-06). Seeding a system gauge with no measure would put a
        // permanently empty widget in every tenant's library and call it
        // delivered. An overdue-findings tile ships in its place.
        $this->assertSame([
            'wg-tprm-assessments-overdue',
            'wg-tprm-clock-status',
            'wg-tprm-evidence-expiring',
            'wg-tprm-exit-readiness',
            'wg-tprm-findings-ageing',
            'wg-tprm-findings-overdue',
            'wg-tprm-residual-bands',
            'wg-tprm-tier-distribution',
            'wg-tprm-top-exposures',
        ], $widgets->pluck('code')->sort()->values()->all());

        foreach ($widgets as $widget) {
            $this->assertTrue((bool) $widget->is_system);
            $this->assertNotEmpty($widget->description, "{$widget->code} ships with no description.");

            $source = $widget->queryConfig('source');
            $this->assertStringStartsWith('tprm_', (string) $source, "{$widget->code} reads a non-TPRM source.");
        }
    }

    #[Test]
    public function seeding_twice_does_not_duplicate(): void
    {
        $this->seed(TprmWidgetSeeder::class);
        $this->seed(TprmWidgetSeeder::class);

        $this->assertSame(9, WidgetDefinition::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->where('code', 'like', 'wg-tprm-%')
            ->count());
    }

    #[Test]
    public function every_column_a_widget_names_is_on_its_sources_whitelist(): void
    {
        $this->seed(TprmWidgetSeeder::class);

        $registry = app(WidgetSourceRegistry::class);

        foreach (WidgetDefinition::withoutGlobalScopes()->where('code', 'like', 'wg-tprm-%')->get() as $widget) {
            $source = (string) $widget->queryConfig('source');

            foreach ((array) $widget->queryConfig('columns', []) as $column) {
                $this->assertTrue(
                    $registry->allowsColumn($source, $column),
                    "{$widget->code} names column [{$column}], which [{$source}] does not allow."
                );
            }

            foreach ((array) $widget->queryConfig('filters', []) as $filter) {
                $this->assertTrue(
                    $registry->allowsColumn($source, $filter['field']),
                    "{$widget->code} filters on [{$filter['field']}], which [{$source}] does not allow."
                );
            }

            if ($group = $widget->queryConfig('group_by')) {
                $this->assertTrue(
                    $registry->allowsColumn($source, $group),
                    "{$widget->code} groups by [{$group}], which [{$source}] does not allow."
                );
            }
        }
    }

    #[Test]
    public function the_seeders_live_status_list_matches_the_enum(): void
    {
        $fromEnum = array_values(array_map(
            fn (EngagementStatus $status) => $status->value,
            array_filter(EngagementStatus::cases(), fn (EngagementStatus $status) => $status->isLive()),
        ));

        // The first version of this constant listed ten statuses including
        // `due_diligence` and `onboarding`, which isLive() excludes — so every
        // tier widget would have counted engagements the register does not,
        // and the dashboard and the register would have disagreed with nothing
        // to explain why. A widget definition is JSON and cannot call an enum,
        // so this test is the only thing holding them together.
        $this->assertSame($fromEnum, TprmWidgetSeeder::LIVE_STATUSES);
    }

    #[Test]
    public function the_seeders_closed_finding_list_matches_the_enum(): void
    {
        $closed = array_values(array_map(
            fn (FindingStatus $status) => $status->value,
            array_filter(FindingStatus::cases(), fn (FindingStatus $status) => ! $status->isOpen()),
        ));

        $this->assertSame($closed, TprmWidgetSeeder::CLOSED_FINDINGS);
    }

    /* ================================================================== */

    /**
     * @return list<string>
     */
    private function scopedReferences(
        int $nodeId,
        string $source = 'tprm_engagements',
        string $code = 'wg-tprm-tier-distribution',
    ): array {
        $engine = app(WidgetQueryEngine::class);

        $rows = $engine->baseQuery(
            $this->definition($code, $source),
            new WidgetContext($this->user),
            new WidgetScope([$nodeId]),
            new ResolvedPeriods(null),
        )->get();

        return $rows->pluck('reference')->sort()->values()->all();
    }

    private function definition(string $code, string $source = 'tprm_engagements'): WidgetDefinition
    {
        return new WidgetDefinition([
            'code' => $code,
            'name' => $code,
            'widget_type' => 'kpi_tile',
            'query' => ['source' => $source],
        ]);
    }

    /**
     * The graph node id for a business unit — the `objects` row the sync
     * service writes on save.
     */
    private function nodeFor(BusinessUnit $unit): int
    {
        $id = DB::table('objects')
            ->where('source_model_type', 'business_unit')
            ->where('source_model_id', $unit->getKey())
            ->value('id');

        $this->assertNotNull($id, "Business unit {$unit->code} has no graph object; the sync did not run.");

        return (int) $id;
    }

    private function businessFunction(string $code, BusinessUnit $unit): BusinessFunction
    {
        return BusinessFunction::create([
            'organization_id' => $this->bank->id,
            'function_code' => $code,
            'name' => $code.' function',
            'owning_business_unit_id' => $unit->getKey(),
            'criticality' => 'critical',
        ]);
    }

    private function linkToFunction(Engagement $engagement, BusinessFunction $function): void
    {
        EngagementFunction::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->getKey(),
            'business_function_id' => $function->getKey(),
            'dependency_level' => 'primary',
            'reliance_level' => 'high',
        ]);
    }

    private function engagement(string $reference): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => $reference.' provider',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => 'Service for '.$reference,
            'engagement_type' => 'ict_service',
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
            'effective_tier' => RiskTier::High->value,
        ])->save();

        return $engagement->refresh();
    }

    private function finding(Engagement $engagement, string $reference): Finding
    {
        return Finding::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->getKey(),
            'third_party_id' => $engagement->third_party_id,
            'source' => 'assessment',
            'reference' => $reference,
            'title' => 'Gap on '.$reference,
            'severity' => FindingSeverity::High->value,
            'identified_at' => now()->subDays(30),
        ]);
    }
}
