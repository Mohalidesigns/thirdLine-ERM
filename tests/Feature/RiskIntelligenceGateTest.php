<?php

namespace Tests\Feature;

use App\Models\EmergingRisk;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Risk Intelligence screens must be unreachable unless the environment has
 * explicitly opted in, and the emerging risk register they read from must be
 * tenant-isolated.
 *
 * The gate matters more than usual here: these routes previously rendered
 * fabricated figures, and the flag is what guarantees no customer environment
 * can reach a half-evidenced screen by default.
 */
class RiskIntelligenceGateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->org = Organization::create([
            'name' => 'Gate Bank PLC',
            'short_name' => 'GATE',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $this->user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Chief Risk Officer',
            'email' => 'cro@gate.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->user->assignRole('chief-risk-officer');
    }

    public static function intelligenceRoutes(): array
    {
        return [
            'forecast' => ['risk.ai.predictive'],
            'radar' => ['risk.ai.radar'],
            'regulatory pulse' => ['risk.ai.regulatory-pulse'],
        ];
    }

    #[Test]
    #[DataProvider('intelligenceRoutes')]
    public function intelligence_routes_404_when_the_feature_flag_is_off(string $routeName): void
    {
        config(['features.ai_intelligence' => false]);

        $this->actingAs($this->user)
            ->get(route($routeName))
            ->assertNotFound();
    }

    #[Test]
    #[DataProvider('intelligenceRoutes')]
    public function intelligence_routes_render_when_the_feature_flag_is_on(string $routeName): void
    {
        config(['features.ai_intelligence' => true]);

        $this->actingAs($this->user)
            ->get(route($routeName))
            ->assertOk();
    }

    #[Test]
    public function the_feature_flag_defaults_to_off(): void
    {
        // Read the shipped config rather than the test-time override: the
        // point of the assertion is that a fresh environment is closed.
        $config = require config_path('features.php');

        $this->assertFalse(
            filter_var($config['ai_intelligence'], FILTER_VALIDATE_BOOLEAN),
            'features.ai_intelligence must default to false so no environment opens these screens by accident.'
        );
    }

    #[Test]
    public function the_benchmarking_route_no_longer_exists(): void
    {
        // Benchmarking was removed, not gated: its peer values were invented.
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('risk.ai.benchmarking'),
            'The Benchmarking screen must stay removed until genuine anonymised peer aggregates exist.'
        );
    }

    #[Test]
    public function the_emerging_risk_register_is_reachable_without_the_feature_flag(): void
    {
        config(['features.ai_intelligence' => false]);

        $this->actingAs($this->user)
            ->get(route('risk.emerging.index'))
            ->assertOk();
    }

    #[Test]
    public function an_emerging_risk_is_stored_with_a_generated_reference_and_the_current_tenant(): void
    {
        $response = $this->actingAs($this->user)->post(route('risk.emerging.store'), [
            'title' => 'Sudden FX policy reversal',
            'description' => 'Signals of an imminent change to the FX window.',
            'horizon' => '0-3m',
            'velocity_score' => 5,
            'proximity_score' => 4,
            'potential_impact' => 'Critical',
            'status' => 'monitoring',
            'source' => 'CBN policy watch',
        ]);

        $response->assertRedirect(route('risk.emerging.index'));

        $entry = EmergingRisk::where('organization_id', $this->org->id)->firstOrFail();

        $this->assertSame('Sudden FX policy reversal', $entry->title);
        $this->assertSame($this->org->id, $entry->organization_id);
        $this->assertSame($this->user->id, $entry->created_by);
        $this->assertMatchesRegularExpression('/^EMR-\d{4}-\d{4}$/', $entry->reference);
        $this->assertSame(20, $entry->radar_score);
    }

    #[Test]
    public function the_radar_only_plots_the_current_tenants_register(): void
    {
        config(['features.ai_intelligence' => true]);

        $other = Organization::create([
            'name' => 'Rival Bank PLC',
            'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        EmergingRisk::create([
            'organization_id' => $other->id,
            'reference' => 'EMR-2026-0001',
            'title' => 'Rival tenant horizon item',
            'horizon' => '0-3m',
            'velocity_score' => 5,
            'proximity_score' => 5,
            'potential_impact' => 'Critical',
            'status' => 'monitoring',
        ]);

        EmergingRisk::create([
            'organization_id' => $this->org->id,
            'reference' => 'EMR-2026-0001',
            'title' => 'Our own horizon item',
            'horizon' => '3-6m',
            'velocity_score' => 3,
            'proximity_score' => 3,
            'potential_impact' => 'Medium',
            'status' => 'monitoring',
        ]);

        $this->actingAs($this->user)
            ->get(route('risk.ai.radar'))
            ->assertOk()
            ->assertSee('Our own horizon item')
            ->assertDontSee('Rival tenant horizon item');
    }

    #[Test]
    public function an_emerging_risk_cannot_be_attached_to_another_tenants_category(): void
    {
        $other = Organization::create([
            'name' => 'Rival Bank PLC',
            'short_name' => 'RIVL',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        $foreignCategory = \App\Models\RiskCategory::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'code' => 'FGN',
            'name' => 'Foreign category',
        ]);

        $this->actingAs($this->user)
            ->post(route('risk.emerging.store'), [
                'title' => 'Cross-tenant attempt',
                'horizon' => '0-3m',
                'velocity_score' => 3,
                'proximity_score' => 3,
                'potential_impact' => 'Medium',
                'status' => 'monitoring',
                'category_id' => $foreignCategory->id,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertSame(0, EmergingRisk::where('organization_id', $this->org->id)->count());
    }
}
