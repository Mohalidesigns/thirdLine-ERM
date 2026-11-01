<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * What the corporate directory says about one ERM user (ADR 0026 section 9).
 *
 * A DISPLAY HINT, never a delivery rule: delivery skips only on
 * `users.is_active = false` or a missing email. Read-only - nothing in the
 * product writes to Active Directory (ADR 0018).
 */
final class DirectoryProfile
{
    public function __construct(
        public readonly bool $synced,
        public readonly ?string $department = null,
        public readonly ?string $jobTitle = null,
        public readonly ?bool $accountEnabled = null,
        public readonly ?CarbonImmutable $lastSyncedAt = null,
    ) {}

    /** The profile of a user the directory does not know. */
    public static function notSynced(): self
    {
        return new self(synced: false);
    }
}
