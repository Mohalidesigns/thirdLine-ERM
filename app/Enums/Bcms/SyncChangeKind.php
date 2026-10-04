<?php

namespace App\Enums\Bcms;

/**
 * `bcms_identity_sync_changes.kind` — the four shapes a directory read
 * produces against the current roster (ADR 0018 §2.4).
 */
enum SyncChangeKind: string
{
    case Joiner = 'joiner';
    case Leaver = 'leaver';
    case Mover = 'mover';
    case ContactChange = 'contact_change';

    public function label(): string
    {
        return match ($this) {
            self::Joiner => 'Joiner',
            self::Leaver => 'Leaver',
            self::Mover => 'Mover',
            self::ContactChange => 'Contact change',
        };
    }
}
