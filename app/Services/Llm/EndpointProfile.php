<?php

namespace App\Services\Llm;

/**
 * A resolved endpoint — phase-11a-ai-contract.md §2.2.
 *
 * `unitCostPer1kTokensMinor` and `currency` are null for every profile this
 * deployment ships (ADR 0015 §4): there is no price, so there is no figure,
 * and the report prints "Not priced" rather than inventing a `0`.
 */
final class EndpointProfile
{
    public function __construct(
        public readonly string $key,
        public readonly string $endpoint,
        public readonly string $model,
        public readonly string $keepAlive,
        public readonly ?int $unitCostPer1kTokensMinor = null,
        public readonly ?string $currency = null,
    ) {}

    public function isPriced(): bool
    {
        return $this->unitCostPer1kTokensMinor !== null;
    }
}
