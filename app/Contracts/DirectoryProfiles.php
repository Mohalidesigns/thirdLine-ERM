<?php

namespace App\Contracts;

use App\Support\DirectoryProfile;

/**
 * Read-only access to directory-sourced facts about ERM users (ADR 0026
 * section 9, ADR 0018). Contacts store `users.id` and nothing else; the hint is
 * read when a screen is drawn, because a copy would go stale on the next sync.
 *
 * IMPLEMENTATIONS NEVER WRITE. Returns [] when there is nothing to report
 * (BCMS off, no directory), and a caller treats an absent id as "not
 * directory-synced".
 */
interface DirectoryProfiles
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, DirectoryProfile> keyed by user id; absent = not directory-synced
     */
    public function forUsers(int $organizationId, array $userIds): array;
}
