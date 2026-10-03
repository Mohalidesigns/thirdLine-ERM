<?php

namespace App\Support\Bcms;

/**
 * One directory object, shaped for BCMS and carrying no Graph vocabulary in
 * its own name — the value `DirectoryClient` yields, so 2D's LDAPS and HRIS
 * paths can produce the same shape (ADR 0018 §4).
 *
 * Every property here is something the attribute map can name; the mapping
 * from a raw provider field to these properties lives in the client, not in
 * anything that reads this object.
 *
 * `$accountEnabled` IS TRI-STATE, NOT A DEFAULT (ADR 0018 §3.1; gate 2
 * rejection #3, blocking defect 1; gate 2 rejection #4, blocking defect 1).
 * `null` means "not reported by this read", never "enabled" and never
 * "disabled". `DirectoryAttributeMap::selectFields()` names `accountEnabled`
 * in every `$select` this phase ever sends, so a FULL read always carries the
 * KEY — but Graph's `accountEnabled` is itself a nullable `Edm.Boolean`, and
 * some guest/external and partially-readable objects report that key WITH a
 * JSON `null` VALUE, not merely omit it. `fromGraphAttributes()` therefore
 * checks both `array_key_exists()` AND `!== null` before casting — an absent
 * key and a present-but-null value both become `null` here, and neither is
 * ever coerced to `(bool) null === false` ("disabled"), which is what an
 * unknown enablement state on a matched, active contact would otherwise stage
 * as a leaver with no human decision. A DELTA read is different again:
 * Graph's `/users/delta` returns only the properties that changed since the
 * last delta query, so a delta entry whose enable/disable state did NOT
 * change never repeats `accountEnabled` at all — treating that absence as
 * "true" (the old default) silently reactivated an already-deactivated
 * leaver on the very next unrelated attribute change. Every caller that
 * reads this property must therefore compare it with `=== true` /
 * `=== false`, never as a bare truthy/falsy value, so "unreported" and
 * "explicitly disabled" are never conflated.
 */
final class DirectoryUser
{
    public function __construct(
        public readonly string $objectId,
        public readonly ?string $userPrincipalName,
        public readonly ?string $displayName,
        public readonly ?string $mail,
        public readonly ?string $mobilePhone,
        public readonly ?string $businessPhone,
        public readonly ?string $jobTitle,
        public readonly ?string $department,
        public readonly ?string $officeLocation,
        public readonly ?string $employeeId,
        public readonly ?bool $accountEnabled,
        public readonly ?string $managerObjectId,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  a Graph user object (already
     *                                            narrowed to the mapped $select)
     */
    public static function fromGraphAttributes(array $attributes, ?string $managerObjectId = null): self
    {
        return new self(
            objectId: (string) ($attributes['id'] ?? ''),
            userPrincipalName: $attributes['userPrincipalName'] ?? null,
            displayName: $attributes['displayName'] ?? null,
            mail: $attributes['mail'] ?? null,
            mobilePhone: $attributes['mobilePhone'] ?? null,
            businessPhone: is_array($attributes['businessPhones'] ?? null)
                ? ($attributes['businessPhones'][0] ?? null)
                : null,
            jobTitle: $attributes['jobTitle'] ?? null,
            department: $attributes['department'] ?? null,
            officeLocation: $attributes['officeLocation'] ?? null,
            employeeId: $attributes['employeeId'] ?? null,
            // `array_key_exists` AND an explicit `!== null` check, NOT `??`
            // and NOT a bare `array_key_exists` ternary (gate 2 rejection
            // #4, blocking defect 1). A full read always sends the key; a
            // delta read only sends it when enable/disable itself changed —
            // absence must become `null` ("not reported"), never coerce to
            // `true`. But Graph's `accountEnabled` is itself a NULLABLE
            // `Edm.Boolean`: some guest/external and partially-readable
            // objects report the key WITH a JSON `null` value, not merely
            // omit it. The bare `array_key_exists(...) ? (bool) ... : null`
            // this replaces coerced a present-but-null value to `(bool)
            // null` === `false` — an explicit, wrong "disabled" reading of
            // what Graph itself reported as unknown. Both "absent" and
            // "present but null" must produce `null` here.
            accountEnabled: array_key_exists('accountEnabled', $attributes) && $attributes['accountEnabled'] !== null
                ? (bool) $attributes['accountEnabled']
                : null,
            managerObjectId: $managerObjectId ?? (is_array($attributes['manager'] ?? null)
                ? ($attributes['manager']['id'] ?? null)
                : null),
        );
    }
}
