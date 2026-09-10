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
 */
class ClauseAnalysisOutcome
{
    /**
     * @param  list<string>  $injectionFlags
     */
    private function __construct(
        public readonly string $state,
        public readonly ?string $message,
        public readonly int $detected,
        public readonly int $applicable,
        public readonly array $injectionFlags = [],
    ) {}

    /**
     * @param  list<string>  $injectionFlags
     */
    public static function analysed(int $detected, int $applicable, array $injectionFlags = []): self
    {
        return new self('analysed', null, $detected, $applicable, $injectionFlags);
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
        return $this->state === 'analysed';
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
        ];
    }
}
