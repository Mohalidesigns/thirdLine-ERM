<?php

namespace App\Support\Bcms;

/**
 * Blueprint §8.2 defaults for `bcms_identity_connectors.attribute_map`, and
 * validation of a saved one.
 *
 * A NULL MAP ON THE CONNECTOR READS THESE DEFAULTS IN CODE (ADR 0018 §2.2
 * point 2). Improving a default is then a code change, not a data migration
 * across every tenant that never opened the screen.
 *
 * The map names the Graph `$select` fields the client asks for and the
 * `DirectoryUser` property each is read from — it does not itself decide
 * which `bcms_contacts` column a field lands on; `ChangeDetector` owns that,
 * because a Graph attribute and a contact column are not always the same
 * word (`mail` → `email`, `mobilePhone` → `mobile_primary`).
 */
final class DirectoryAttributeMap
{
    /**
     * The Graph `$select` this phase ever asks for. Nothing outside this list
     * reaches a `DirectoryUser`, and nothing outside `DirectoryUser`'s own
     * properties can be named in a saved map — the never-synced list of ADR
     * 0018 §3.4 has no Graph attribute here to name.
     *
     * @return list<string>
     */
    public static function selectFields(): array
    {
        return [
            'id', 'userPrincipalName', 'displayName', 'mail', 'mobilePhone', 'businessPhones',
            'jobTitle', 'department', 'officeLocation', 'employeeId', 'accountEnabled',
        ];
    }

    /**
     * The default field→contact-field mapping, editable per tenant. Keys are
     * `DirectoryUser` property names; values are the `bcms_contacts` column
     * each populates. Only allowlisted contact fields (`ChangeApplier`
     * constants) may appear on the right-hand side, and this default set is a
     * subset of that allowlist by construction.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'displayName' => 'full_name',
            'employeeId' => 'employee_id',
            'jobTitle' => 'title',
            'department' => 'business_unit_id',
            'officeLocation' => 'site_id',
            'mail' => 'email',
            'mobilePhone' => 'mobile_primary',
        ];
    }

    /**
     * A saved map is valid when every value it names is on the writer's
     * allowlist and every key is a real `DirectoryUser` property —
     * specifically, one of the source properties `ChangeDetector::
     * mapDirectoryUser()`'s `$bySourceProperty` actually looks up, which is
     * exactly this class's own `defaults()` key set. `userPrincipalName` and
     * `businessPhones` are deliberately NOT in that set even though they are
     * real `DirectoryUser`/Graph names: the former is applied unconditionally
     * as the email fallback and the latter is folded into `mobilePhone`
     * before the map ever sees it, so a saved key naming either would be
     * accepted and then silently never looked up — the exact defect this
     * validator exists to catch elsewhere.
     *
     * @param  array<string, mixed>  $map
     */
    public static function isValid(array $map): bool
    {
        $knownKeys = array_keys(self::defaults());
        $allowlist = \App\Services\Bcms\Identity\ChangeApplier::WRITE_ALLOWLIST;

        foreach ($map as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                return false;
            }

            if (! in_array($key, $knownKeys, true)) {
                return false;
            }

            if (! in_array($value, $allowlist, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The never-synced list, shown read-only on the connector screen (ADR
     * 0018 §5) so nobody looks for the missing fields.
     *
     * @return list<string>
     */
    public static function neverSynced(): array
    {
        return [
            'whatsapp', 'mobile_secondary', 'next_of_kin', 'channel_preferences', 'preferred_language',
            'consent_status', 'consent_captured_at', 'consent_withdrawn_at', 'verification_status',
            'last_verified_at', 'latitude', 'longitude', 'geo_last_known', 'user_id',
        ];
    }
}
