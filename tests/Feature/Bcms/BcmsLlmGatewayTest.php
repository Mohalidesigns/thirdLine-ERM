<?php

namespace Tests\Feature\Bcms;

use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Services\Bcms\Ai\BcmsLlmClient;
use App\Services\Bcms\BcmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0015 §9 — BCMS moves onto the gateway in the same phase.
 * `BcmsLlmClient::json()`'s PUBLIC API AND RETURN SHAPE are unchanged; only
 * its internals now route through `App\Services\Llm\LlmGateway`.
 *
 * Contract §8.14: "BCMS's three AI drafters behave unchanged through the
 * gateway" is marked [verify at integration] for the next BCMS integration
 * window — this test is the local half available now: the client's own
 * contract, exercised directly.
 */
class BcmsLlmGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);
        config()->set('services.llm.enabled', true);
        config()->set('bcms.ai.capabilities.bia_draft', true);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        $this->organization = Organization::create([
            'name' => 'Jos Cooperative Bank', 'short_name' => 'JCB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        app(BcmsSettings::class)->update(['ai_enabled' => true], $this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function a_successful_call_returns_the_same_shape_as_before(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response(
                ['response' => '{"narrative":"draft text"}', 'prompt_eval_count' => 40, 'eval_count' => 20],
                200
            ),
        ]);

        $result = app(BcmsLlmClient::class)->json(
            BcmsLlmClient::BIA_DRAFT,
            'draft a narrative',
            [],
            $this->organization->id,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(['narrative' => 'draft text'], $result['data']);
        $this->assertNull($result['reason']);

        // The gateway's own ledger now records the call, module-tagged
        // 'bcms' — the one behavioural change, and it is additive.
        $row = LlmUsageEvent::withoutGlobalScopes()->where('organization_id', $this->organization->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('bcms', $row->module);
        $this->assertSame('succeeded', $row->outcome->value);
    }

    #[Test]
    public function a_disabled_capability_still_refuses_calmly_with_no_data(): void
    {
        config()->set('bcms.ai.capabilities.bia_draft', false);

        $result = app(BcmsLlmClient::class)->json(
            BcmsLlmClient::BIA_DRAFT,
            'draft a narrative',
            [],
            $this->organization->id,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['data']);
        $this->assertNotNull($result['reason']);
    }

    #[Test]
    public function tenant_ai_off_is_still_the_reason_named(): void
    {
        app(BcmsSettings::class)->update(['ai_enabled' => false], $this->organization->id);

        $result = app(BcmsLlmClient::class)->json(
            BcmsLlmClient::BIA_DRAFT,
            'draft a narrative',
            [],
            $this->organization->id,
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('switched off for this organisation', (string) $result['reason']);
    }
}
