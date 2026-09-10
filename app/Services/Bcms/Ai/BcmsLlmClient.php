<?php

namespace App\Services\Bcms\Ai;

use App\Services\Bcms\BcmsSettings;
use App\Services\LlmService;
use Illuminate\Support\Facades\Log;

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
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, data: array<string, mixed>, reason: ?string}
     */
    public function json(string $capability, string $prompt, array $context = [], ?int $organizationId = null): array
    {
        $reason = $this->unavailableReason($capability, $organizationId);

        if ($reason !== null) {
            return ['ok' => false, 'data' => [], 'reason' => $reason];
        }

        $started = microtime(true);
        $data = $this->llm->json($prompt);

        Log::info('BCMS AI call', [
            'capability' => $capability,
            'organization_id' => $organizationId,
            'model' => config('services.llm.model'),
            'ms' => (int) ((microtime(true) - $started) * 1000),
            'ok' => $data !== [],
            'context_keys' => array_keys($context),
        ]);

        if ($data === []) {
            return [
                'ok' => false,
                'data' => [],
                // Deliberately not "the model failed": on a locally hosted model
                // an unparseable answer is the commonest outcome and the user's
                // next step is the same either way.
                'reason' => 'The model did not return an answer this screen could use. Fill the assessment in by hand, or try again.',
            ];
        }

        return ['ok' => true, 'data' => $data, 'reason' => null];
    }
}
