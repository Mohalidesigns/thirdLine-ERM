<?php

namespace App\Enums\Bcms;

/** `bcms_incidents.activation_level` (ADR 0020 §3). */
enum ActivationLevel: string
{
    case Monitor = 'monitor';
    case Standby = 'standby';
    case Partial = 'partial';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Monitor => 'Monitor',
            self::Standby => 'Standby',
            self::Partial => 'Partial activation',
            self::Full => 'Full activation',
        };
    }
}
