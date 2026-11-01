<?php

namespace App\Services\Kri;

use App\Contracts\DirectoryProfiles;
use App\Support\DirectoryProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * App\Contracts\DirectoryProfiles over what BCMS identity sync has ALREADY
 * written (ADR 0026 section 9, ADR 0018).
 *
 * READ-ONLY, AND NEVER A DIRECTORY CALL. Three plain SELECTs per invocation,
 * whatever the number of users: no Graph call, no LDAP bind, no write. Raw
 * query-builder reads are deliberate: `Contact` carries a hierarchy scope that
 * hides rows by the VIEWER's business units, which would make a contact's flag
 * depend on who is looking. Tenancy is applied explicitly instead.
 *
 * WHAT THE SYNC ACTUALLY KEEPS. `bcms_contacts` has no column for the raw AD
 * department string: the sync maps it (case-insensitive name or code) to
 * `business_unit_id`, and an unmatched department is dropped. So `department`
 * here is the NAME OF THE BUSINESS UNIT the sync resolved the person to - the
 * only directory department fact stored. A hand-edited `business_unit_id` wins
 * over the sync (ChangeApplier provenance), so treat it as a hint, as the ADR
 * does. `accountEnabled` is the contact's `is_active`, which the sync sets
 * false for a disabled account only once the change is applied (a staged leaver
 * still reads enabled until BC-admin approval).
 *
 * Degrades to [] when `features.bcms` is off or the tenant has no active
 * identity connector, so a tenant with no directory sees no hint and no flag.
 */
class BcmsDirectoryProfiles implements DirectoryProfiles
{
    public function forUsers(int $organizationId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($userIds === [] || ! config('features.bcms')) {
            return [];
        }

        $configured = DB::table('bcms_identity_connectors')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->exists();

        if (! $configured) {
            return [];
        }

        $rows = [];

        foreach (array_chunk($userIds, 500) as $chunk) {
            $found = DB::table('bcms_contacts')
                ->where('organization_id', $organizationId)
                ->whereIn('user_id', $chunk)
                ->whereNotNull('ad_object_guid')
                ->whereNull('deleted_at')
                ->get(['user_id', 'business_unit_id', 'title', 'is_active', 'ad_synced_at']);

            foreach ($found as $row) {
                $current = $rows[$row->user_id] ?? null;

                // One user, several directory contacts is not expected (unique on
                // guid, not on user); the most recently synced one speaks.
                if ($current === null || (string) $row->ad_synced_at > (string) $current->ad_synced_at) {
                    $rows[$row->user_id] = $row;
                }
            }
        }

        if ($rows === []) {
            return [];
        }

        $unitIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r->business_unit_id, $rows))));
        $unitNames = $unitIds === [] ? [] : DB::table('business_units')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $unitIds)
            ->pluck('name', 'id')
            ->all();

        $profiles = [];

        foreach ($rows as $userId => $row) {
            $profiles[(int) $userId] = new DirectoryProfile(
                synced: true,
                department: $row->business_unit_id === null ? null : ($unitNames[$row->business_unit_id] ?? null),
                jobTitle: $row->title,
                accountEnabled: (bool) $row->is_active,
                lastSyncedAt: $row->ad_synced_at === null ? null : CarbonImmutable::parse($row->ad_synced_at),
            );
        }

        return $profiles;
    }
}
