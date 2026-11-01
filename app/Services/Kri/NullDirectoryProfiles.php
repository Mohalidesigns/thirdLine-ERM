<?php

namespace App\Services\Kri;

use App\Contracts\DirectoryProfiles;

/**
 * The placeholder binding for App\Contracts\DirectoryProfiles (ADR 0026
 * section 9): it knows no one, so every user reads as "not directory-synced".
 *
 * The real binding is BcmsDirectoryProfiles, the
 * batched read of BCMS directory-sourced contacts. This class is the explicit
 * "no directory" implementation: no hint and no mismatch flag.
 */
class NullDirectoryProfiles implements DirectoryProfiles
{
    public function forUsers(int $organizationId, array $userIds): array
    {
        return [];
    }
}
