<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\BusinessUnit;
use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\BusinessFunction;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\EngagementFunction;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Models\WidgetDefinition;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 1 (TPRM Phase 10), defect 6 — acceptance item 8/10, "widgets publish
 * and render in Business HQ against an org node", had no test exercising
 * either half of that sentence. `TprmWidgetTest` proves the scoping join
 * through `WidgetQueryEngine::baseQuery()` directly; nothing published a
 * TPRM widget on a real `Dashboard`, resolved it through `DashboardResolver`,
 * and rendered it on an actual `/hq/{node}` page — the path a bank's board
 * member actually uses.
 *
 * This is also the acceptance-level twin of defect 2's fix: the widget here
 * is node-scoped (`context_binding = inherit_node`) through the exact
 * `engagement_functions` join `WidgetQueryEngine::engagementsUnderNodes()`
 * resolves, so a regression in either the join or its tenant guard would
 * show up as a wrong count on a real page, not only in a unit-level test.
 */
class TprmWidgetHqRenderTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $viewer;

    private BusinessUnit $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Ibadan Merchant Bank', 'short_name' => 'IMB',
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

        $this->viewer = User::create([
            'name' => 'Board Member', 'email' => 'board@imb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('tprm-hq-viewer', 'web');
        foreach (['hq.view', 'tprm.view'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->viewer->assignRole($role);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function a_tprm_widget_publishes_and_renders_its_real_count_on_the_units_hq_page(): void
    {
        // In scope for Treasury: linked through the function it supports.
        $inScope = $this->engagement('ENG-IN-SCOPE');
        $function = BusinessFunction::create([
            'organization_id' => $this->bank->id,
            'function_code' => 'BF-TRE', 'name' => 'Treasury dealing',
            'owning_business_unit_id' => $this->treasury->id, 'criticality' => 'critical',
        ]);
        EngagementFunction::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $inScope->getKey(), 'business_function_id' => $function->getKey(),
            'dependency_level' => 'primary', 'reliance_level' => 'high',
        ]);

        // Out of scope for Treasury: a real, live engagement that supports
        // nothing at Treasury. If the widget's count included this, the join
        // would be wrong in exactly the direction that leaks another unit's
        // vendor into a committee's own page.
        $this->engagement('ENG-OUT-OF-SCOPE');

        $widget = WidgetDefinition::withoutGlobalScopes()->create([
            'organization_id' => null,
            'code' => 'wg-tprm-hq-render-test',
            'name' => 'Third-party engagements',
            'widget_type' => 'kpi_tile',
            'query' => ['source' => 'tprm_engagements', 'aggregate' => ['fn' => 'count']],
            'context_binding' => 'inherit_node',
            'period_binding' => 'selected',
            'is_system' => true,
        ]);

        $treasuryNode = $this->treasury->graphObject();

        Dashboard::withoutGlobalScopes()->create([
            'organization_id' => $this->bank->id,
            'code' => 'tprm-business-unit-hq',
            'name' => 'Business Unit Third-Party Exposure',
            'object_type_id' => $treasuryNode->object_type_id,
            'role_ids' => null,
            'tabs' => [['code' => 'main', 'label' => 'Third parties', 'layout' => [
                ['widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'overrides' => []],
            ]]],
            'is_published' => true,
        ]);

        $response = $this->actingAs($this->viewer)->get('/hq/'.$treasuryNode->id);

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hq/Show')
            ->where('dashboard.name', 'Business Unit Third-Party Exposure')
            ->has('payloads', 1)
            ->where('payloads.0.data.value', 1)
            ->where('payloads.0.state', 'ok'));
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
}
