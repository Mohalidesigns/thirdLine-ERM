<?php

namespace App\Services\Kri;

use App\Models\BusinessUnit;
use App\Support\DirectoryProfile;

/**
 * ADR 0026 section 9: the directory's department text against the unit's name
 * and code. Business units are authoritative; the directory is a hint. The
 * output is a flag for the contacts screen and nothing else: it never moves a
 * contact, never changes a unit, and never gates delivery.
 *
 * It uses the same name-or-code match the identity sync uses to resolve a
 * department (ChangeDetector), only normalised a little further (case, spacing,
 * "&", punctuation) so "Risk & Compliance" does not flag against "Risk and
 * Compliance". Unknown is not a mismatch: no profile, not synced, or no
 * department text all return false. A person who moved department after being
 * configured shows up here, because their department no longer names the unit.
 */
final class DepartmentMismatchRule
{
    public static function mismatches(?DirectoryProfile $profile, BusinessUnit $unit): bool
    {
        if ($profile === null || ! $profile->synced) {
            return false;
        }

        $department = self::normalise($profile->department);

        if ($department === '') {
            return false;
        }

        return $department !== self::normalise($unit->name)
            && $department !== self::normalise($unit->code);
    }

    private static function normalise(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = str_replace('&', ' and ', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim($value);
    }
}
