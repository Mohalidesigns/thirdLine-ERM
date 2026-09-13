<?php

namespace App\Services\Tprm\Extraction;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\Llm\LlmCall;
use App\Services\Llm\LlmGateway;
use App\Services\Tprm\Ai\TprmAiPolicy;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The one place TPRM talks to a model — TRD §12.1, phase-11a-ai-contract.md
 * §2.3.
 *
 * THE KILL SWITCH IS CHECKED HERE AND NOWHERE ELSE, which is what makes AC-16
 * ("with every AI service disabled, all workflows complete manually")
 * enforceable rather than aspirational. A caller cannot forget the check
 * because a caller cannot reach a model except through this class, and when
 * the switch is off this returns a refusal rather than throwing — a disabled
 * optional service is not an error condition, and a screen that 500s when AI
 * is off has failed AC-16 as surely as one that calls the model anyway.
 *
 * PHASE 11A: `run()` no longer talks to `App\Services\LlmService` directly.
 * It builds an `App\Services\Llm\LlmCall` and hands it to the platform
 * `LlmGateway`, which owns retry, the circuit breaker, per-tenant endpoint
 * selection and the usage ledger. `enabled()`/`available()` stay network-free
 * probes — they answer through `TprmAiPolicy`, the SAME resolver the gateway
 * itself consults via `ModuleAiPolicyRegistry`, so the cheap check an
 * `ExtractionDispatcher` makes before reading a document's text can never
 * disagree with what the gateway would actually do.
 *
 * THIS CLASS'S OWN `log()` IS GONE. The gateway writes exactly one
 * `llm_usage_events` row per call, including refusals — a second, private log
 * here would be a second ledger that can drift from the first.
 */
class LlmClient
{
    public const EVIDENCE_EXTRACTION = 'evidence_extraction';

    public const CLAUSE_ANALYSIS = 'clause_analysis';

    /**
     * TRD §12.7 — the board pack's narrative. Named here rather than passed as
     * a literal so the kill switch, the availability probe and the per-call
     * log apply to it exactly as they do to the other two.
     */
    public const NARRATIVE_GENERATION = 'narrative_generation';

    public function __construct(
        private readonly PromptRegistry $prompts,
        private readonly LlmGateway $gateway,
        private readonly TprmAiPolicy $policy,
    ) {}

    /**
     * Whether extraction may run at all. NO NETWORK CALL — contract §5: steps
     * 1-5 are config and tenant switches only, so a deployment with AI off
     * never contacts a box to discover that it is off.
     */
    public function enabled(string $service = self::EVIDENCE_EXTRACTION, ?int $organizationId = null): bool
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return false;
        }

        if (! config('services.llm.enabled')) {
            return false;
        }

        $snapshot = $this->policy->snapshot($organizationId, $service);

        if (! $snapshot->moduleEnabled) {
            return false;
        }

        if ($snapshot->tenantMasterEnabled === false) {
            return false;
        }

        if (! $snapshot->serviceDeploymentEnabled) {
            return false;
        }

        return $snapshot->tenantServiceEnabled !== false;
    }

    /**
     * WAS: `$this->llm->available()` — the deployment-default endpoint,
     * regardless of what the tenant has chosen (Gate 2 advisory 9, the same
     * root cause as blocking defect 1: two spellings of "the endpoint that
     * applies to this call", eleven lines apart in the gateway, and this
     * class had a third). `LlmGateway::availability()` resolves the SAME
     * tenant profile a real `call()` would use and probes THAT endpoint, so
     * a probe here can never say "available" for a box the tenant's own
     * calls do not actually go to.
     */
    public function available(string $service = self::EVIDENCE_EXTRACTION, ?int $organizationId = null): bool
    {
        $organizationId ??= TenantContext::organizationIdOrNull();

        if ($organizationId === null) {
            return false;
        }

        if (! $this->enabled($service, $organizationId)) {
            return false;
        }

        return $this->gateway->availability($organizationId, 'tprm', $service)->allowed;
    }

    /**
     * Run an extraction prompt against document text.
     *
     * HAS NO CALLER TODAY (Gate 2 advisory). `ExtractionDispatcher` calls
     * `run()` directly so it can read `PromptRegistry::renderWithMeta()`'s
     * truncation fact and low-trust-field list before sending the prompt,
     * neither of which this method's `LlmResult` return type has anywhere to
     * put. `$subjectType`/`$subjectId`/`$userId` are threaded through so a
     * future caller does not silently lose subject or actor attribution the
     * way it would have before this fix — but a caller that also needs the
     * truncation declaration should call `run()` with `renderWithMeta()`'s
     * text directly, the way `ExtractionDispatcher` does, rather than this
     * convenience method.
     *
     * Gate 2, Phase 11a, defect 1's third discard site was
     * `PromptRegistry::render(DocumentExtractor, ...)`, this method's own
     * former dependency — removed rather than fixed in place, since it had
     * no caller besides this one and this one has none of its own. `extract()`
     * now calls `renderKey()` directly; the discard this method's docblock
     * already warns about is unchanged, but there is one fewer method that
     * could quietly grow a second caller and inherit it.
     */
    public function extract(
        DocumentExtractor $extractor,
        string $documentText,
        ?int $organizationId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $userId = null,
    ): LlmResult {
        return $this->run(
            $extractor->value,
            $this->prompts->renderKey($extractor->value, $documentText),
            self::EVIDENCE_EXTRACTION,
            $organizationId,
            $subjectType,
            $subjectId,
            $userId,
        );
    }

    /**
     * Run any configured prompt, already rendered.
     *
     * The one door. `extract()` and the clause analyser both come through
     * here, so the kill switch and the usage ledger exist once rather than
     * once per caller — and a new caller cannot forget either.
     *
     * `$subjectType`/`$subjectId`/`$userId` ARE ADDITIVE, TRAILING AND
     * OPTIONAL — every existing call site keeps working unchanged. They exist
     * so `llm_usage_events.subject_type`/`.subject_id`/`.user_id` (contract
     * §3.1: "tracing a usage spike back to the document that caused it", and
     * to the person who caused it) can actually be populated for the caller
     * that has the clearest use for it. Pass a registered morph ALIAS
     * (`App\Support\MorphTypes`), never a raw FQCN — `enforceMorphMap()` is
     * on, and a stored FQCN is unreadable by every later query the same way
     * `App\Support\MorphTypes`'s own docblock describes for every other
     * polymorphic column in this product.
     *
     * `$userId` WAS UNWIRED (Gate 2 blocking defect 3): the column existed,
     * `UsageRecorder` wrote it, and nothing ever passed it, so every
     * user-initiated call recorded a null actor that the usage grid then
     * printed as "Scheduled" — an absence rendered as a false claim. Every
     * caller that runs inside an authenticated action now passes the actor
     * through here.
     */
    public function run(
        string $promptKey,
        string $renderedPrompt,
        string $service = self::EVIDENCE_EXTRACTION,
        ?int $organizationId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $userId = null,
    ): LlmResult {
        $prompt = $this->prompts->forKey($promptKey);
        $organizationId ??= TenantContext::organizationIdOrNull();

        // Contract §5 / §8.10: a call with no tenant context is refused and
        // writes NOTHING — there is no organisation to attribute a usage row
        // to, and `llm_usage_events.organization_id` is never null.
        if ($organizationId === null) {
            return LlmResult::unavailable(
                $prompt['version'],
                'No organisation context is available, so nothing was sent to the model.'
            );
        }

        if (! $this->enabled($service, $organizationId)) {
            return LlmResult::unavailable(
                $prompt['version'],
                'AI extraction is switched off for this installation. Every field on this document can be '
                .'entered by hand.'
            );
        }

        $call = new LlmCall(
            organizationId: $organizationId,
            module: 'tprm',
            service: $service,
            promptKey: $promptKey,
            promptVersion: $prompt['version'],
            prompt: $renderedPrompt,
            system: $prompt['system'],
            budget: $this->budgetFor($service),
            subjectType: $subjectType,
            subjectId: $subjectId,
            userId: $userId,
        );

        $outcome = $this->gateway->call($call);

        if (! $outcome->succeeded()) {
            return LlmResult::unavailable(
                $prompt['version'],
                ($outcome->reason ?? 'The extraction service is not reachable right now.')
                .' Every field on this document can be entered by hand.'
            );
        }

        return new LlmResult(
            data: $outcome->data,
            promptVersion: $prompt['version'],
            model: $outcome->model,
            promptTokens: $outcome->promptTokens,
            completionTokens: $outcome->completionTokens,
            durationMs: $outcome->durationMs,
            contextWindow: $outcome->contextWindow,
        );
    }

    /**
     * Which `services.llm.budgets` entry applies. Not a caller-supplied
     * argument — `run()`'s signature is unchanged by Phase 11a — so the
     * mapping lives here, next to the three services it serves.
     */
    private function budgetFor(string $service): string
    {
        return match ($service) {
            self::NARRATIVE_GENERATION => 'narrative',
            default => 'extraction',
        };
    }
}
