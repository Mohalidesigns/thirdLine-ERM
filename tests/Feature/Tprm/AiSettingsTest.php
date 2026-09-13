<?php

namespace Tests\Feature\Tprm;

use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\TprmSetting;
use App\Models\User;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The AI settings screen — phase-11a-ai-contract.md §7.1-§7.2,
 * docs/tprm/screens/ai-settings.md.
 */
class AiSettingsTest extends TestCase
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
        config()->set('tprm.ai.services.evidence_extraction', true);
        config()->set('tprm.ai.implemented_services', ['evidence_extraction']);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        $this->bank = Organization::create([
            'name' => 'Enugu Provident Bank', 'short_name' => 'EPB',
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
            'name' => 'AI Admin', 'email' => 'admin@epb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-ai-admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.admin', 'web'));
        $this->admin->assignRole($role);

        // The settings screen calls LlmGateway::availability() once per
        // service, and its last layer is a real endpoint probe. Faked so
        // these tests are fast and deterministic regardless of whether a
        // real Ollama happens to be reachable on the machine running them.
        Http::fake(['*/api/tags' => Http::response(['models' => []], 200)]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_first_visit_shows_every_service_as_not_set(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tprm.settings.ai'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // `false`: the React component is frontend-engineer's to
                // build against this payload, not backend-engineer's — see
                // the phase brief. The component NAME is still asserted; only
                // the file-on-disk existence check is skipped.
                ->component('Tprm/Settings/Ai', false)
                ->where('tenant.ai_enabled', null)
                ->where('effective.enabled', true) // deployment on, tenant not-set follows it
                ->has('deployment.services', 7)
            );
    }

    #[Test]
    public function an_unimplemented_service_is_named_as_not_built(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tprm.settings.ai'))
            ->assertInertia(function (AssertableInertia $page) {
                $page->has('deployment.services');
            });

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai'));
        $services = $response->original->getData()['page']['props']['deployment']['services'];

        $scoping = collect($services)->firstWhere('key', 'scoping_assistant');
        $this->assertNotNull($scoping);
        $this->assertFalse($scoping['implemented']);
        $this->assertFalse($scoping['effective']);
    }

    #[Test]
    public function tenant_off_names_itself_as_the_reason_even_though_the_deployment_is_on(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill(['ai_enabled' => false])->save();

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai'));
        $props = $response->original->getData()['page']['props'];

        $this->assertFalse($props['effective']['enabled']);
        $service = collect($props['deployment']['services'])->firstWhere('key', 'evidence_extraction');
        $this->assertSame('tenant_master', $service['layer']);
        // effective.master_layer answers the same question the per-service
        // rows already do, from the same LlmGateway::availability() path —
        // contract §7.1, ruled 2026-09-11.
        $this->assertSame('tenant_master', $props['effective']['master_layer']);
    }

    #[Test]
    public function master_layer_is_null_when_the_master_switch_is_enabled(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill(['ai_enabled' => true])->save();

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai'));
        $props = $response->original->getData()['page']['props'];

        $this->assertTrue($props['effective']['enabled']);
        $this->assertNull($props['effective']['master_layer']);
    }

    #[Test]
    public function an_endpoint_profile_url_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), [
                'ai_endpoint_profile' => 'http://attacker.example.com',
            ])
            ->assertSessionHasErrors('ai_endpoint_profile');

        $this->assertNull(TprmSetting::forOrganization($this->bank->id)->refresh()->ai_endpoint_profile);
    }

    #[Test]
    public function a_configured_profile_key_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), ['ai_endpoint_profile' => 'local-ollama'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('local-ollama', TprmSetting::forOrganization($this->bank->id)->refresh()->ai_endpoint_profile);
    }

    #[Test]
    public function a_save_that_omits_ai_services_entirely_does_not_erase_previously_recorded_preferences(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill([
            'ai_services' => ['evidence_extraction' => true],
        ])->save();

        // Only the master switch is submitted — no `ai_services` key at all,
        // the shape a partial API caller or a stale retried request would
        // send. The stored per-service preference must survive.
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), ['ai_enabled' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['evidence_extraction' => true],
            TprmSetting::forOrganization($this->bank->id)->refresh()->ai_services,
        );
    }

    #[Test]
    public function saving_one_service_does_not_erase_another_already_recorded(): void
    {
        config()->set('tprm.ai.implemented_services', ['evidence_extraction', 'clause_analysis']);
        config()->set('tprm.ai.services.clause_analysis', true);

        TprmSetting::forOrganization($this->bank->id)->forceFill([
            'ai_services' => ['evidence_extraction' => true],
        ])->save();

        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), ['ai_services' => ['clause_analysis' => false]])
            ->assertSessionHasNoErrors();

        $stored = TprmSetting::forOrganization($this->bank->id)->refresh()->ai_services;

        $this->assertTrue($stored['evidence_extraction']);
        $this->assertFalse($stored['clause_analysis']);
    }

    /**
     * Gate 1 (TPRM Phase 11a), acceptance criterion 16, third clause — not
     * covered elsewhere in this file. A key sent back as `null` is a
     * decision ("stop following either the deployment or a previously
     * recorded true/false — go back to not decided"), not a no-op, and it
     * must record and resolve exactly as an ABSENT key does: `tp_settings`
     * stores the key with a `null` value rather than dropping it, and
     * `TprmAiPolicy::snapshot()` reads that the same way it reads a key
     * that was never set (§3.2, §7.2).
     */
    #[Test]
    public function a_service_key_sent_as_null_is_recorded_as_not_decided_and_resolves_like_an_absent_key(): void
    {
        config()->set('tprm.ai.services.evidence_extraction', true);

        TprmSetting::forOrganization($this->bank->id)->forceFill([
            'ai_enabled' => true,
            'ai_services' => ['evidence_extraction' => false],
        ])->save();

        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), ['ai_services' => ['evidence_extraction' => null]])
            ->assertSessionHasNoErrors();

        $stored = TprmSetting::forOrganization($this->bank->id)->refresh();

        // The key is present in the stored column, with a null value — not
        // dropped back to absent by the merge.
        $this->assertArrayHasKey('evidence_extraction', (array) $stored->ai_services);
        $this->assertNull($stored->ai_services['evidence_extraction']);

        // And it resolves identically to a tenant that never set this key
        // at all: deployment-enabled, tenant master on, nothing at the
        // per-service tenant layer to narrow it further.
        $snapshot = app(\App\Services\Tprm\Ai\TprmAiPolicy::class)
            ->snapshot($this->bank->id, 'evidence_extraction');

        $this->assertNull($snapshot->tenantServiceEnabled);

        $freshTenantSettings = TprmSetting::forOrganization($this->bank->id);
        $freshTenantSettings->forceFill(['ai_services' => []])->save();

        $snapshotForAbsentKey = app(\App\Services\Tprm\Ai\TprmAiPolicy::class)
            ->snapshot($this->bank->id, 'evidence_extraction');

        $this->assertSame($snapshot->tenantServiceEnabled, $snapshotForAbsentKey->tenantServiceEnabled);
    }

    #[Test]
    public function an_unimplemented_service_key_is_rejected_from_the_form(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), [
                'ai_services' => ['scoping_assistant' => true],
            ])
            ->assertSessionHasErrors('ai_services');
    }

    #[Test]
    public function saving_the_master_switch_on_is_recorded(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), ['ai_enabled' => true])
            ->assertSessionHasNoErrors();

        $settings = TprmSetting::forOrganization($this->bank->id)->refresh();
        $this->assertTrue($settings->ai_enabled);
        $this->assertSame($this->admin->id, $settings->updated_by);
    }

    #[Test]
    public function clearing_the_master_switch_back_to_not_set_is_a_legitimate_write(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill(['ai_enabled' => true])->save();

        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), ['ai_enabled' => null])
            ->assertSessionHasNoErrors();

        $this->assertNull(TprmSetting::forOrganization($this->bank->id)->refresh()->ai_enabled);
    }

    #[Test]
    public function every_settings_change_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->put(route('tprm.settings.ai.update'), ['ai_enabled' => true]);

        $this->assertDatabaseHas('tp_audit_logs', [
            'auditable_type' => TprmSetting::class,
            'event' => 'updated',
        ]);
    }

    #[Test]
    public function a_non_admin_cannot_reach_the_screen_or_change_settings(): void
    {
        $reporter = User::create([
            'name' => 'Reporter', 'email' => 'reporter@epb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-report-reader-ai', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.report.view', 'web'));
        $reporter->assignRole($role);

        $this->actingAs($reporter)->get(route('tprm.settings.ai'))->assertForbidden();
        $this->actingAs($reporter)->put(route('tprm.settings.ai.update'), ['ai_enabled' => true])->assertForbidden();
    }

    #[Test]
    public function with_ai_off_at_deployment_level_every_tprm_workflow_screen_still_loads(): void
    {
        // AC-16 / contract §8.1, exercised at the settings screen itself:
        // the screen that reports AI's own state must not fail when AI is off.
        config()->set('services.llm.enabled', false);

        $this->actingAs($this->admin)->get(route('tprm.settings.ai'))->assertOk();
        $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage'))->assertOk();
    }

    #[Test]
    public function the_usage_report_names_a_tenant_currently_off(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill(['ai_enabled' => false])->save();

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage'));
        $props = $response->original->getData()['page']['props'];

        $this->assertFalse($props['tenant_ai_currently_enabled']);
    }

    #[Test]
    public function the_usage_report_never_shows_a_cost_figure(): void
    {
        LlmUsageEvent::create([
            'organization_id' => $this->bank->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
            'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
            'model' => 'granite4:micro', 'outcome' => 'succeeded', 'attempts' => 1,
            'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150, 'duration_ms' => 500,
            'usage_month' => now()->format('Y-m'), 'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage'));
        $body = $response->getContent();

        $this->assertStringNotContainsString('₦', (string) $body);
        $this->assertStringNotContainsString('0.00', (string) $body);
    }

    #[Test]
    public function a_month_with_only_unreported_tokens_shows_null_not_zero(): void
    {
        LlmUsageEvent::create([
            'organization_id' => $this->bank->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
            'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
            'model' => 'granite4:micro', 'outcome' => 'succeeded', 'attempts' => 1,
            'prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null, 'duration_ms' => 500,
            'usage_month' => now()->format('Y-m'), 'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage'));
        $props = $response->original->getData()['page']['props'];

        $this->assertNull($props['month']['total_tokens']);
        $this->assertSame(1, $props['month']['call_count']);
    }

    #[Test]
    public function the_usage_report_carries_remaining_and_server_computed_tones(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill(['ai_monthly_token_cap' => 100])->save();

        LlmUsageEvent::create([
            'organization_id' => $this->bank->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
            'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
            'model' => 'granite4:micro', 'outcome' => 'succeeded', 'attempts' => 1,
            'prompt_tokens' => 60, 'completion_tokens' => 30, 'total_tokens' => 90, 'duration_ms' => 500,
            'usage_month' => now()->format('Y-m'), 'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage'));
        $props = $response->original->getData()['page']['props'];

        // §7.1's frozen field list already named `month.remaining` — it was
        // simply never sent (ruled 2026-09-11, frontend deviation 2).
        $this->assertSame(10, $props['month']['remaining']['tokens']);
        $this->assertNull($props['month']['remaining']['calls'], 'No call cap was set, so calls-remaining is uncapped, not zero.');
        // 90 of 100 is 90% consumed -> warn, computed server-side.
        $this->assertSame('warn', $props['month']['token_cap_tone']);
        // No call cap set at all -> no tone, never a fabricated neutral string.
        $this->assertNull($props['month']['call_cap_tone']);
    }

    #[Test]
    public function an_out_of_range_month_is_declared_not_silently_substituted(): void
    {
        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage', ['month' => '2001-01']));
        $props = $response->original->getData()['page']['props'];

        // The panels show the current month (the substitution is correct —
        // §7.3), but the screen must be told what was actually asked for.
        $this->assertSame('2001-01', $props['requested_month']);
        $this->assertNotSame('2001-01', $props['month']['usage_month']);
    }

    #[Test]
    public function a_valid_month_reports_no_requested_month_substitution(): void
    {
        $currentMonth = now()->format('Y-m');

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage', ['month' => $currentMonth]));
        $props = $response->original->getData()['page']['props'];

        $this->assertNull($props['requested_month']);
    }

    #[Test]
    public function by_outcome_rows_carry_their_share_of_the_months_calls(): void
    {
        $month = now()->format('Y-m');

        foreach (['succeeded', 'succeeded', 'succeeded', 'refused'] as $outcome) {
            LlmUsageEvent::create([
                'organization_id' => $this->bank->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
                'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
                'model' => 'granite4:micro', 'outcome' => $outcome, 'attempts' => 1,
                'duration_ms' => 500, 'usage_month' => $month, 'created_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage'));
        $props = $response->original->getData()['page']['props'];

        $succeeded = collect($props['byOutcome'])->firstWhere('outcome', 'succeeded');
        $refused = collect($props['byOutcome'])->firstWhere('outcome', 'refused');

        $this->assertSame(0.75, $succeeded['share']);
        $this->assertSame(0.25, $refused['share']);
    }
}
