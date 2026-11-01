<?php

namespace App\Services\Kri;

use App\Contracts\DirectoryProfiles;

/**
 * The placeholder binding for App\Contracts\DirectoryProfiles (ADR 0026
 * section 9): it knows no one, so every user reads as "not directory-synced".
 *
 * integrations-engineer replaces this binding in AppServiceProvider with the
 * real batched read of BCMS's directory-sourced contacts. Until then the
 * contacts screen simply shows no directory hint and no mismatch flag.
 */
class NullDirectoryProfiles implements DirectoryProfiles
{
    public function forUsers(int $organizationId, array $userIds): array
    {
        return [];
    }
}
