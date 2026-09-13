<?php

namespace App\Services\Tprm\Extraction;

use App\Enums\Tprm\DocumentExtractor;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentExtraction;
use App\Services\Llm\CircuitBreaker;
use App\Services\Llm\EndpointResolver;
use App\Services\Tprm\Ai\TprmAiPolicy;
use App\Services\Tprm\Evidence\CitationVerifier;
use App\Services\Tprm\Evidence\ExtractionGuard;
use App\Services\Tprm\Extraction\Extractors\BcpTestExtractor;
use App\Services\Tprm\Extraction\Extractors\DpaExtractor;
use App\Services\Tprm\Extraction\Extractors\FinancialsExtractor;
use App\Services\Tprm\Extraction\Extractors\InsuranceExtractor;
use App\Services\Tprm\Extraction\Extractors\IsoCertExtractor;
use App\Services\Tprm\Extraction\Extractors\PciAocExtractor;
use App\Services\Tprm\Extraction\Extractors\PentestExtractor;
use App\Services\Tprm\Extraction\Extractors\Soc2Extractor;

/**
 * The extraction pipeline — TRD §12.1, and the one path a document takes.
 *
 * READ THE ORDER, IT IS THE DESIGN.
 *
 *   1. Text out of the file, or stop. An empty string is never sent to a
 *      model: an empty document produces a confident extraction of nothing.
 *   2. `ExtractionGuard` strips and FLAGS instruction-like content. The flag
 *      travels with the extraction and is shown to whoever confirms it.
 *   3. The prompt comes from `PromptRegistry` with its version, wrapping the
 *      document in an explicit data delimiter.
 *   4. The response is validated against the extractor's schema. ONE RETRY,
 *      then manual fallback — a model that returned the wrong shape twice will
 *      not return the right one on the third attempt, and each retry costs a
 *      call and a wait.
 *   5. `CitationVerifier` checks every quoted string against the document
 *      text. A failure does not discard the extraction; it caps its confidence
 *      in the low band and marks the fields that failed, so a human sees
 *      exactly which claims the document does not support.
 *   6. The row is written `pending`. NOTHING IS APPLIED. That is not a policy
 *      this class implements, it is the only thing it can do — applying is a
 *      different service that requires a human's confirmation first.
 *
 * WITH AI OFF, THIS CLASS RETURNS A REASON AND WRITES NOTHING (AC-16). The
 * caller renders the manual form. There is no code path where an extraction
 * row appears without a model having produced it.
 */
class ExtractionDispatcher
{
    /** TRD §12.1: schema validation with one retry, then manual fallback. */
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly DocumentTextExtractor $text,
        private readonly ExtractionGuard $guard,
        private readonly LlmClient $llm,
        private readonly SchemaValidator $validator,
        private readonly CitationVerifier $citations,
        private readonly PromptRegistry $prompts,
        private readonly TprmAiPolicy $policy,
        private readonly EndpointResolver $endpoints,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * The extractor for a document, or null when its type has none.
     */
    public function extractorFor(Document $document): ?Extractor
    {
        $type = $document->documentType?->extractor;

        if ($type === null || $type === DocumentExtractor::Generic) {
            return null;
        }

        /**
         * A map rather than a `match`, so that a document type naming an
         * extractor this build does not have returns null and falls through to
         * manual entry. `match` would throw, and an unhandled enum case taking
         * down an upload screen is a worse failure than a form.
         *
         * @var array<string, class-string<Extractor>>
         */
        $extractors = [
            DocumentExtractor::Soc2->value => Soc2Extractor::class,
            DocumentExtractor::IsoCert->value => IsoCertExtractor::class,
            DocumentExtractor::PciAoc->value => PciAocExtractor::class,
            DocumentExtractor::Pentest->value => PentestExtractor::class,
            DocumentExtractor::Insurance->value => InsuranceExtractor::class,
            DocumentExtractor::Financials->value => FinancialsExtractor::class,
            DocumentExtractor::BcpTest->value => BcpTestExtractor::class,
            DocumentExtractor::Dpa->value => DpaExtractor::class,
        ];

        $class = $extractors[$type->value] ?? null;

        return $class === null ? null : new $class;
    }

    /**
     * Run extraction for a document.
     *
     * `$userId` — Gate 2 blocking defect 3. `RunTprmDocumentExtraction` has
     * the actor on its own `JobRun` (`$jobRun->created_by`, set from the
     * uploader/requester at dispatch time or `auth()->id()` for an inline
     * trigger); threading it here is what lets `llm_usage_events.user_id`
     * distinguish a person's extraction from a genuinely unattended one
     * instead of leaving every extraction to print as "Scheduled".
     */
    public function dispatch(Document $document, ?int $userId = null): ExtractionOutcome
    {
        $document->loadMissing('documentType');

        $extractor = $this->extractorFor($document);

        if ($extractor === null) {
            return ExtractionOutcome::skipped(
                'No extractor is defined for this document type. Enter the fields by hand.'
            );
        }

        if (! $this->llm->enabled(LlmClient::EVIDENCE_EXTRACTION, $document->organization_id)) {
            $this->markStatus($document, 'unavailable');

            return ExtractionOutcome::skipped(
                'AI extraction is switched off for this installation. Every field on this document can be '
                .'entered by hand.'
            );
        }

        $read = $this->text->textFor($document);

        if ($read['text'] === null) {
            $this->markStatus($document, 'unavailable');

            return ExtractionOutcome::skipped((string) $read['reason']);
        }

        $sanitised = $this->guard->sanitise($read['text']);

        // Phase 11a, ADR 0015 §6b: rendered once here — not inside
        // `LlmClient::extract()` — so the truncation fact and the prompt's
        // declared `low_trust_fields` are both available to `persist()`
        // without widening `LlmClient`'s own return type.
        $promptKey = $extractor->extractor()->value;
        $rendered = $this->prompts->renderWithMeta($promptKey, $sanitised->text);

        $errors = [];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1 && $this->breakerIsOpen($document->organization_id)) {
                // ADR 0015 §6: the schema retry and the gateway's transport
                // retry must not multiply. Extraction now runs on the queue
                // (§6a), so a DIFFERENT worker's calls can trip the breaker
                // between this job's first and second attempt; there is no
                // reason to build and send a second prompt to a box the
                // breaker has already given up on for this minute.
                $this->markStatus($document, 'failed');

                return ExtractionOutcome::failed(
                    'The extraction service failed repeatedly and is temporarily paused, so only one attempt '
                    .'was made. Every field on this document can be entered by hand.'
                );
            }

            $result = $this->llm->run(
                $promptKey,
                $rendered['text'],
                LlmClient::EVIDENCE_EXTRACTION,
                $document->organization_id,
                $document->getMorphClass(),
                $document->getKey(),
                $userId,
            );

            if (! $result->succeeded()) {
                $this->markStatus($document, 'failed');

                return ExtractionOutcome::failed((string) $result->message);
            }

            $payload = $this->validator->coerce($result->data, $extractor->schema());
            $errors = $this->validator->validate($payload, $extractor->schema());

            if ($errors !== []) {
                continue;
            }

            return ExtractionOutcome::extracted(
                $this->persist($document, $extractor, $payload, $result, $sanitised->flags, $read['text'], $rendered)
            );
        }

        $this->markStatus($document, 'failed');

        // The schema failures are named rather than summarised. "The model
        // returned an invalid response" tells an administrator nothing; "the
        // field `period_end` should be a date but was array" tells them
        // whether this is a prompt problem or a model problem.
        return ExtractionOutcome::failed(
            'The extraction did not match the expected shape after '.self::MAX_ATTEMPTS.' attempts, so the '
            .'fields are left for manual entry. '.implode(' ', $errors)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $injectionFlags
     * @param  array{text: string, document_truncated: array{cap: int, original_length: int}|null, low_trust_fields: list<string>}  $rendered
     */
    private function persist(
        Document $document,
        Extractor $extractor,
        array $payload,
        LlmResult $result,
        array $injectionFlags,
        string $documentText,
        array $rendered,
    ): DocumentExtraction {
        $citations = $this->citationsFrom($payload);
        $verification = $this->citations->verify($citations, $documentText);

        $reported = $this->reportedConfidence($payload);
        $confidence = $verification->adjustedConfidence($reported);

        $extraction = DocumentExtraction::create([
            'organization_id' => $document->organization_id,
            'document_id' => $document->getKey(),
            'extractor' => $extractor->extractor()->value,
            'model' => $result->model,
            'prompt_version' => $result->promptVersion,
            'extracted' => $extractor->normalise($payload) + [
                // Travels WITH the extraction, so the confirmation screen
                // shows it beside the fields rather than in a log nobody
                // opens. A document carrying instruction-like content is a
                // fact about the vendor.
                '_meta' => [
                    'injection_flags' => $injectionFlags,
                    'citation_check' => $verification->toArray(),
                    'reported_confidence' => $reported,
                    'below_threshold' => $confidence < (float) config('tprm.ai.confidence_threshold', 0.7),
                    'tokens' => [
                        'prompt' => $result->promptTokens,
                        'completion' => $result->completionTokens,
                    ],
                    'duration_ms' => $result->durationMs,
                    // ADR 0015 §6b: declared, never silent. A null
                    // `document_truncated` means only that OUR cap did not
                    // cut it — it says nothing about whether the server's own
                    // context window did. `context_window` below is what
                    // answers that question, and only that question.
                    'document_truncated' => $rendered['document_truncated'],
                    'low_trust_fields' => $rendered['low_trust_fields'],
                    // ADR 0015 §6d, phase-11a-ai-contract.md §2.5. `fitted`
                    // is computed HERE, server-side, and nowhere else — never
                    // in JavaScript — because it is the one comparison the
                    // confirmation screen must never be able to get subtly
                    // different from an export.
                    'context_window' => $this->contextWindowMeta($result),
                ],
            ],
            'confidence' => $confidence,
            'citations' => $verification->toArray(),
            'status' => DocumentExtraction::STATUS_PENDING,
        ]);

        $this->markStatus($document, 'extracted');

        return $extraction;
    }

    /**
     * ADR 0015 §6d, phase-11a-ai-contract.md §2.5 — the exact three-valued
     * truth table. `fitted` is `true` ONLY when both a declared window and a
     * reported prompt-token count exist and the count strictly clears the
     * window: a truncated prompt fills it. `>=` proves nothing (it is
     * indistinguishable from a much larger prompt cut down to fit) and a
     * missing count proves nothing either — both cases are `null`, never
     * `false`. "We checked and it fits" must never look like "we could not
     * check", so a missing `prompt_tokens` or `num_ctx` is NEVER coerced to 0
     * and NEVER defaulted to `false`.
     *
     * @return array{num_ctx: int|null, prompt_tokens: int|null, fitted: bool|null}
     */
    private function contextWindowMeta(LlmResult $result): array
    {
        $numCtx = $result->contextWindow;
        $promptTokens = $result->promptTokens;

        $fitted = match (true) {
            $numCtx === null => null,
            $promptTokens === null => null,
            $promptTokens < $numCtx => true,
            default => false,
        };

        return [
            'num_ctx' => $numCtx,
            'prompt_tokens' => $promptTokens,
            'fitted' => $fitted,
        ];
    }

    /**
     * The citations the model returned, in the shape `CitationVerifier` reads.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{field?: string, quote?: string, page?: int|string|null}>
     */
    private function citationsFrom(array $payload): array
    {
        $citations = $payload['citations'] ?? [];

        if (! is_array($citations)) {
            return [];
        }

        return array_values(array_filter($citations, 'is_array'));
    }

    /**
     * The model's own confidence, if it offered one.
     *
     * Defaults to the configured threshold rather than to 1.0: an extraction
     * that made no confidence claim should sit exactly on the boundary and be
     * confirmed, not sail past it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function reportedConfidence(array $payload): float
    {
        $reported = $payload['confidence'] ?? null;

        return is_numeric($reported)
            ? max(0.0, min(1.0, (float) $reported))
            : (float) config('tprm.ai.confidence_threshold', 0.7);
    }

    private function markStatus(Document $document, string $status): void
    {
        $document->forceFill(['extraction_status' => $status])->save();
    }

    /**
     * A PURE READ — never `CircuitBreaker::allows()`, which transitions an
     * expired OPEN breaker to HALF_OPEN and admits exactly one probe as a
     * side effect. Calling that here to decide whether to SKIP an attempt
     * would consume the one admitted probe on a call that is then never
     * made, leaving the breaker stuck half-open with nothing to resolve it.
     */
    private function breakerIsOpen(int $organizationId): bool
    {
        $snapshot = $this->policy->snapshot($organizationId, LlmClient::EVIDENCE_EXTRACTION);
        $profile = $this->endpoints->resolve($snapshot->endpointProfileKey);

        return $this->breaker->state($profile->key) === 'open';
    }
}
