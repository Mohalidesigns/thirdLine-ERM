<?php

namespace App\Services\Tprm\Extraction;

use App\Enums\Tprm\DocumentExtractor;
use App\Services\LlmService;
use Illuminate\Support\Facades\Log;

/**
 * The one place TPRM talks to a model — TRD §12.1.
 *
 * THE KILL SWITCH IS CHECKED HERE AND NOWHERE ELSE, which is what makes AC-16
 * ("with every AI service disabled, all workflows complete manually")
 * enforceable rather than aspirational. A caller cannot forget the check
 * because a caller cannot reach a model except through this class, and when
 * the switch is off this returns a refusal rather than throwing — a disabled
 * optional service is not an error condition, and a screen that 500s when AI
 * is off has failed AC-16 as surely as one that calls the model anyway.
 *
 * TWO FLAGS, BOTH OF WHICH MUST BE ON. `tprm.ai.enabled` is the tenant-level
 * master switch; `tprm.ai.services.<service>` is the per-service one — there
 * are seven, and evidence extraction and clause analysis are separate because
 * an institution may well trust a model to read a certificate's expiry date
 * and not to read its contracts. Either flag being off is enough to stop the
 * call, so an operator who turns off the master switch during an incident does
 * not have to find all seven.
 *
 * EVERY CALL IS LOGGED with the model, the prompt version and the backend's
 * own token counts. Not an estimate: a cost log built on our own arithmetic is
 * one nobody can reconcile against a bill.
 */
class LlmClient
{
    public const EVIDENCE_EXTRACTION = 'evidence_extraction';

    public const CLAUSE_ANALYSIS = 'clause_analysis';

    public function __construct(
        private readonly LlmService $llm,
        private readonly PromptRegistry $prompts,
    ) {}

    /**
     * Whether extraction may run at all.
     *
     * Note the order: the config switches are checked before the endpoint is
     * probed, so a deployment with AI switched off never makes a network call
     * to discover that it is switched off.
     */
    public function enabled(string $service = self::EVIDENCE_EXTRACTION): bool
    {
        return (bool) config('tprm.ai.enabled')
            && (bool) config('tprm.ai.services.'.$service);
    }

    public function available(string $service = self::EVIDENCE_EXTRACTION): bool
    {
        return $this->enabled($service) && $this->llm->available();
    }

    /**
     * Run an extraction prompt against document text.
     *
     * @return LlmResult
     */
    public function extract(DocumentExtractor $extractor, string $documentText, ?int $organizationId = null): LlmResult
    {
        return $this->run(
            $extractor->value,
            $this->prompts->render($extractor, $documentText),
            self::EVIDENCE_EXTRACTION,
            $organizationId,
        );
    }

    /**
     * Run any configured prompt, already rendered.
     *
     * The one door. `extract()` and the clause analyser both come through
     * here, so the kill switch, the availability probe and the per-call log
     * exist once rather than once per caller — and a new caller cannot forget
     * any of the three.
     */
    public function run(
        string $promptKey,
        string $renderedPrompt,
        string $service = self::EVIDENCE_EXTRACTION,
        ?int $organizationId = null,
    ): LlmResult {
        $prompt = $this->prompts->forKey($promptKey);

        if (! $this->enabled($service)) {
            return LlmResult::unavailable(
                $prompt['version'],
                'AI extraction is switched off for this installation. Every field on this document can be '
                .'entered by hand.'
            );
        }

        if (! $this->llm->available()) {
            return LlmResult::unavailable(
                $prompt['version'],
                'The extraction service is not reachable right now: '.($this->llm->lastError() ?? 'no detail given')
                .'. Every field on this document can be entered by hand.'
            );
        }

        $response = $this->llm->jsonWithUsage(
            $renderedPrompt,
            $prompt['system'],
            ['max_tokens' => 2048],
        );

        $this->log($promptKey, $prompt['version'], $response, $organizationId);

        if ($response['data'] === []) {
            return LlmResult::failed($prompt['version'], $response['model'], $response['error'] ?? 'The model returned nothing usable.');
        }

        return new LlmResult(
            data: $response['data'],
            promptVersion: $prompt['version'],
            model: $response['model'],
            promptTokens: $response['prompt_tokens'],
            completionTokens: $response['completion_tokens'],
            durationMs: $response['duration_ms'],
        );
    }

    /**
     * @param  array{model: string, prompt_tokens: int|null, completion_tokens: int|null, duration_ms: int, error: string|null}  $response
     */
    private function log(string $promptKey, string $promptVersion, array $response, ?int $organizationId): void
    {
        Log::channel(config('logging.default'))->info('TPRM model call', [
            'organization_id' => $organizationId,
            'prompt_key' => $promptKey,
            'prompt_version' => $promptVersion,
            'model' => $response['model'],
            'prompt_tokens' => $response['prompt_tokens'],
            'completion_tokens' => $response['completion_tokens'],
            'duration_ms' => $response['duration_ms'],
            'error' => $response['error'],
        ]);
    }
}
