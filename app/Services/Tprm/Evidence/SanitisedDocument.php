<?php

namespace App\Services\Tprm\Evidence;

/**
 * Document text with instruction-like content removed, and a record of what
 * was removed.
 *
 * `flags` being non-empty is not a reason to refuse the extraction — a
 * false positive on the phrase "new instructions" in a change-management
 * procedure is entirely possible. It is a reason to tell a human, loudly,
 * before they confirm anything the extraction proposes.
 */
class SanitisedDocument
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public readonly string $text,
        public readonly array $flags = [],
    ) {}

    public function hasInjectionAttempt(): bool
    {
        return $this->flags !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'injection_flagged' => $this->hasInjectionAttempt(),
            'flags' => $this->flags,
        ];
    }
}
