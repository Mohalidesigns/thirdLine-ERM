<?php

namespace App\Services\Tprm\Contracts;

/**
 * What a clause-analysis run produced.
 *
 * `manual` is a first-class outcome rather than a failure, and the distinction
 * reaches the screen. AI switched off, a scanned PDF, no document attached —
 * in every one of those the clause list is on the page and each row can be
 * determined by hand. The gap report and the activation gate work identically.
 * A screen that reported those as errors would teach a user that the module is
 * broken when it is doing exactly what it was configured to do.
 *
 * `analysed_partial` IS A DIFFERENT STATE FROM `analysed`, NOT A DECORATION ON
 * IT (Gate 2, Phase 11a, defect 1). The document was too long for
 * `PromptRegistry`'s cap and the model read only the first part of it, so
 * `detected` still equals `applicable` — every clause got a verdict — but an
 * unknown number of those verdicts are "absent" only because the reader never
 * reached the paragraph that would have said otherwise. `succeeded()` still
 * returns true (a determination exists for every applicable clause and the
 * reviewer queue is exactly as usable as it is for a full read); only the
 * caller's WORDING must differ, per §7.5's rules: state the cap and the
 * original length as measured facts, never say the contract was read in full.
 */
class ClauseAnalysisOutcome
{
    /**
     * @param  list<string>  $injectionFlags
     * @param  array{cap: int, original_length: int}|null  $documentTruncated
     */
    private function __construct(
        public readonly string $state,
        public readonly ?string $message,
        public readonly int $detected,
        public readonly int $applicable,
        public readonly array $injectionFlags = [],
        public readonly ?array $documentTruncated = null,
    ) {}

    /**
     * @param  list<string>  $injectionFlags
     */
    public static function analysed(int $detected, int $applicable, array $injectionFlags = []): self
    {
        return new self('analysed', null, $detected, $applicable, $injectionFlags);
    }

    /**
     * @param  list<string>  $injectionFlags
     * @param  array{cap: int, original_length: int}  $documentTruncated
     */
    public static function analysedPartial(
        int $detected,
        int $applicable,
        array $injectionFlags,
        array $documentTruncated,
    ): self {
        return new self('analysed_partial', null, $detected, $applicable, $injectionFlags, $documentTruncated);
    }

    public static function manual(string $message, int $applicable): self
    {
        return new self('manual', $message, 0, $applicable);
    }

    public static function skipped(string $message): self
    {
        return new self('skipped', $message, 0, 0);
    }

    public function succeeded(): bool
    {
        return $this->state === 'analysed' || $this->state === 'analysed_partial';
    }

    /**
     * Whether the run completed against only part of the document. Every
     * caller printing a coverage claim must branch on this before printing
     * one.
     */
    public function partial(): bool
    {
        return $this->state === 'analysed_partial';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'message' => $this->message,
            'detected' => $this->detected,
            'applicable' => $this->applicable,
            'injection_flagged' => $this->injectionFlags !== [],
            'injection_flags' => $this->injectionFlags,
            'document_truncated' => $this->documentTruncated,
        ];
    }
}
