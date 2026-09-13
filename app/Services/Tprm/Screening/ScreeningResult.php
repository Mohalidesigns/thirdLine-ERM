<?php

namespace App\Services\Tprm\Screening;

/**
 * What one provider said about one name.
 *
 * `raw` IS KEPT WHOLE. Reg. 35 requires five years' retention retrievable
 * within 48 hours, and what has to be retrievable is the provider's answer —
 * our normalised match list is a reading of it, and a supervisor asking what
 * the list said in 2021 is not asking what we concluded.
 *
 * A FAILED SEARCH IS NOT A CLEAR SEARCH. `failed` exists so that a provider
 * timing out cannot be recorded as "no matches found", which is the single
 * most dangerous confusion available in this part of the module.
 */
class ScreeningResult
{
    /**
     * @param  list<array{list_name: string, matched_name: string, score: float|null, details: array<string, mixed>}>  $matches
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly bool $succeeded,
        public readonly array $matches,
        public readonly array $raw,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  list<array{list_name: string, matched_name: string, score: float|null, details: array<string, mixed>}>  $matches
     * @param  array<string, mixed>  $raw
     */
    public static function found(array $matches, array $raw = []): self
    {
        return new self(true, $matches, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function clear(array $raw = []): self
    {
        return new self(true, [], $raw);
    }

    public static function failed(string $error): self
    {
        return new self(false, [], [], $error);
    }

    public function hasMatches(): bool
    {
        return $this->matches !== [];
    }
}
