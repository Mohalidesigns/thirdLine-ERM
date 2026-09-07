<?php

namespace App\Services\Tprm\Access;

/**
 * Whether an engagement may terminate, and what is still plugged in if not.
 *
 * `blockers` is structured for the same reason `ActivationVerdict`'s is: the
 * screen needs a row per obstacle with the action that clears it. A user told
 * "there is still open access" and not told WHICH will either give up or
 * terminate the engagement some other way.
 */
class TerminationVerdict
{
    /**
     * @param  list<array<string, mixed>>  $blockers
     * @param  list<array<string, mixed>>  $excepted
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly array $blockers,
        public readonly array $excepted,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $excepted
     */
    public static function allowed(array $excepted = []): self
    {
        return new self(true, null, [], $excepted);
    }

    /**
     * @param  list<array<string, mixed>>  $blockers
     * @param  list<array<string, mixed>>  $excepted
     */
    public static function refused(string $reason, array $blockers, array $excepted = []): self
    {
        return new self(false, $reason, $blockers, $excepted);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'blockers' => $this->blockers,
            'excepted' => $this->excepted,
        ];
    }
}
