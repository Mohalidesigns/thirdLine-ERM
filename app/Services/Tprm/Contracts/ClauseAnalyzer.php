<?php

namespace App\Services\Tprm\Contracts;

use App\Enums\Tprm\ClausePresence;
use App\Models\Tprm\ClauseLibraryEntry;
use App\Models\Tprm\Contract;
use App\Models\Tprm\ContractClause;
use App\Services\Tprm\Evidence\CitationVerifier;
use App\Services\Tprm\Evidence\ExtractionGuard;
use App\Services\Tprm\Extraction\DocumentTextExtractor;
use App\Services\Tprm\Extraction\LlmClient;
use App\Services\Tprm\Extraction\PromptRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Clause detection — TRD §12.3.
 *
 * "For each clause detect `present | partial | absent`, return located text
 * with page reference and confidence, and for gaps return the suggested model
 * text and the citation. Human confirmation per clause."
 *
 * PARTIAL IS THE VERDICT THAT MAKES THIS WORTH BUILDING, and the prompt pushes
 * doubt towards it. A contract that mentions breach notification without a
 * timeframe, or grants an audit right "subject to the provider's consent", has
 * the subject and not the obligation. Those are the clauses that pass a manual
 * review by someone scanning for headings, and they are the ones a regulator
 * finds. A reviewer correcting `partial` upwards has read the clause; a
 * reviewer accepting a wrong `present` has not.
 *
 * EVERY DETECTION LANDS `reviewer_status = pending` AND THE GATE IGNORES IT
 * UNTIL ACCEPTED. `ContractClause::isSatisfied()` requires both `present` and
 * `accepted`, so an unreviewed machine detection cannot admit a vendor. That
 * is not belt-and-braces: a model that reads "the Provider shall not be
 * obliged to permit audits" as an audit-rights clause has produced exactly the
 * output that would otherwise clear the gate.
 *
 * ABSENT IS RECORDED, NOT INFERRED FROM SILENCE. A clause the analyser did not
 * return gets an `absent` row of its own, because the register has to
 * distinguish "we looked and it is not there" from "nobody has looked".
 */
class ClauseAnalyzer
{
    /** The versioned prompt in config/tprm_prompts.php. */
    private const PROMPT_KEY = 'clause_analysis';

    public function __construct(
        private readonly ClauseResolver $resolver,
        private readonly DocumentTextExtractor $text,
        private readonly ExtractionGuard $guard,
        private readonly LlmClient $llm,
        private readonly CitationVerifier $citations,
        private readonly PromptRegistry $prompts,
    ) {}

    /**
     * Analyse a contract against its applicable clause set.
     */
    public function analyse(Contract $contract, ?int $userId = null): ClauseAnalysisOutcome
    {
        $contract->loadMissing(['engagement', 'document']);

        if ($contract->engagement === null) {
            return ClauseAnalysisOutcome::skipped('This contract is not attached to an engagement.');
        }

        $resolution = $this->resolver->resolve($contract->engagement, $contract);
        $applicable = $resolution->applicable;

        if ($applicable->isEmpty()) {
            return ClauseAnalysisOutcome::skipped('No clauses in the library apply to this engagement.');
        }

        // Every applicable clause gets a row before anything is read, so a
        // contract nobody could analyse still shows its full gap list rather
        // than an empty table that reads as a clean bill of health.
        $this->seedAbsentRows($contract, $applicable);

        if ($contract->document === null) {
            return ClauseAnalysisOutcome::manual(
                'No contract document is attached, so the clauses are listed for manual determination.',
                $applicable->count(),
            );
        }

        if (! $this->llm->enabled(LlmClient::CLAUSE_ANALYSIS)) {
            return ClauseAnalysisOutcome::manual(
                'Automatic clause analysis is switched off for this installation. Every clause below can be '
                .'determined by hand, and the gap report and the activation gate work identically either way.',
                $applicable->count(),
            );
        }

        $read = $this->text->textFor($contract->document);

        if ($read['text'] === null) {
            return ClauseAnalysisOutcome::manual((string) $read['reason'], $applicable->count());
        }

        $sanitised = $this->guard->sanitise($read['text']);

        $result = $this->llm->run(
            self::PROMPT_KEY,
            $this->prompts->renderKey(self::PROMPT_KEY, $sanitised->text, $this->clauseList($applicable)),
            LlmClient::CLAUSE_ANALYSIS,
            $contract->organization_id,
        );

        if (! $result->succeeded()) {
            return ClauseAnalysisOutcome::manual(
                (string) $result->message,
                $applicable->count(),
            );
        }

        $detected = $this->persist($contract, $applicable, $result->data, $read['text']);

        $contract->forceFill(['clause_analysis_status' => 'analysed'])->save();
        app(ContractService::class)->refreshBlockingGapCount($contract);

        return ClauseAnalysisOutcome::analysed($detected, $applicable->count(), $sanitised->flags);
    }

    /**
     * A determination recorded by a person — the manual path, and the same
     * rows the machine path writes.
     */
    public function record(
        Contract $contract,
        ClauseLibraryEntry $clause,
        ClausePresence $presence,
        ?string $locatedText = null,
        ?string $pageReference = null,
        ?int $userId = null,
    ): ContractClause {
        $row = ContractClause::updateOrCreate(
            ['contract_id' => $contract->getKey(), 'clause_library_id' => $clause->getKey()],
            ['organization_id' => $contract->organization_id],
        );

        $row->forceFill([
            'presence' => $presence->value,
            'located_text' => $locatedText,
            'page_reference' => $pageReference,
            'detected_by' => ContractClause::DETECTED_BY_MANUAL,
            // A person recording a determination has reviewed it by the act of
            // recording it. There is no second confirmation to ask them for.
            'reviewer_status' => ContractClause::REVIEW_ACCEPTED,
            'reviewer_id' => $userId,
            'reviewed_at' => now(),
            'confidence' => null,
        ])->save();

        app(ContractService::class)->refreshBlockingGapCount($contract);

        return $row->refresh();
    }

    /**
     * Accept or reject a machine detection — the human confirmation §12.3
     * requires.
     */
    public function review(ContractClause $row, bool $accept, ?ClausePresence $correctedTo = null, ?int $userId = null): ContractClause
    {
        $row->forceFill([
            'presence' => ($correctedTo ?? $row->presence)->value,
            'reviewer_status' => $accept
                ? ContractClause::REVIEW_ACCEPTED
                : ContractClause::REVIEW_REJECTED,
            'reviewer_id' => $userId,
            'reviewed_at' => now(),
        ])->save();

        app(ContractService::class)->refreshBlockingGapCount($row->contract);

        return $row->refresh();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClauseLibraryEntry>  $applicable
     */
    private function seedAbsentRows(Contract $contract, $applicable): void
    {
        DB::transaction(function () use ($contract, $applicable) {
            foreach ($applicable as $clause) {
                ContractClause::firstOrCreate(
                    ['contract_id' => $contract->getKey(), 'clause_library_id' => $clause->getKey()],
                    [
                        'organization_id' => $contract->organization_id,
                        'presence' => ClausePresence::Absent->value,
                        'detected_by' => ContractClause::DETECTED_BY_MANUAL,
                        'reviewer_status' => ContractClause::REVIEW_PENDING,
                    ]
                );
            }
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClauseLibraryEntry>  $applicable
     * @param  array<mixed>  $payload
     * @return int how many clauses the analyser reached a verdict on
     */
    private function persist(Contract $contract, $applicable, array $payload, string $documentText): int
    {
        $byCode = $applicable->keyBy('code');
        $rows = is_array($payload['clauses'] ?? null) ? $payload['clauses'] : [];
        $detected = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clause = $byCode->get((string) ($row['code'] ?? ''));

            // A code the model invented, or one that does not apply to this
            // engagement, is dropped rather than recorded. A gap report
            // carrying a clause nobody asked about is a report that gets
            // argued with instead of acted on.
            if ($clause === null) {
                continue;
            }

            $presence = ClausePresence::tryFrom((string) ($row['presence'] ?? '')) ?? ClausePresence::Absent;
            $quote = is_string($row['located_text'] ?? null) ? trim($row['located_text']) : null;

            // The quote has to be IN the contract. A located_text the document
            // does not contain is the single most damaging output here: it
            // reads as proof the clause is present, and a reviewer scanning
            // for the quote finds it in the panel rather than in the contract.
            $verified = $quote === null
                ? null
                : $this->citations->verify([['field' => (string) $clause->code, 'quote' => $quote]], $documentText);

            $trustworthy = $verified?->isTrustworthy() ?? true;

            ContractClause::updateOrCreate(
                ['contract_id' => $contract->getKey(), 'clause_library_id' => $clause->getKey()],
                ['organization_id' => $contract->organization_id],
            )->forceFill([
                // An unverifiable quote demotes the verdict to absent rather
                // than keeping a `present` nobody can check.
                'presence' => $trustworthy ? $presence->value : ClausePresence::Absent->value,
                'located_text' => $trustworthy ? $quote : null,
                'page_reference' => is_scalar($row['page_reference'] ?? null)
                    ? (string) $row['page_reference']
                    : null,
                'confidence' => $this->confidence($row, $trustworthy),
                'detected_by' => ContractClause::DETECTED_BY_AI,
                // Pending, always. The gate ignores it until a person accepts.
                'reviewer_status' => ContractClause::REVIEW_PENDING,
            ])->save();

            $detected++;
        }

        return $detected;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function confidence(array $row, bool $trustworthy): ?float
    {
        $reported = is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null;

        if (! $trustworthy) {
            // The low-confidence cap, same number and same reasoning as the
            // document extractor's.
            return min($reported ?? 0.3, 0.3);
        }

        return $reported === null ? null : max(0.0, min(1.0, $reported));
    }

    /**
     * The clause codes to look for, appended to the stored prompt at call
     * time.
     *
     * The guidance travels with each code because it is what tells the model
     * where the line between present and partial falls for THAT clause — an
     * audit-rights clause and a data-retention clause fail in different ways,
     * and a generic instruction cannot say how.
     *
     * @param  \Illuminate\Support\Collection<int, ClauseLibraryEntry>  $applicable
     */
    private function clauseList($applicable): string
    {
        $lines = $applicable->map(fn (ClauseLibraryEntry $clause) => sprintf(
            '- %s: %s%s',
            $clause->code,
            $clause->title,
            $clause->guidance ? ' — '.$clause->guidance : ''
        ))->implode("\n");

        return "The clauses to look for:\n".$lines;
    }
}
