<?php

namespace App\Http\Controllers\Tprm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tprm\UpdateAiSettingsRequest;
use App\Models\Tprm\TprmSetting;
use App\Services\Llm\CircuitBreaker;
use App\Services\Llm\EndpointResolver;
use App\Services\Llm\LlmGateway;
use App\Services\Llm\UsageReporter;
use App\Services\Tprm\Ai\TprmAiPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The tenant AI settings screen — phase-11a-ai-contract.md §7.1-§7.2,
 * `docs/tprm/screens/ai-settings.md`.
 *
 * NO POLICY, NO `TprmServiceProvider::POLICIES` ENTRY — ADR 0015 §8, the same
 * deviation `ProgrammeSettingsController` already states: `tp_settings` has
 * exactly one row per tenant, reachable only through a `tprm.admin`-gated
 * route, and no per-record question can be asked of it.
 *
 * THREE LAYERS ARE SHOWN, NEVER JUST THE EFFECTIVE VALUE (contract §7.1). The
 * `layer` a service or the master switch resolves through is computed here,
 * once, through `LlmGateway::availability()` — the same resolution order the
 * gateway itself uses for a real call — so the screen and the enforcement can
 * never say two different things.
 */
class AiSettingsController extends Controller
{
    public function __construct(
        private readonly LlmGateway $gateway,
        private readonly TprmAiPolicy $policy,
        private readonly EndpointResolver $endpoints,
        private readonly CircuitBreaker $breaker,
        private readonly UsageReporter $usage,
    ) {}

    public function edit(Request $request)
    {
        Gate::authorize('tprm.admin');

        $organizationId = (int) TenantContext::organizationId();
        // Through the policy, not `TprmSetting::forOrganization()` directly —
        // `TprmAiPolicy` is bound `scoped()` precisely so this read, the
        // seven `availability()` calls below (each resolving a snapshot
        // through the SAME policy instance) and `masterEnabled()` share one
        // memoised row instead of issuing nine identical SELECTs per render
        // (Gate 2 defect 4).
        $settings = $this->policy->settingsFor($organizationId);

        $services = (array) config('tprm.ai.services', []);
        $implemented = (array) config('tprm.ai.implemented_services', []);
        $tenantServices = (array) ($settings->ai_services ?? []);

        $stored = $settings->ai_endpoint_profile;
        $resolvedProfile = $this->endpoints->resolve($stored);

        $serviceRows = [];
        $effectiveServices = [];
        $firstLayer = null;

        foreach (array_keys($services) as $key) {
            $availability = $this->gateway->availability($organizationId, 'tprm', $key);
            $firstLayer ??= $availability->layer;

            $serviceRows[] = [
                'key' => $key,
                'label' => $this->serviceLabel($key),
                'deployment_enabled' => (bool) ($services[$key] ?? false),
                'implemented' => in_array($key, $implemented, true),
                'effective' => $availability->allowed,
                'layer' => $availability->layer,
                'tenant_value' => array_key_exists($key, $tenantServices) ? (bool) $tenantServices[$key] : null,
            ];

            $effectiveServices[$key] = $availability->allowed;
        }

        /*
         * `master_layer` — added 2026-09-11 (frontend deviation 1), contract
         * §7.1. NOT a fresh if-chain: `LlmGateway::blockingLayer()` checks
         * `deployment_llm` / `deployment_module` / `tenant_master` BEFORE any
         * service-specific layer, for every service. So when the master
         * switch itself is what is blocking, EVERY service row's `layer`
         * already carries one of those three values — the first one
         * captured above IS the master's layer, with no second resolution
         * branch. When the master switch is not what is blocking (services
         * differ only on their own deployment/tenant/cap/breaker/endpoint
         * layer, or nothing blocks at all), the master is enabled and this
         * is null, per §7.1's "null when enabled".
         */
        $masterEnabled = $this->policy->masterEnabled($organizationId);
        $masterLayer = $masterEnabled ? null : $firstLayer;

        return Inertia::render('Tprm/Settings/Ai', [
            'deployment' => [
                'llm_enabled' => (bool) config('services.llm.enabled'),
                'module_enabled' => (bool) config('tprm.ai.enabled'),
                'services' => $serviceRows,
                'profiles' => $this->endpoints->availableProfiles(),
                'cache_store_is_shared' => $this->breaker->usesSharedStore(),
                'model' => (string) config('services.llm.model'),
                'default_profile_key' => (string) config('llm.default_profile'),
            ],
            'tenant' => [
                'ai_enabled' => $settings->ai_enabled,
                'ai_services' => $tenantServices,
                'ai_endpoint_profile' => $settings->ai_endpoint_profile,
                'ai_monthly_token_cap' => $settings->ai_monthly_token_cap,
                'ai_monthly_call_cap' => $settings->ai_monthly_call_cap,
            ],
            'effective' => [
                'enabled' => $masterEnabled,
                'master_layer' => $masterLayer,
                'services' => $effectiveServices,
                'endpoint_profile' => $resolvedProfile->key,
            ],
            'breaker' => [
                'state' => $this->breaker->state($resolvedProfile->key),
                'opens_at' => $this->breaker->opensAt($resolvedProfile->key)?->toIso8601String(),
                'consecutive_failures' => $this->breaker->consecutiveFailures($resolvedProfile->key),
            ],
            'month' => $this->monthSummary($organizationId, $settings),
            'stale_profile' => $this->endpoints->wasFallback($stored) ? $stored : null,
            'updatedBy' => $settings->updater?->name,
            'updatedAt' => $settings->updated_at?->toDayDateTimeString(),
        ]);
    }

    public function update(UpdateAiSettingsRequest $request)
    {
        $organizationId = (int) TenantContext::organizationId();
        $settings = $this->policy->settingsFor($organizationId);

        // `settingsPayload()` now returns ONLY the keys this request actually
        // sent (Gate 2 advisory 7) — `fill()` below therefore leaves every
        // other column, including `ai_endpoint_profile` and the two monthly
        // caps, exactly as stored when a request omits them.
        $payload = $request->settingsPayload();

        /*
         * MERGED, NOT REPLACED, WHEN `ai_services` IS PRESENT. The screen
         * always renders and submits all seven service rows together, so in
         * the ordinary case this merge is indistinguishable from a wholesale
         * replace. It exists for the case that is not ordinary: a `PUT` that
         * sends `ai_services` as a partial map, or as `null` ("no opinion on
         * any service"), must not silently erase every service preference a
         * tenant already recorded. Values the request DOES send still win
         * outright, including sending an individual service key back to null
         * ("follow the deployment") — this is a merge of KEYS PRESENT, not a
         * refusal to change anything. When the request omits `ai_services`
         * altogether, it is simply absent from `$payload` and `fill()` never
         * touches the column at all.
         */
        if (array_key_exists('ai_services', $payload)) {
            $payload['ai_services'] = $payload['ai_services'] === null
                ? $settings->ai_services
                : array_merge((array) $settings->ai_services, $payload['ai_services']);
        }

        $settings->fill($payload + ['updated_by' => $request->user()->id])->save();

        // Defensive: this instance's own cache entry is now stale. `update()`
        // redirects rather than re-rendering `edit()` in the same request, so
        // nothing in this codebase currently reads it again before the next
        // request rebuilds the scoped instance — but a policy resolved
        // earlier in a longer-lived context must never hand back the value a
        // tenant just changed away from.
        $this->policy->forget($organizationId);

        return back()->with('success', 'AI settings saved.');
    }

    private function serviceLabel(string $key): string
    {
        return match ($key) {
            'evidence_extraction' => 'Evidence extraction',
            'clause_analysis' => 'Clause analysis',
            'subprocessor_discovery' => 'Subprocessor discovery',
            'response_quality' => 'Response quality',
            'adverse_media_triage' => 'Adverse media triage',
            'narrative_generation' => 'Narrative generation',
            'scoping_assistant' => 'Scoping assistant',
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function monthSummary(int $organizationId, TprmSetting $settings): array
    {
        $usageMonth = $this->usage->currentMonth();
        $summary = $this->usage->monthSummary($organizationId, $usageMonth);

        $tokenCap = $settings->ai_monthly_token_cap;
        $callCap = $settings->ai_monthly_call_cap;

        return [
            'usage_month' => $usageMonth,
            'total_tokens' => $summary['total_tokens'],
            'call_count' => $summary['call_count'],
            'token_cap' => $tokenCap,
            'call_cap' => $callCap,
            // One home for the subtraction — App\Services\Llm\UsageReporter
            // — so this screen and the usage report can never disagree.
            'remaining' => $this->usage->remaining($tokenCap, $callCap, $summary['total_tokens'], $summary['call_count']),
        ];
    }
}
