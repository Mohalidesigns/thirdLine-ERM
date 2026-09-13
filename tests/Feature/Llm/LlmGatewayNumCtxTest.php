<?php

namespace Tests\Feature\Llm;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\User;
use App\Services\Llm\LlmCall;
use App\Services\Llm\LlmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * AC 18, phase-11a-ai-contract.md §8.18 / ADR 0015 §6d deviation 9.
 *
 * `num_ctx` is sent, and only through the gateway. The regression this
 * criterion exists to catch is a future engineer moving the value back onto
 * a budget "for tidiness" — which would silently reach ERM's grandfathered
 * `Risk\AiToolsController` through `...self::budget('narrative')` and pin its
 * executive-narrative tool to 4,096 on any deployment that had raised
 * `OLLAMA_CONTEXT_LENGTH`. Both halves of the asymmetry are asserted: the
 * gateway sends the declared window, and the ungoverned ERM caller sends
 * none at all.
 */
class LlmGatewayNumCtxTest extends TestCase
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
        // `narrative` is DELIBERATELY left as the real config/services.php
        // value, not overridden here — the whole point of
        // `ai_tools_controllers_narrative_path_sends_no_num_ctx()` is to
        // catch a real `num_ctx` key added back to the SHIPPED file, and an
        // override here would silently launder that regression.
        config()->set('tprm.ai.enabled', true);
        config()->set('tprm.ai.services.evidence_extraction', true);
        config()->set('tprm.ai.implemented_services', ['evidence_extraction']);
        config()->set('llm.retry.max_attempts', 1);
        config()->set('llm.breaker.failure_threshold', 3);
        config()->set('llm.breaker.open_seconds', 60);
        config()->set('llm.context.num_ctx', 4096);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);

        $this->organization = Organization::create([
            'name' => 'Enugu Metropolitan Bank', 'short_name' => 'EMB',
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

    #[Test]
    public function a_gateway_routed_call_sends_the_declared_context_window(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode(['headline' => 'ok']),
                'prompt_eval_count' => 100, 'eval_count' => 20,
            ], 200),
        ]);

        $call = new LlmCall(
            organizationId: $this->organization->id,
            module: 'tprm',
            service: 'evidence_extraction',
            promptKey: 'soc2',
            promptVersion: 'soc2.v2',
            prompt: 'extract this',
            system: 'system prompt',
            budget: 'extraction',
        );

        $outcome = app(LlmGateway::class)->call($call);

        $this->assertTrue($outcome->succeeded());
        $this->assertSame(4096, $outcome->contextWindow, 'A gateway-routed call must report the num_ctx it actually sent.');

        Http::assertSent(function ($request) {
            return isset($request['options']['num_ctx']) && $request['options']['num_ctx'] === 4096;
        });
    }

    /**
     * The asymmetry, asserted directly: ERM's grandfathered
     * `Risk\AiToolsController::executiveNarrative()` calls `$this->llm->json()`
     * DIRECTLY — never through the gateway — spreading
     * `self::budget('narrative')` into the options array. With `num_ctx` kept
     * off every budget (asserted above), that spread carries no `num_ctx`
     * key, so the outgoing Ollama request must not carry one either.
     */
    #[Test]
    public function ai_tools_controllers_narrative_path_sends_no_num_ctx(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'headline' => 'Q3 posture stable', 'posture' => 'green',
                    'summary' => 'Overall posture is stable this quarter with no material regulatory exposure.',
                    'watchlist' => ['A', 'B', 'C'], 'action_items' => ['X', 'Y', 'Z'],
                ]),
            ], 200),
        ]);

        $user = User::create([
            'name' => 'CRO Office', 'email' => 'cro@emb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('erm-ai-use-numctx', 'web');
        $role->givePermissionTo(Permission::findOrCreate('ai.use', 'web'));
        $user->assignRole($role);

        $this->actingAs($user)->post(route('risk.ai.tools.executive-narrative'))->assertOk();

        // `Http::assertSent()` is satisfied by ANY ONE matching recorded
        // request — a closure that returns true for every non-`/api/generate`
        // call (the `/api/tags` availability probe) would pass even if the
        // real `/api/generate` call leaked `num_ctx`, because the probe call
        // alone would satisfy it. Filter to the generate calls explicitly and
        // assert on all of them, so this test cannot be satisfied by a call
        // it was never meant to check.
        $generateCalls = Http::recorded(fn ($request) => str_contains($request->url(), '/api/generate'));

        $this->assertNotEmpty($generateCalls, 'Expected the narrative tool to reach /api/generate.');

        foreach ($generateCalls as [$request, $response]) {
            $this->assertArrayNotHasKey(
                'num_ctx',
                (array) ($request['options'] ?? []),
                'Risk\\AiToolsController::executiveNarrative() must never send num_ctx: it is an '
                .'ungoverned ERM caller (ADR 0015 §1 amendment) that derives no cap and has no '
                .'fitted signal, so a declared window pinned onto it via a leaked budget key is '
                .'pure loss with no compensating benefit.'
            );
        }
    }
}
