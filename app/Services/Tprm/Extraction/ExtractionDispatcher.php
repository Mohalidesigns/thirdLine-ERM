<?php

namespace App\Services\Tprm\Extraction;

use App\Enums\Tprm\DocumentExtractor;
use App\Models\Tprm\Document;
use App\Models\Tprm\DocumentExtraction;
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
     */
    public function dispatch(Document $document): ExtractionOutcome
    {
        $document->loadMissing('documentType');

        $extractor = $this->extractorFor($document);

        if ($extractor === null) {
            return ExtractionOutcome::skipped(
                'No extractor is defined for this document type. Enter the fields by hand.'
            );
        }

        if (! $this->llm->enabled()) {
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

        $errors = [];

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->llm->extract($extractor->extractor(), $sanitised->text, $document->organization_id);

            if (! $result->succeeded()) {
                $this->markStatus($document, 'failed');

                return ExtractionOutcome::failed((string) $result->message);
            }

            $payload = $result->data;
            $errors = $this->validator->validate($payload, $extractor->schema());

            if ($errors !== []) {
                continue;
            }

            return ExtractionOutcome::extracted(
                $this->persist($document, $extractor, $payload, $result, $sanitised->flags, $read['text'])
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
     */
    private function persist(
        Document $document,
        Extractor $extractor,
        array $payload,
        LlmResult $result,
        array $injectionFlags,
        string $documentText,
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
}
