<?php

namespace App\Enums\Bcms;

/**
 * `bcms_dr_tests.test_type` (ADR 0020 §3).
 *
 * A `Failover` with no paired `Failback` recorded afterward is half a test —
 * the clause map's own words, and `dr-test-record.md`'s "unpaired failover"
 * banner is built against exactly this pair of cases.
 */
enum DrTestType: string
{
    case Failover = 'failover';
    case Failback = 'failback';
    case BackupRestore = 'backup_restore';
    case Tabletop = 'tabletop';
    case Component = 'component';

    public function label(): string
    {
        return match ($this) {
            self::Failover => 'Failover',
            self::Failback => 'Failback',
            self::BackupRestore => 'Backup restore',
            self::Tabletop => 'Tabletop',
            self::Component => 'Component',
        };
    }
}
