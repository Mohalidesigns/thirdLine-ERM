<?php

namespace App\Services\Llm;

/**
 * The result of checking a tenant's month against its caps.
 */
final class BudgetVerdict
{
    private function __construct(
        public readonly bool $withinBudget,
        public readonly ?string $reason,
        public readonly int $tokensUsed,
        public readonly int $callsUsed,
    ) {}

    public static function ok(int $tokensUsed, int $callsUsed): self
    {
        return new self(true, null, $tokensUsed, $callsUsed);
    }

    public static function exceeded(string $reason, int $tokensUsed, int $callsUsed): self
    {
        return new self(false, $reason, $tokensUsed, $callsUsed);
    }
}
