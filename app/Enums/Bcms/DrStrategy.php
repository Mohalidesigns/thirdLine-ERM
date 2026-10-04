<?php

namespace App\Enums\Bcms;

/** `bcms_dr_systems.dr_strategy` (ADR 0020 §3). */
enum DrStrategy: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';
    case ActiveActive = 'active_active';
    case BackupRestore = 'backup_restore';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Hot => 'Hot standby',
            self::Warm => 'Warm standby',
            self::Cold => 'Cold standby',
            self::ActiveActive => 'Active/active',
            self::BackupRestore => 'Backup and restore',
            self::None => 'No DR arrangement',
        };
    }
}
