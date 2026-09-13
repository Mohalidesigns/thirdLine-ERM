<?php

namespace Tests\Feature\Llm;

use App\Enums\Llm\Outcome;
use App\Models\LlmUsageEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\TprmSetting;
use App\Services\Llm\LlmCall;
use App\Services\Llm\LlmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The gateway's own resolution order (phase-11a-ai-contract.md §5), the
 * usage ledger it writes on every outcome, and the "never throws" contract.
 */
class LlmGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('services.llm.enabled', true);
        config()->set('services.llm.endpoint', 'http://localhost:11434');
        config()->set('services.llm.model', 'granite4:micro');
        config()->set('services.llm.budgets.extraction', ['max_tokens' => 2048, 'timeout' => 120]);
        config()->set('tprm.ai.enabled', true);
        config()->set('tprm.ai.services.evidence_extraction', true);
        config()->set('tprm.ai.implemented_services', ['evidence_extraction']);
        config()->set('llm.retry.max_attempts', 2);
        config()->set('llm.retry.backoff_ms', [1, 1]);
        config()->set('llm.breaker.failure_threshold', 3);
        config()->set('llm.breaker.open_seconds', 60);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        $this->organization = Organization::create([
            'name' => 'Kaduna Merchant Bank', 'short_name' => 'KMB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    private function makeCall(?string $endpointProfile = null): LlmCall
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
            endpointProfile: $endpointProfile,
        );
    }

    private function assertOneUsageRow(Outcome $outcome): LlmUsageEvent
    {
        $rows = LlmUsageEvent::withoutGlobalScopes()->where('organization_id', $this->organization->id)->get();
        $this->assertCount(1, $rows, 'Expected exactly one usage row.');
        $this->assertSame($outcome->value, $rows->first()->outcome->value);
        $this->assertNotNull($rows->first()->organization_id);

        return $rows->first();
    }

    /* ------------------------------------------------------------------ */
    /*  §5 resolution order */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function deployment_llm_off_refuses_without_a_network_call_and_writes_a_row(): void
    {
        config()->set('services.llm.enabled', false);

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::Refused, $outcome->outcome);
        $this->assertSame('deployment_llm', $this->availabilityLayer());
        $this->assertOneUsageRow(Outcome::Refused);
    }

    #[Test]
    public function deployment_module_off_refuses(): void
    {
        config()->set('tprm.ai.enabled', false);

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::Refused, $outcome->outcome);
        $this->assertOneUsageRow(Outcome::Refused);
    }

    #[Test]
    public function tenant_master_off_refuses_even_though_the_deployment_is_on(): void
    {
        TprmSetting::forOrganization($this->organization->id)->forceFill(['ai_enabled' => false])->save();

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::Refused, $outcome->outcome);
        $this->assertOneUsageRow(Outcome::Refused);
    }

    #[Test]
    public function tenant_master_null_follows_the_deployment_default(): void
    {
        // Null (never asked) with the deployment ON and the endpoint
        // reachable must proceed past the tenant-master layer.
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 10, 'eval_count' => 5], 200),
        ]);

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertTrue($outcome->succeeded());
    }

    #[Test]
    public function a_tenant_cannot_enable_a_service_the_deployment_has_disabled(): void
    {
        // AC-16 item 3 / contract §8.3: asserted at the RESOLVER, not only at
        // the form. The deployment switch for this service is off; the
        // tenant explicitly asked for it on.
        config()->set('tprm.ai.services.evidence_extraction', false);
        TprmSetting::forOrganization($this->organization->id)->forceFill([
            'ai_enabled' => true,
            'ai_services' => ['evidence_extraction' => true],
        ])->save();

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::Refused, $outcome->outcome);
    }

    #[Test]
    public function an_unimplemented_service_cannot_be_enabled_by_anyone(): void
    {
        config()->set('tprm.ai.implemented_services', []);
        TprmSetting::forOrganization($this->organization->id)->forceFill([
            'ai_enabled' => true,
            'ai_services' => ['evidence_extraction' => true],
        ])->save();

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::Refused, $outcome->outcome);
    }

    /* ------------------------------------------------------------------ */
    /*  Cap */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function exceeding_the_monthly_call_cap_refuses_the_next_call_and_records_it(): void
    {
        TprmSetting::forOrganization($this->organization->id)->forceFill(['ai_monthly_call_cap' => 1])->save();

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 10, 'eval_count' => 5], 200),
        ]);

        $first = app(LlmGateway::class)->call($this->makeCall());
        $this->assertTrue($first->succeeded());

        $second = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::CapExceeded, $second->outcome);

        $rows = LlmUsageEvent::withoutGlobalScopes()->where('organization_id', $this->organization->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(Outcome::CapExceeded->value, $rows->last()->outcome->value);
    }

    #[Test]
    public function exceeding_the_monthly_token_cap_refuses_the_next_call_and_states_the_cap(): void
    {
        TprmSetting::forOrganization($this->organization->id)->forceFill(['ai_monthly_token_cap' => 10])->save();

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response(['response' => '{"a":1}', 'prompt_eval_count' => 10, 'eval_count' => 5], 200),
        ]);

        $first = app(LlmGateway::class)->call($this->makeCall());
        $this->assertTrue($first->succeeded());
        $this->assertSame(15, $first->totalTokens);

        $second = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::CapExceeded, $second->outcome);
        $this->assertStringContainsString('token limit of 10', (string) $second->reason);
    }

    /* ------------------------------------------------------------------ */
    /*  Never throws */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_unparseable_200_does_not_open_the_breaker(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response(['response' => 'not json at all'], 200),
        ]);

        $gateway = app(LlmGateway::class);

        for ($i = 0; $i < 5; $i++) {
            $outcome = $gateway->call($this->makeCall());
            $this->assertSame(Outcome::Unparsable, $outcome->outcome);
        }

        // Five unparseable responses in a row must not have tripped the
        // breaker — ADR 0015 §6: the box is up, the model just answered
        // badly.
        $this->assertTrue(app(\App\Services\Llm\CircuitBreaker::class)->allows('local-ollama'));
    }

    /**
     * Gate 1 (QA restart cycle): AC-6 was previously proven only at
     * `CircuitBreaker`'s own unit level, driven by manual `recordFailure()`
     * calls — that proves the breaker's state machine but not that
     * `LlmGateway` actually wires it: nothing failed if `recordFailure()`
     * were deleted from `attemptGenerate()`, or if the `breaker->allows()`
     * check were removed from `attemptCall()`. This drives three REAL
     * transport failures through `LlmGateway::call()` itself and asserts the
     * fourth is refused WITHOUT a network request, per contract §8.6.
     */
    #[Test]
    public function three_consecutive_transport_failures_through_the_gateway_open_the_breaker_and_the_fourth_call_makes_no_network_request(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response('server error', 500),
        ]);

        $gateway = app(LlmGateway::class);

        for ($i = 0; $i < 3; $i++) {
            $outcome = $gateway->call($this->makeCall());
            $this->assertSame(Outcome::HttpError, $outcome->outcome);
        }

        $this->assertSame('open', app(\App\Services\Llm\CircuitBreaker::class)->state('local-ollama'));

        $requestsBefore = count(Http::recorded());

        $fourth = $gateway->call($this->makeCall());

        $this->assertSame(Outcome::CircuitOpen, $fourth->outcome);
        $this->assertSame(
            $requestsBefore,
            count(Http::recorded()),
            'The fourth call made a network request even though the breaker was open.'
        );
    }

    #[Test]
    public function the_gateway_never_throws_even_when_the_driver_does(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('a truly unexpected failure');
        });

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertFalse($outcome->succeeded());
        $this->assertNotNull($outcome->reason);
    }

    #[Test]
    public function every_call_writes_exactly_one_usage_row_including_a_refusal(): void
    {
        config()->set('services.llm.enabled', false);

        app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(1, LlmUsageEvent::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_call_with_no_tenant_context_is_refused_by_the_caller_and_writes_nothing(): void
    {
        // The gateway itself cannot be asked this question — LlmCall's
        // organizationId is a required int, so a caller with no resolvable
        // tenant cannot construct one at all. This is asserted at
        // Tprm\Extraction\LlmClient::run(), the layer that owns the decision.
        TenantContext::clear();

        $result = app(\App\Services\Tprm\Extraction\LlmClient::class)->run(
            'soc2', 'rendered prompt', \App\Services\Tprm\Extraction\LlmClient::EVIDENCE_EXTRACTION, null
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame(0, LlmUsageEvent::withoutGlobalScopes()->count());
    }

    private function availabilityLayer(): ?string
    {
        return app(LlmGateway::class)->availability($this->organization->id, 'tprm', 'evidence_extraction')->layer;
    }

    /* ------------------------------------------------------------------ */
    /*  contextWindow on a failed call — flagged by the implementing engineer */
    /* ------------------------------------------------------------------ */

    /**
     * A transport failure (`Timeout`/`HttpError`/`Unparsable`) still carries
     * the `num_ctx` that was ACTUALLY SENT on `LlmOutcome::$contextWindow`,
     * because `attemptGenerate()` sends the request before it learns the
     * call failed — "a request was sent with this num_ctx even though it did
     * not succeed" (the comment on that code path, verbatim). This is
     * distinct from a refusal at an earlier layer (deployment/tenant switch,
     * cap, breaker, an unreachable endpoint's own probe), where NO request
     * is ever built and `contextWindow` is correctly null.
     *
     * The risk this test is for: a caller reading a non-null `contextWindow`
     * off a failed outcome and mistaking it for a completeness signal.
     * Nothing downstream does this today — `ExtractionDispatcher` only ever
     * reaches `contextWindowMeta()` after `$result->succeeded()` is checked
     * — but `contextWindow` is a public, readable property, and this pins
     * the shape so a future caller cannot make that mistake unnoticed: the
     * gateway is proving what it SENT, never what came back.
     */
    #[Test]
    public function a_failed_call_still_reports_the_num_ctx_it_sent_but_never_succeeded(): void
    {
        config()->set('llm.context.num_ctx', 4096);

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            // A 500 reaches `attemptGenerate()` (the availability probe above
            // already passed) and classifies as `http_error`, which DOES
            // build and send a request — unlike a refusal, which never does.
            '*/api/generate' => Http::response('server exploded', 500),
        ]);

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertFalse($outcome->succeeded());
        $this->assertSame(Outcome::HttpError, $outcome->outcome);
        $this->assertSame(
            4096,
            $outcome->contextWindow,
            'A failed call that DID reach the model must still report the num_ctx it sent — "sent" is not "succeeded".'
        );
        // And the outcome itself is unambiguous about not having succeeded,
        // so a caller cannot read the non-null contextWindow as a completeness
        // signal without also ignoring $outcome->succeeded() === false.
        $this->assertNotSame(Outcome::Succeeded, $outcome->outcome);
    }

    /**
     * The complement: a refusal at an earlier layer never reaches the model
     * at all, so `contextWindow` must be null — there is nothing that was
     * "sent".
     */
    #[Test]
    public function a_refusal_before_the_model_is_ever_contacted_reports_no_context_window(): void
    {
        config()->set('llm.context.num_ctx', 4096);
        config()->set('services.llm.enabled', false);

        $outcome = app(LlmGateway::class)->call($this->makeCall());

        $this->assertSame(Outcome::Refused, $outcome->outcome);
        $this->assertNull($outcome->contextWindow, 'A refusal that never reaches the model must not report a num_ctx that was never sent.');
    }
}
