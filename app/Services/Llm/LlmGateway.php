<?php

namespace App\Services\Llm;

use App\Enums\Llm\Outcome;

/**
 * The one door — phase-11a-ai-contract.md §2.1. NEVER THROWS.
 *
 * Every module client (`Tprm\Extraction\LlmClient`, `Bcms\Ai\BcmsLlmClient`)
 * calls `call()` and gets back a result object, never an exception. A
 * disabled or unreachable optional AI service is not an error condition —
 * that rule predates this class in both module clients and this class exists
 * to stop it being reimplemented a third time.
 *
 * BOUND TRANSIENT in `AppServiceProvider`. It carries no state of its own
 * between calls — everything that must survive between calls (the breaker,
 * the usage ledger) lives in the cache or the database, which is what makes a
 * fresh instance per call safe in a queue worker serving different tenants
 * back to back.
 *
 * MODULE-BLIND BY CONSTRUCTION. This class and everything else in
 * `App\Services\Llm\` may not import `App\Models\Tprm`, `App\Enums\Tprm`,
 * `App\Models\Bcms` or `App\Enums\Bcms` — a guard test asserts it. Tenant and
 * deployment policy for TPRM and BCMS is read through
 * `ModuleAiPolicyRegistry`, which hands back a `ModulePolicySnapshot` built by
 * a policy class that lives in the OWNING module's own namespace.
 */
class LlmGateway
{
    /**
     * Per-INSTANCE memoisation only — never a property that outlives this
     * object. `LlmGateway` is bound transient specifically so a fresh
     * instance serves each request/job; a Gate-2-driven defect (advisory:
     * "9-second admin page") is that the settings screen resolves the SAME
     * tenant's snapshot and probes the SAME resolved endpoint once per
     * service (seven times) on ONE instance of this class, because a single
     * `AiSettingsController` request injects one `LlmGateway` and calls
     * `availability()` in a loop. Caching here — keyed by module/org/service
     * and by resolved endpoint respectively — collapses that back down to one
     * snapshot resolution per service and one network probe per distinct
     * endpoint, without turning the binding into a singleton and without the
     * cache ever surviving past this object's own lifetime.
     *
     * @var array<string, ModulePolicySnapshot>
     */
    private array $snapshotCache = [];

    /** @var array<string, bool> */
    private array $probeCache = [];

    public function __construct(
        private readonly \App\Services\LlmService $llm,
        private readonly EndpointResolver $endpoints,
        private readonly CircuitBreaker $breaker,
        private readonly UsageBudget $budget,
        private readonly UsageRecorder $recorder,
        private readonly RetryBackoff $backoff,
    ) {}

    public function call(LlmCall $call): LlmOutcome
    {
        try {
            return $this->attemptCall($call);
        } catch (\Throwable $e) {
            // Belt and braces: every branch below is written not to throw,
            // but "never throws" is a promise to every caller, not an
            // aspiration, so an unanticipated exception anywhere in this
            // method still comes back as a refusal rather than a 500.
            \Illuminate\Support\Facades\Log::error('LlmGateway::call() raised unexpectedly', [
                'organization_id' => $call->organizationId,
                'module' => $call->module,
                'service' => $call->service,
                'message' => $e->getMessage(),
            ]);

            // Same resolution order as the happy path below: the tenant's
            // stored profile choice, not the deployment default, is what a
            // refusal should be attributed to and what the usage row should
            // name. `snapshotFor()` is wrapped in its own try/catch here —
            // this branch is ALREADY handling an unanticipated exception, and
            // "never throws" has to hold even if resolving the snapshot
            // itself is what is failing.
            $endpointProfileKey = $call->endpointProfile;

            try {
                $endpointProfileKey ??= $this->snapshotFor($call)->endpointProfileKey;
            } catch (\Throwable) {
                // Best effort only — fall back to whatever the caller passed
                // (possibly null, which EndpointResolver turns into the
                // deployment default) rather than let a second exception
                // escape this handler.
            }

            $profile = $this->endpoints->resolve($endpointProfileKey);

            $event = $this->recorder->recordSafely($call, Outcome::HttpError, [
                'model' => $profile->model,
                'endpointProfile' => $profile->key,
            ]);

            return new LlmOutcome(
                outcome: Outcome::HttpError,
                data: [],
                reason: 'An unexpected error occurred while contacting the model. Nothing was applied.',
                model: $profile->model,
                endpointProfile: $profile->key,
                promptTokens: null,
                completionTokens: null,
                totalTokens: null,
                durationMs: 0,
                attempts: 0,
                // No request was ever sent on this path — the exception fired
                // before or during resolution, not during a call to the
                // model — so there is no `num_ctx` to report.
                contextWindow: null,
                usageEventId: $event?->getKey(),
            );
        }
    }

    private function attemptCall(LlmCall $call): LlmOutcome
    {
        // The snapshot carries the tenant's STORED endpoint choice
        // (`$snapshot->endpointProfileKey`); `$call->endpointProfile` is an
        // explicit per-call override that no caller in the product actually
        // sets today. Resolving the snapshot FIRST and falling back to it is
        // what makes a tenant's chosen profile govern the call, the breaker
        // key and the usage row — matching `availability()` below, which
        // already resolved it this way. Resolving `$call->endpointProfile`
        // alone here (the bug) silently used the deployment default for
        // every real call, no matter what a tenant had configured.
        $snapshot = $this->snapshotFor($call);
        $profile = $this->endpoints->resolve($call->endpointProfile ?? $snapshot->endpointProfileKey);

        $blocked = $this->blockingLayer($call->organizationId, $snapshot);

        if ($blocked !== null) {
            return $this->refuse($call, $profile, $blocked);
        }

        if (! $snapshot->caps->isUncapped()) {
            $verdict = $this->budget->check($call->organizationId, $snapshot->caps);

            if (! $verdict->withinBudget) {
                return $this->refuse($call, $profile, Availability::blocked((string) $verdict->reason, 'cap'), Outcome::CapExceeded);
            }
        }

        if (! $this->breaker->allows($profile->key)) {
            return $this->refuse(
                $call,
                $profile,
                Availability::blocked(
                    'The endpoint for this request has failed repeatedly and is temporarily paused. It will be tried again shortly.',
                    'breaker',
                ),
                Outcome::CircuitOpen,
            );
        }

        $endpointLlm = $this->llm->forEndpoint($profile->endpoint, $profile->model);

        if (! $endpointLlm->available()) {
            $this->breaker->recordFailure($profile->key);

            return $this->refuse(
                $call,
                $profile,
                Availability::blocked(
                    'The model endpoint is not reachable right now: '.($endpointLlm->lastError() ?? 'no detail given').'.',
                    'endpoint',
                ),
                Outcome::Unreachable,
            );
        }

        return $this->attemptGenerate($call, $profile, $endpointLlm);
    }

    private function attemptGenerate(LlmCall $call, EndpointProfile $profile, \App\Services\LlmService $endpointLlm): LlmOutcome
    {
        $budgetConfig = (array) config('services.llm.budgets.'.$call->budget, ['max_tokens' => 768, 'timeout' => 60]);
        $maxAttempts = max(1, (int) config('llm.retry.max_attempts', 2));
        $backoffMs = (array) config('llm.retry.backoff_ms', [1000, 3000]);
        $retryOn = (array) config('llm.retry.retry_on', ['connection', 'timeout', 'http_5xx']);

        // ADR 0015 §6d deviation 9 / contract §2.5 §4.4: `num_ctx` lives in
        // `config/llm.php`, the gateway's own config, NOT on the budget.
        // `Risk\AiToolsController` spreads a whole budget array into a direct
        // `LlmService` call, so a key placed on the budget would reach ERM's
        // grandfathered callers too. `config/llm.php` is read here and by no
        // module caller, which is what keeps the declared window scoped to
        // calls that actually go through the gateway. Absent or null means
        // none is sent — never a default invented here.
        $numCtx = config('llm.context.num_ctx');
        $numCtx = $numCtx !== null ? (int) $numCtx : null;

        $attempts = 0;
        $response = [];

        for ($attempts = 1; $attempts <= $maxAttempts; $attempts++) {
            $response = $endpointLlm->jsonWithUsage($call->prompt, $call->system, array_filter([
                'model' => $profile->model,
                'keep_alive' => $profile->keepAlive,
                'max_tokens' => $budgetConfig['max_tokens'] ?? 768,
                'timeout' => $budgetConfig['timeout'] ?? 60,
                'num_ctx' => $numCtx,
            ], static fn ($value) => $value !== null));

            if (($response['kind'] ?? '') === 'ok') {
                break;
            }

            $retryableKind = $this->retryableKind((string) ($response['kind'] ?? ''), (int) ($response['status'] ?? 0));

            if (! in_array($retryableKind, $retryOn, true) || $attempts >= $maxAttempts) {
                break;
            }

            // The jitter itself lives in RetryBackoff, and only there — ADR
            // 0015 §6c: a herd of retries after a shared outage must not
            // re-arrive at the endpoint in lockstep a second time, and the
            // RNG that prevents it must not share a file with the figures
            // this class computes for the usage report.
            $this->backoff->sleep((int) ($backoffMs[$attempts - 1] ?? end($backoffMs)));
        }

        $kind = (string) ($response['kind'] ?? 'connection');

        if ($kind === 'ok') {
            $this->breaker->recordSuccess($profile->key);

            $promptTokens = $response['prompt_tokens'];
            $completionTokens = $response['completion_tokens'];
            $totalTokens = ($promptTokens === null || $completionTokens === null)
                ? null
                : $promptTokens + $completionTokens;

            $costMinor = $this->costFor($profile, $totalTokens);

            $event = $this->recorder->recordSafely($call, Outcome::Succeeded, [
                'model' => $response['model'],
                'endpointProfile' => $profile->key,
                'attempts' => $attempts,
                'promptTokens' => $promptTokens,
                'completionTokens' => $completionTokens,
                'durationMs' => $response['duration_ms'],
                'unitCostMinor' => $costMinor,
                'currency' => $costMinor === null ? null : $profile->currency,
            ]);

            return new LlmOutcome(
                outcome: Outcome::Succeeded,
                data: $response['data'],
                reason: null,
                model: $response['model'],
                endpointProfile: $profile->key,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: $totalTokens,
                durationMs: $response['duration_ms'],
                attempts: $attempts,
                contextWindow: $numCtx,
                usageEventId: $event?->getKey(),
            );
        }

        $outcome = match ($kind) {
            'timeout' => Outcome::Timeout,
            'connection' => Outcome::Unreachable,
            'http_error' => Outcome::HttpError,
            'unparsable' => Outcome::Unparsable,
            default => Outcome::HttpError,
        };

        // ADR 0015 §6: timeouts and connection failures, and HTTP 5xx, count
        // as breaker failures. An unparseable 200 does not — the box is up,
        // the model's own answer just was not usable JSON.
        //
        // `Outcome::countsAsBreakerFailure()`, not `!== Outcome::Unparsable`
        // (Gate 2 advisory): the inline denylist and the enum's own allowlist
        // are the same rule stated twice, and a ninth Outcome case added here
        // without also touching the enum would silently diverge from it.
        if ($outcome->countsAsBreakerFailure()) {
            $this->breaker->recordFailure($profile->key);
        }

        $event = $this->recorder->recordSafely($call, $outcome, [
            'model' => $response['model'] ?? $profile->model,
            'endpointProfile' => $profile->key,
            'attempts' => $attempts,
            'durationMs' => $response['duration_ms'] ?? 0,
        ]);

        return new LlmOutcome(
            outcome: $outcome,
            data: [],
            reason: (string) ($response['error'] ?? 'The model did not return a usable answer.'),
            model: (string) ($response['model'] ?? $profile->model),
            endpointProfile: $profile->key,
            promptTokens: null,
            completionTokens: null,
            totalTokens: null,
            durationMs: (int) ($response['duration_ms'] ?? 0),
            attempts: $attempts,
            // A request WAS sent with this `num_ctx` (or none) even though it
            // did not succeed — "actually sent" does not mean "succeeded".
            contextWindow: $numCtx,
            usageEventId: $event?->getKey(),
        );
    }

    /**
     * `services.llm.budgets.<shape>` supplies `max_tokens`/`timeout`;
     * `unit_cost_per_1k_tokens_minor` comes from the endpoint profile.
     */
    private function costFor(EndpointProfile $profile, ?int $totalTokens): ?int
    {
        if (! $profile->isPriced() || $totalTokens === null) {
            return null;
        }

        return (int) round(($totalTokens / 1000) * (int) $profile->unitCostPer1kTokensMinor);
    }

    private function retryableKind(string $kind, int $status): string
    {
        if ($kind === 'http_error' && $status >= 500) {
            return 'http_5xx';
        }

        return $kind;
    }

    /**
     * Whether a call would be allowed right now, and which layer would stop
     * it — the settings screen's question, never a network call except the
     * final endpoint check (contract §5: config and tenant switches make no
     * network call).
     */
    public function availability(int $organizationId, string $module, string $service, ?string $profile = null): Availability
    {
        $call = new LlmCall(
            organizationId: $organizationId,
            module: $module,
            service: $service,
            promptKey: '',
            promptVersion: '',
            prompt: '',
            endpointProfile: $profile,
        );

        $snapshot = $this->snapshotFor($call);
        $blocked = $this->blockingLayer($organizationId, $snapshot);

        if ($blocked !== null) {
            return $blocked;
        }

        if (! $snapshot->caps->isUncapped()) {
            $verdict = $this->budget->check($organizationId, $snapshot->caps);

            if (! $verdict->withinBudget) {
                return Availability::blocked((string) $verdict->reason, 'cap');
            }
        }

        $resolved = $this->endpoints->resolve($profile ?? $snapshot->endpointProfileKey);

        if (! $this->breaker->allows($resolved->key)) {
            return Availability::blocked(
                'The endpoint for this service has failed repeatedly and is temporarily paused.',
                'breaker',
            );
        }

        if (! $this->probe($resolved)) {
            return Availability::blocked('The model endpoint is not reachable right now.', 'endpoint');
        }

        return Availability::allowed();
    }

    /**
     * The cheap-probe path's own network check, memoised by resolved
     * endpoint for the life of this instance — `availability()` ONLY. The
     * real call path in `attemptCall()` deliberately does NOT go through this
     * cache: an actual attempt has to know the endpoint's reachability at the
     * moment of that attempt, because a failed probe there trips the breaker
     * (`recordFailure()`) and a cached answer would either skip that or trip
     * it on a stale result. `availability()` never trips the breaker on its
     * own probe (it only reads breaker state), so memoising its probe is
     * purely a "how many times do we ask the same question in one render"
     * saving, not a change to the breaker's own semantics.
     */
    private function probe(EndpointProfile $resolved): bool
    {
        return $this->probeCache[$resolved->endpoint.'|'.$resolved->model] ??=
            $this->llm->forEndpoint($resolved->endpoint, $resolved->model)->available();
    }

    private function snapshotFor(LlmCall $call): ModulePolicySnapshot
    {
        $cacheKey = $call->module.'|'.$call->organizationId.'|'.$call->service;

        return $this->snapshotCache[$cacheKey] ??= $this->resolveSnapshot($call);
    }

    private function resolveSnapshot(LlmCall $call): ModulePolicySnapshot
    {
        $policy = ModuleAiPolicyRegistry::resolve($call->module);

        if ($policy === null) {
            // No policy registered for this module: nothing beyond the
            // deployment LLM switch gates it. Neither TPRM nor BCMS is ever
            // in this branch — both register a policy — so this only applies
            // to a future module that has not yet built one.
            return new ModulePolicySnapshot(
                moduleEnabled: true,
                tenantMasterEnabled: null,
                serviceDeploymentEnabled: true,
                tenantServiceEnabled: null,
                caps: UsageCaps::uncapped(),
                endpointProfileKey: $call->endpointProfile,
            );
        }

        return $policy->snapshot($call->organizationId, $call->service);
    }

    /**
     * Steps 1-5 of contract §5, in order. Returns null when nothing at this
     * level blocks the call.
     */
    private function blockingLayer(int $organizationId, ModulePolicySnapshot $snapshot): ?Availability
    {
        if (! config('services.llm.enabled')) {
            return Availability::blocked('No language model is configured for this deployment.', 'deployment_llm');
        }

        if (! $snapshot->moduleEnabled) {
            return Availability::blocked('AI is switched off for this module at the deployment level.', 'deployment_module');
        }

        if ($snapshot->tenantMasterEnabled === false) {
            return Availability::blocked('AI is switched off for this organisation.', 'tenant_master');
        }

        if (! $snapshot->serviceDeploymentEnabled) {
            return Availability::blocked('This AI service is not enabled for this deployment.', 'deployment_service');
        }

        if ($snapshot->tenantServiceEnabled === false) {
            return Availability::blocked('This AI service is switched off for this organisation.', 'tenant_service');
        }

        return null;
    }

    private function refuse(LlmCall $call, EndpointProfile $profile, Availability $blocked, Outcome $outcome = Outcome::Refused): LlmOutcome
    {
        $event = $this->recorder->recordSafely($call, $outcome, [
            'model' => $profile->model,
            'endpointProfile' => $profile->key,
        ]);

        return new LlmOutcome(
            outcome: $outcome,
            data: [],
            reason: $blocked->reason,
            model: $profile->model,
            endpointProfile: $profile->key,
            promptTokens: null,
            completionTokens: null,
            totalTokens: null,
            durationMs: 0,
            attempts: 0,
            // A refusal at this layer (deployment/tenant switch, cap, breaker,
            // endpoint) never reaches `attemptGenerate()`, so no request —
            // and no `num_ctx` — was ever sent.
            contextWindow: null,
            usageEventId: $event?->getKey(),
        );
    }
}
