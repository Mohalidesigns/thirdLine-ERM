<?php

namespace Tests\Feature\Tprm;

use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * phase-11a-ai-contract.md §8.13 — the cross-tenant probe: tenant A's usage
 * report contains no row of tenant B's.
 */
class AiUsageReportCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenant_as_report_contains_none_of_tenant_bs_rows(): void
    {
        config()->set('features.tprm', true);

        $bankA = Organization::create([
            'name' => 'Bank A', 'short_name' => 'BKA',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $bankB = Organization::create([
            'name' => 'Bank B', 'short_name' => 'BKB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        foreach ([$bankA, $bankB] as $bank) {
            RiskCategory::create([
                'organization_id' => $bank->id,
                'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
            ]);
        }

        $month = now()->format('Y-m');

        LlmUsageEvent::create([
            'organization_id' => $bankA->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
            'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
            'model' => 'granite4:micro', 'outcome' => 'succeeded', 'attempts' => 1,
            'prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150, 'duration_ms' => 500,
            'usage_month' => $month, 'created_at' => now(),
        ]);
        LlmUsageEvent::create([
            'organization_id' => $bankB->id, 'module' => 'tprm', 'service' => 'evidence_extraction',
            'prompt_key' => 'soc2', 'prompt_version' => 'soc2.v2', 'endpoint_profile' => 'local-ollama',
            'model' => 'granite4:micro', 'outcome' => 'succeeded', 'attempts' => 1,
            'prompt_tokens' => 999, 'completion_tokens' => 999, 'total_tokens' => 1998, 'duration_ms' => 500,
            'usage_month' => $month, 'created_at' => now(),
        ]);

        TenantContext::set($bankA->id);
        $adminA = User::create([
            'name' => 'Admin A', 'email' => 'admin@bka.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $bankA->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('tprm-ai-admin-a', 'web');
        $role->givePermissionTo(Permission::findOrCreate('tprm.admin', 'web'));
        $adminA->assignRole($role);

        $response = $this->actingAs($adminA)->get(route('tprm.settings.ai.usage'));
        $props = $response->original->getData()['page']['props'];

        // Tenant A's own total is 150, never 1998 or 2148 (150+1998) —
        // no aggregate anywhere on this screen may include tenant B's rows.
        $this->assertSame(150, $props['month']['total_tokens']);
        $this->assertSame(1, $props['month']['call_count']);

        TenantContext::clear();
    }
}
