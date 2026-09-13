<?php

namespace Tests\Feature\Tprm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\TprmSetting;
use App\Services\Llm\LlmGateway;
use App\Services\Tprm\Ai\TprmAiPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 1 restart cycle — the `scoped()` binding for `TprmAiPolicy` (Gate 2
 * fix, phase 11a) was "reasoned, not observed" by the implementing engineer:
 * plausible that `QueueServiceProvider::forgetScopedInstances()` gives a
 * persistent worker a fresh policy per job, but nobody had driven two tenants
 * through one resolved instance and checked. This does.
 *
 * Also confirms `LlmGateway` remains bound TRANSIENT — a singleton there
 * would leak one tenant's resolved endpoint/breaker state into the next
 * tenant's call in a persistent worker, and the per-instance
 * snapshot/probe caches inside it (Gate 2 defect 4's fix) are only safe
 * because it is not shared.
 */
class TprmAiPolicyBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function makeOrg(string $name): Organization
    {
        $org = Organization::create([
            'name' => $name, 'short_name' => strtoupper(substr($name, 0, 3)),
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $org->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        return $org;
    }

    #[Test]
    public function tprm_ai_policy_is_shared_within_one_resolution_scope_but_not_forever(): void
    {
        $first = app(TprmAiPolicy::class);
        $second = app(TprmAiPolicy::class);

        // scoped(), not bind(): two resolutions inside the same request/job
        // must be the SAME instance, or the memoisation that collapses nine
        // `tp_settings` SELECTs into one (Gate 2 defect 4) does nothing.
        $this->assertSame($first, $second, 'TprmAiPolicy must be shared within one request/job (scoped, not transient).');

        // scoped(), not singleton(): the container's own scoped-instance
        // flush (what a queue worker performs between jobs via
        // QueueServiceProvider) must actually drop it. If this were bound
        // singleton() this assertion would fail, because a singleton
        // survives forgetScopedInstances().
        $this->app->forgetScopedInstances();

        $third = app(TprmAiPolicy::class);
        $this->assertNotSame($first, $third, 'TprmAiPolicy must be dropped by forgetScopedInstances(), i.e. bound scoped(), not singleton().');
    }

    #[Test]
    public function llm_gateway_is_bound_transient_not_shared(): void
    {
        $first = app(LlmGateway::class);
        $second = app(LlmGateway::class);

        $this->assertNotSame($first, $second, 'LlmGateway must be transient: two resolutions in the same request must not be the same instance.');
    }

    #[Test]
    public function two_tenants_resolved_through_the_same_scoped_policy_instance_do_not_see_each_others_settings(): void
    {
        // Simulates the shape of risk the engineer flagged: a persistent
        // worker resolves ONE TprmAiPolicy instance and then serves two
        // different tenants' jobs against it without an intervening
        // forgetScopedInstances() call (a bug in the worker/job wiring, not
        // in this class — but this class is the one that would silently
        // paper over it with a stale cache if its cache key were wrong).
        $bankA = $this->makeOrg('Bank Alpha Nine');
        $bankB = $this->makeOrg('Bank Beta Nine');

        TenantContext::set($bankA->id);
        TprmSetting::forOrganization($bankA->id)->forceFill([
            'ai_enabled' => false,
            'ai_endpoint_profile' => 'alpha-profile',
        ])->save();

        TenantContext::set($bankB->id);
        TprmSetting::forOrganization($bankB->id)->forceFill([
            'ai_enabled' => true,
            'ai_endpoint_profile' => 'beta-profile',
        ])->save();

        // ONE policy instance, deliberately not re-resolved between reads —
        // this is the scenario a correct per-organization cache key must
        // survive. `TenantContext` is switched alongside `organizationId` on
        // each call, matching how every real caller invokes the gateway
        // (`TenantContext` is always set to the same tenant as the
        // `LlmCall::organizationId` being processed) — `TprmSetting` carries
        // `BelongsToOrganization`'s own global scope on top of the explicit
        // `organization_id` filter in `TprmSetting::forOrganization()`, so a
        // mismatched TenantContext is a distinct, pre-existing hazard of that
        // trait and not what this test is targeting.
        $policy = app(TprmAiPolicy::class);

        TenantContext::set($bankA->id);
        $snapshotA = $policy->snapshot($bankA->id, 'evidence_extraction');

        TenantContext::set($bankB->id);
        $snapshotB = $policy->snapshot($bankB->id, 'evidence_extraction');

        $this->assertFalse($snapshotA->tenantMasterEnabled);
        $this->assertSame('alpha-profile', $snapshotA->endpointProfileKey);

        $this->assertTrue($snapshotB->tenantMasterEnabled);
        $this->assertSame('beta-profile', $snapshotB->endpointProfileKey);

        // Re-reading A through the SAME instance must still be A, not B —
        // proof the cache key is per-organization, not a single slot the
        // second tenant's read overwrote.
        TenantContext::set($bankA->id);
        $snapshotAAgain = $policy->snapshot($bankA->id, 'evidence_extraction');
        $this->assertFalse($snapshotAAgain->tenantMasterEnabled);
        $this->assertSame('alpha-profile', $snapshotAAgain->endpointProfileKey);
    }
}
