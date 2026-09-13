<?php

namespace App\Services\Llm;

/**
 * One request to the gateway — phase-11a-ai-contract.md §2.2. FROZEN SHAPE.
 *
 * `$organizationId` is required and typed `int`, not `?int`, on purpose: ADR
 * 0015 §5 refuses a call with no tenant context rather than writing an
 * unattributed usage row, and a required constructor argument is what makes
 * that refusal happen at the call site instead of three lines into the
 * gateway. A caller with no resolvable tenant does not construct this object
 * at all.
 */
final class LlmCall
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $module,
        public readonly string $service,
        public readonly string $promptKey,
        public readonly string $promptVersion,
        public readonly string $prompt,
        public readonly string $system = '',
        public readonly string $budget = 'short',
        public readonly ?string $endpointProfile = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?int $userId = null,
    ) {}
}
