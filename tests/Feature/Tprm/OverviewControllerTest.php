<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
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
 * The TPRM front door's tier tile — Gate 1 (TPRM Phase 10), defect 4.
 *
 * `tierDistribution()` had no test at all before this: it ran on every
 * `/tprm` page view and used to `->get()` every engagement ever created, then
 * filter to live status and bucket by tier in PHP. This asserts the bucketing
 * itself is still correct now that both are pushed into SQL — a draft or
 * terminated engagement must not inflate a tier count, and an untiered live
 * engagement must land in its own bucket rather than vanishing or being
 * folded into Low.
 */
class OverviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Sokoto Trust Bank', 'short_name' => 'STB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@stb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('tprm-overview-viewer', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.view', 'web'));
        $this->viewer->assignRole($role);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_tile_counts_only_live_engagements_by_tier(): void
    {
        $this->engagement('ENG-HIGH', EngagementStatus::Active, RiskTier::High);
        $this->engagement('ENG-HIGH-2', EngagementStatus::MonitoringException, RiskTier::High);
        $this->engagement('ENG-LOW', EngagementStatus::Active, RiskTier::Low);
        $this->engagement('ENG-UNTIERED', EngagementStatus::Active, null);
        // Not live: must not appear in any bucket.
        $this->engagement('ENG-DRAFT', EngagementStatus::Draft, RiskTier::Critical);
        $this->engagement('ENG-TERMINATED', EngagementStatus::Terminated, RiskTier::Critical);

        $response = $this->actingAs($this->viewer)->get(route('tprm.overview'));
        $response->assertOk();

        $tiers = collect($response->original->getData()['page']['props']['tiers']);

        $this->assertSame(2, $tiers->firstWhere('tier', 'high')['count']);
        $this->assertSame(1, $tiers->firstWhere('tier', 'low')['count']);
        $this->assertSame(0, $tiers->firstWhere('tier', 'critical')['count'], 'A draft or terminated engagement was counted.');
        $this->assertSame(1, $tiers->firstWhere('tier', null)['count']);
    }

    #[Test]
    public function an_untiered_bucket_is_not_folded_into_low_when_there_are_no_untiered_engagements(): void
    {
        $this->engagement('ENG-LOW', EngagementStatus::Active, RiskTier::Low);

        $response = $this->actingAs($this->viewer)->get(route('tprm.overview'));
        $tiers = collect($response->original->getData()['page']['props']['tiers']);

        $this->assertSame(1, $tiers->firstWhere('tier', 'low')['count']);
        $this->assertSame(0, $tiers->firstWhere('tier', null)['count']);
    }

    #[Test]
    public function the_overview_renders_with_no_engagements_at_all(): void
    {
        $this->actingAs($this->viewer)
            ->get(route('tprm.overview'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Tprm/Overview', false));
    }

    private function engagement(string $reference, EngagementStatus $status, ?RiskTier $tier): Engagement
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
            'status' => $status->value,
            'effective_tier' => $tier?->value,
        ])->save();

        return $engagement->refresh();
    }
}
