<?php

namespace App\Services\Tprm\Contracts;

/**
 * Whether an engagement may activate, and the named reasons it may not.
 *
 * `blockingClauses` is structured rather than only rendered into the message,
 * because the screen has to do more than print it: each entry becomes a row
 * with a "request a waiver" action and the model text to send the vendor.
 */
class ActivationVerdict
{
    /**
     * @param  list<array<string, mixed>>  $blockingClauses
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly array $blockingClauses,
        public readonly ?ClauseResolution $resolution,
    ) {}

    public static function allowed(?ClauseResolution $resolution = null): self
    {
        return new self(true, null, [], $resolution);
    }

    /**
     * @param  list<array<string, mixed>>  $blockingClauses
     */
    public static function refused(string $reason, array $blockingClauses, ?ClauseResolution $resolution = null): self
    {
        return new self(false, $reason, $blockingClauses, $resolution);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'blocking_clauses' => $this->blockingClauses,
        ];
    }
}
