<?php

namespace Tests\Feature\Llm;

use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\TprmSetting;
use App\Services\Llm\CircuitBreaker;
use App\Services\Llm\LlmCall;
use App\Services\Llm\LlmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Gate 1 restart cycle — blocking defect 1: `LlmGateway::attemptCall()`
 * resolved `$call->endpointProfile` (always null on every real caller) and
 * discarded `$snapshot->endpointProfileKey`, so a tenant's chosen endpoint
 * profile was never actually used for the call, the usage row or the breaker
 * key.
 *
 * `config/llm.php` SHIPS EXACTLY ONE PROFILE, which is precisely why this
 * defect survived the prior Gate 1: with a single profile, "the default" and
 * "the tenant's choice" are the same string and no assertion here could ever
 * tell right from wrong. This test configures a SECOND profile and stores it
 * as the tenant's choice, so a regression back to reading
 * `$call->endpointProfile` (always null) instead of the snapshot would
 * silently fall back to the default profile — and every assertion below
 * would fail.
 */
class LlmGatewayEndpointSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('services.llm.enabled', true);
        config()->set('tprm.ai.enabled', true);
        config()->set('tprm.ai.services.evidence_extraction', true);
        config()->set('tprm.ai.implemented_services', ['evidence_extraction']);
        config()->set('llm.retry.max_attempts', 2);
        config()->set('llm.retry.backoff_ms', [1, 1]);
        config()->set('llm.breaker.failure_threshold', 3);
        config()->set('llm.breaker.open_seconds', 60);
        config()->set('llm.default_profile', 'local-ollama');

        // TWO profiles. The default ("local-ollama", box1) and a second,
        // distinct profile ("secondary", box2) that a tenant explicitly
        // chooses. Different hosts so `Http::fake()` can tell which endpoint
        // a request actually reached.
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model (default)', 'endpoint' => 'http://box1:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
            'secondary' => [
                'label' => 'Secondary endpoint', 'endpoint' => 'http://box2:11434', 'model' => 'granite4:small',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        $this->organization = Organization::create([
            'name' => 'Jos Merchant Bank', 'short_name' => 'JMB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);

        // The tenant explicitly chose the NON-DEFAULT profile.
        TprmSetting::forOrganization($this->organization->id)
            ->forceFill(['ai_endpoint_profile' => 'secondary'])
            ->save();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function makeCall(): LlmCall
    {
        return new LlmCall(
            organizationId: $this->organization->id,
            module: 'tprm',
            service: 'evidence_extraction',
            promptKey: 'soc2',
            promptVersion: 'soc2.v2',
            prompt: 'extract this',
            system: 'system prompt',
            budget: 'extraction',
            // No per-call override. The tenant's STORED profile is the only
            // thing that should select the endpoint.
            endpointProfile: null,
        );
    }

    #[Test]
    public function a_real_call_goes_to_the_tenants_stored_profile_not_the_deployment_default(): void
    {
        Http::fake([
            'box1:11434/*' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 10, 'eval_count' => 5], 200),
            'box2:11434/*' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 20, 'eval_count' => 8], 200),
        ]);

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertTrue($outcome->succeeded());
        $this->assertSame('secondary', $outcome->endpointProfile);
        $this->assertSame('granite4:small', $outcome->model);

        // The wrong-but-plausible bug sends the call to box1 (the default)
        // while claiming "secondary" in the return value, or vice versa —
        // assert the network request itself reached the tenant's box.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'box2:11434'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'box1:11434'));
    }

    #[Test]
    public function the_usage_row_records_the_tenants_profile_not_the_default(): void
    {
        Http::fake([
            'box1:11434/*' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 10, 'eval_count' => 5], 200),
            'box2:11434/*' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 20, 'eval_count' => 8], 200),
        ]);

        app(LlmGateway::class)->call($this->makeCall());

        $row = LlmUsageEvent::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->firstOrFail();

        $this->assertSame('secondary', $row->endpoint_profile);
        $this->assertSame('granite4:small', $row->model);
    }

    #[Test]
    public function the_breaker_key_follows_the_tenants_profile_not_the_default(): void
    {
        // Three consecutive transport failures FROM THE TENANT'S PROFILE
        // (box2) must open the breaker keyed "secondary" and must leave the
        // default profile's own breaker ("local-ollama") untouched — proof
        // that the breaker key itself is resolved from the snapshot, not
        // from the deployment default.
        Http::fake([
            'box1:11434/*' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 10, 'eval_count' => 5], 200),
            'box2:11434/*' => Http::response('server error', 500),
        ]);

        $gateway = app(LlmGateway::class);

        for ($i = 0; $i < 3; $i++) {
            $gateway->call($this->makeCall());
        }

        $breaker = app(CircuitBreaker::class);

        $this->assertSame('open', $breaker->state('secondary'), 'The breaker for the tenant\'s own chosen profile should be open.');
        $this->assertSame('closed', $breaker->state('local-ollama'), 'The default profile\'s breaker must be untouched by the tenant\'s failures on a different box.');
    }

    #[Test]
    public function availability_and_a_real_call_resolve_to_the_same_endpoint_profile(): void
    {
        // `LlmGateway::availability()` (the settings screen's own path) and
        // `call()` (the real path) must never disagree about which profile a
        // tenant's calls actually go through — the exact two spellings of
        // "the endpoint that applies to this call" the fix collapsed into
        // one snapshot resolution.
        Http::fake([
            'box1:11434/api/tags' => Http::response(['models' => []], 200),
            'box2:11434/api/tags' => Http::response(['models' => []], 200),
            'box2:11434/api/generate' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 1, 'eval_count' => 1], 200),
        ]);

        $gateway = app(LlmGateway::class);

        $availability = $gateway->availability($this->organization->id, 'tprm', 'evidence_extraction');
        $this->assertTrue($availability->allowed);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'box2:11434/api/tags'));

        $outcome = $gateway->call($this->makeCall());
        $this->assertSame('secondary', $outcome->endpointProfile);
    }
}
