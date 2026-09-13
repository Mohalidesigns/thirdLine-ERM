<?php

namespace Tests\Feature\Tprm;

use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\User;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The N+1 and the duplicate SELECT that `AiSettingsQueryCountTest` does not
 * cover — that test is `tprm.settings.ai` only. Gate 2 observed there was
 * NO query-count test on `tprm.settings.ai.usage` at all, which is how the
 * grid's missing `->with('user:id,name')` and any second `tp_settings` read
 * both got through undetected.
 *
 * The grid was fixed to eager-load the actor
 * (`TprmAiUsageEventsGrid::query()` now carries `->with('user:id,name')`) and
 * `AiUsageController` was fixed to read settings through
 * `$this->policy->settingsFor()` rather than a second direct `tp_settings`
 * read. This asserts both directly: the `users` SELECT count must stay FLAT
 * as the number of distinct actors on the page grows, and `tp_settings` must
 * be read at most once per render.
 */
class AiUsageReportQueryCountTest extends TestCase
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
            'name' => 'Sokoto Community Bank', 'short_name' => 'SCB',
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
            'name' => 'Usage Admin', 'email' => 'usageadmin@scb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('tprm-ai-usage-admin-qc', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.admin', 'web'));
        $this->admin->assignRole($role);

        // Ten rows, each attributed to a DIFFERENT user, so a query-per-row
        // N+1 on the `user` relation cannot hide behind a small, coincidental
        // constant — ten distinct actors makes "1 base query + N per-row
        // queries" visibly different from "flat".
        $usageMonth = now()->format('Y-m');

        for ($i = 1; $i <= 10; $i++) {
            $actor = User::create([
                'name' => "Caller {$i}", 'email' => "caller{$i}@scb.test",
                'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
                'organization_id' => $this->bank->id, 'is_active' => true,
            ]);

            LlmUsageEvent::create([
                'organization_id' => $this->bank->id,
                'module' => 'tprm',
                'service' => 'evidence_extraction',
                'prompt_key' => 'soc2',
                'prompt_version' => 'soc2.v2',
                'endpoint_profile' => 'local-ollama',
                'model' => 'granite4:micro',
                'outcome' => 'succeeded',
                'attempts' => 1,
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'duration_ms' => 1200,
                'usage_month' => $usageMonth,
                'user_id' => $actor->id,
                'created_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_users_select_count_stays_flat_as_row_count_grows_and_tp_settings_is_read_at_most_once(): void
    {
        $usersSelectCount = 0;
        $tpSettingsSelectCount = 0;

        DB::listen(function ($query) use (&$usersSelectCount, &$tpSettingsSelectCount) {
            $sql = trim($query->sql);

            if (! str_starts_with($sql, 'select')) {
                return;
            }

            if (str_contains($sql, '`users`')) {
                $usersSelectCount++;
            }

            if (str_contains($sql, '`tp_settings`')) {
                $tpSettingsSelectCount++;
            }
        });

        $this->actingAs($this->admin)->get(route('tprm.settings.ai.usage'))->assertOk();

        // One base query for the ten grid rows plus, AT MOST, one eager-load
        // query for the distinct users referenced by them
        // (`with('user:id,name')`). Ten separate per-row lookups (an N+1)
        // would put this at 10 or 11; a correctly eager-loaded page never
        // exceeds 2 regardless of how many distinct actors appear.
        $this->assertLessThanOrEqual(
            2,
            $usersSelectCount,
            "Expected the recent-calls grid to eager-load its actors (at most one extra SELECT against `users`), found {$usersSelectCount} SELECTs against `users` for 10 rows with 10 distinct actors — this is the N+1 Gate 2 found."
        );

        $this->assertLessThanOrEqual(
            1,
            $tpSettingsSelectCount,
            "AiUsageController::index() must read tp_settings at most once per render via TprmAiPolicy::settingsFor(), found {$tpSettingsSelectCount} SELECTs."
        );
    }
}
