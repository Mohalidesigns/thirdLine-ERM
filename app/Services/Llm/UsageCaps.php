<?php

namespace App\Services\Llm;

/**
 * A tenant's monthly limits, in tokens and calls — never currency.
 *
 * ADR 0015 §4: "there is no money, and we will not print one." Both fields
 * are null-means-uncapped, and a `0` is never a valid substitute for "not
 * set" — a `0` cap would refuse every call, which nothing in this product
 * treats as a legitimate default.
 */
final class UsageCaps
{
    public function __construct(
        public readonly ?int $tokenCap = null,
        public readonly ?int $callCap = null,
    ) {}

    public static function uncapped(): self
    {
        return new self(null, null);
    }

    public function isUncapped(): bool
    {
        return $this->tokenCap === null && $this->callCap === null;
    }
}
