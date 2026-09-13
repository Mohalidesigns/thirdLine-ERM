<?php

namespace App\Services\Llm;

/**
 * Whether a call would be allowed, and — if not — which layer said no.
 * phase-11a-ai-contract.md §2.2.
 *
 * `$layer` IS THE WHOLE POINT OF THIS CLASS EXISTING SEPARATELY FROM A BOOL.
 * `deployment_llm`, `deployment_module`, `tenant_master`, `deployment_service`,
 * `tenant_service`, `cap`, `breaker`, `endpoint` — eight possible answers to
 * "why not", and a screen showing only `allowed: false` sends every admin to
 * the same wrong queue regardless of which switch is actually theirs to flip.
 */
final class Availability
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?string $layer,
    ) {}

    public static function allowed(): self
    {
        return new self(true, null, null);
    }

    public static function blocked(string $reason, string $layer): self
    {
        return new self(false, $reason, $layer);
    }
}
