<?php

namespace App\Services\Bcms\Ai;

use App\Services\Bcms\BcmsSettings;
use App\Services\Llm\LlmCall;
use App\Services\Llm\LlmGateway;
use App\Services\LlmService;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The one place BCMS talks to a model — ADR 0010.
 *
 * THE KILL SWITCH IS CHECKED HERE AND NOWHERE ELSE, which is what makes "with
 * every AI capability disabled, every workflow still completes manually"
 * enforceable rather than aspirational. A caller cannot forget the check because
 * a caller cannot reach a model any other way.
 *
 * THREE SWITCHES, ALL OF WHICH MUST BE ON:
 *   · `services.llm.enabled` — the deployment has a model at all;
 *   · `bcms_settings.ai_enabled` — this TENANT has opted in, which is what a
 *     bank's model-risk function will actually ask about;
 *   · `bcms.ai.capabilities.<name>` — this particular capability. Blueprint §12
 *     lists eight, and an institution may well trust a model to draft an impact
 *     narrative and not to propose a recovery time objective.
 *
 * WHEN IT IS OFF IT REFUSES, IT DOES NOT THROW. A disabled optional service is
 * not an error condition, and a BIA workspace that 500s because AI is off has
 * failed worse than one with no AI at all.
 *
 * IT RETURNS A RESULT OBJECT, NEVER A BARE STRING. Every caller has to handle
 * "unavailable" and "the model said something unusable" as ordinary outcomes,
 * because on a locally hosted model both are ordinary.
 */
class BcmsLlmClient
{
    public const BIA_DRAFT = 'bia_draft';

    public const SCENARIO_GENERATOR = 'scenario_generator';

    public const AAR_SYNTHESIS = 'aar_synthesis';

    public const ALERT_COMPOSER = 'alert_composer';

    public const PLAN_DRAFT = 'plan_draft';

    public const PROGRAMME_ADVISOR = 'programme_advisor';

    public function __construct(
        private LlmService $llm,
        private BcmsSettings $settings,
        private LlmGateway $gateway,
    ) {}

    /**
     * Whether this capability may run at all.
     *
     * The config and tenant switches are checked BEFORE the endpoint is probed,
     * so a deployment with AI switched off never makes a network call to
     * discover that it is switched off.
     */
    public function enabled(string $capability, ?int $organizationId = null): bool
    {
        if (! config('services.llm.enabled')) {
            return false;
        }

        if (! $this->settings->for($organizationId)->ai_enabled) {
            return false;
        }

        return (bool) config('bcms.ai.capabilities.'.$capability, false);
    }

    public function available(string $capability, ?int $organizationId = null): bool
    {
        return $this->enabled($capability, $organizationId) && $this->llm->available();
    }

    /**
     * Why the capability is unavailable, in words a screen can print.
     *
     * A greyed-out button with no explanation gets raised as a bug; one that
     * says which switch is off gets fixed by whoever can fix it.
     */
    public function unavailableReason(string $capability, ?int $organizationId = null): ?string
    {
        if (! config('services.llm.enabled')) {
            return 'No language model is configured for this deployment.';
        }

        if (! $this->settings->for($organizationId)->ai_enabled) {
            return 'AI assistance is switched off for this organisation. An administrator can enable it in BCMS settings.';
        }

        if (! config('bcms.ai.capabilities.'.$capability, false)) {
            return 'This particular AI capability is switched off for this deployment.';
        }

        if (! $this->llm->available()) {
            return 'The language model is not responding. Everything on this screen can still be completed by hand.';
        }

        return null;
    }

    /**
     * Ask for a JSON answer.
     *
     * PHASE 11A: routes through the platform `LlmGateway` instead of calling
     * `LlmService::json()` directly — ADR 0015 §9. `BiaAiDrafter`,
     * `PlanAiDrafter` and `ProgrammeAdvisor` call this method exactly as
     * before; its PUBLIC API AND RETURN SHAPE ARE UNCHANGED. What changed
     * internally: BCMS calls now carry transport retry, a circuit breaker and
     * a recorded `llm_usage_events` row, the same as TPRM's.
     *
     * `$context` remains unused by this method (kept for API compatibility —
     * BCMS's own prompt builders already fold context into `$prompt` before
     * calling this) and is logged nowhere any more: the gateway's own usage
     * row is the log.
     *
     * `$userId` — Gate 2 blocking defect 3, ADDITIVE AND TRAILING so `json()`'s
     * public API stays exactly as the phase-11a docblock above promises.
     * Defaults to `auth()->id()` because every current caller —
     * `BiaAiDrafter`, `PlanAiDrafter`, `ProgrammeAdvisor` — runs inside a
     * controller action on the request's own authenticated user; a future
     * caller running outside a request (a job, a console command) would get
     * `null` from `auth()->id()` there and should pass the real actor
     * explicitly rather than rely on this default.
     *
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, data: array<string, mixed>, reason: ?string}
     */
    public function json(string $capability, string $prompt, array $context = [], ?int $organizationId = null, ?int $userId = null): array
    {
        $organizationId ??= TenantContext::organizationIdOrNull();
        $userId ??= auth()->id();

        $reason = $this->unavailableReason($capability, $organizationId);

        if ($reason !== null) {
            return ['ok' => false, 'data' => [], 'reason' => $reason];
        }

        // unavailableReason() above already confirmed a non-null
        // organisation (it returns non-null when ai_enabled cannot be
        // resolved for one), but PHPStan cannot see that across two methods,
        // so this is asserted rather than re-checked.
        if ($organizationId === null) {
            return ['ok' => false, 'data' => [], 'reason' => 'No organisation context is available.'];
        }

        $call = new LlmCall(
            organizationId: $organizationId,
            module: 'bcms',
            service: $capability,
            promptKey: $capability,
            // BCMS does not version its AI prompts the way TPRM's
            // PromptRegistry does — its three drafters are untouched by this
            // phase (ADR 0015 §9) and this column exists for TPRM's
            // reproducibility requirement, not BCMS's.
            promptVersion: 'unversioned',
            prompt: $prompt,
            budget: 'medium',
            userId: $userId,
        );

        $outcome = $this->gateway->call($call);

        if (! $outcome->succeeded()) {
            return [
                'ok' => false,
                'data' => [],
                // Deliberately not "the model failed": on a locally hosted model
                // an unparseable answer is the commonest outcome and the user's
                // next step is the same either way.
                'reason' => $outcome->reason ?? 'The model did not return an answer this screen could use. '
                    .'Fill the assessment in by hand, or try again.',
            ];
        }

        return ['ok' => true, 'data' => $outcome->data, 'reason' => null];
    }
}
