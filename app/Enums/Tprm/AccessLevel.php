<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * What a third party's access grant lets it do — CBN Cyber Framework
 * Appendix III §1.3, which requires third-party access to be approved by
 * senior management, time-bounded, escorted where physical, and monitored.
 *
 * `isPrivileged()` is the KO-PRIV knockout condition (TRD §7.3): privileged or
 * administrative access to production floors the engagement's tier at High.
 */
enum AccessLevel: string
{
    use EnumHelpers;

    case Read = 'read';
    case Write = 'write';
    case Privileged = 'privileged';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read only',
            self::Write => 'Write',
            self::Privileged => 'Privileged',
            self::Admin => 'Administrative',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Read => 'low',
            self::Write => 'medium',
            self::Privileged => 'high',
            self::Admin => 'critical',
        };
    }

    public function isPrivileged(): bool
    {
        return in_array($this, [self::Privileged, self::Admin], true);
    }
}
