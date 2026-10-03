<?php

namespace App\Enums\Bcms;

/**
 * `bcms_identity_connectors.provider`.
 *
 * One case today. It is a string column with room in its unique index
 * (`organization_id`, `provider`) rather than a boolean "is this Entra"
 * precisely because 2D adds LDAPS as a second provider without a schema
 * change — ADR 0018 §2.2 point 6.
 */
enum IdentityProvider: string
{
    case Entra = 'entra';

    public function label(): string
    {
        return match ($this) {
            self::Entra => 'Microsoft Entra ID',
        };
    }
}
