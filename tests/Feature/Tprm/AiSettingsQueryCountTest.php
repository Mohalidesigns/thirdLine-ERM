<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\User;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 1 restart cycle — blocking defect 4: the settings screen issued nine
 * identical `tp_settings` SELECTs and three serial 3-second probes per
 * render. "It seems faster" is not a test; this asserts the actual counts.
 */
class AiSettingsQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('services.llm.enabled', true);
        config()->set('tprm.ai.enabled', true);
        // Multiple implemented+deployment-enabled services, so the
        // controller's per-service loop actually runs several iterations —
        // a single-service fixture could not distinguish "resolved once" from
        // "resolved once per service, coincidentally the same number".
        config()->set('tprm.ai.services.evidence_extraction', true);
        config()->set('tprm.ai.services.clause_analysis', true);
        config()->set('tprm.ai.services.narrative_generation', true);
        config()->set('tprm.ai.implemented_services', ['evidence_extraction', 'clause_analysis', 'narrative_generation']);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        $this->bank = Organization::create([
            'name' => 'Ilorin Community Bank', 'short_name' => 'ICB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->admin = User::create([
            'name' => 'AI Admin', 'email' => 'admin@icb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-ai-admin-qc', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.admin', 'web'));
        $this->admin->assignRole($role);

        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_settings_screen_reads_tp_settings_exactly_once(): void
    {
        $queriesAgainstTpSettings = 0;

        DB::listen(function ($query) use (&$queriesAgainstTpSettings) {
            if (str_contains($query->sql, '`tp_settings`') && str_starts_with(trim($query->sql), 'select')) {
                $queriesAgainstTpSettings++;
            }
        });

        $this->actingAs($this->admin)->get(route('tprm.settings.ai'))->assertOk();

        $this->assertSame(
            1,
            $queriesAgainstTpSettings,
            'AiSettingsController::edit() must read tp_settings exactly once per render, memoised across every '.
            'availability() call and masterEnabled() through the shared scoped TprmAiPolicy instance — found '.
            $queriesAgainstTpSettings.' SELECTs instead.'
        );
    }

    #[Test]
    public function the_settings_screen_probes_each_distinct_endpoint_exactly_once_regardless_of_service_count(): void
    {
        $this->actingAs($this->admin)->get(route('tprm.settings.ai'))->assertOk();

        // Three implemented+enabled services all resolve to the SAME single
        // configured profile ("local-ollama"), so a correct render probes
        // that one endpoint exactly once — not once per service (which would
        // be 3 serial network round trips here, and 7 in the real 7-service
        // deployment Gate 2 found).
        Http::assertSentCount(1);
    }
}
