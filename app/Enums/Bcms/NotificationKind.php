<?php

namespace App\Enums\Bcms;

/**
 * `bcms_incident_notifications.kind` (ADR 0020 §2) — DORA arts. 17-19's
 * three-report structure (benchmark only, no `dora.*` clause code), which is
 * also exactly what NDPA s.40(2)'s "in phases" and the CBN's "update where
 * the earlier report was incomplete" both require: a regulator notification
 * is a SERIES of submissions against one obligation, one row per submission.
 */
enum NotificationKind: string
{
    case Initial = 'initial';
    case Intermediate = 'intermediate';
    case Final = 'final';
    case Supplementary = 'supplementary';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial notification',
            self::Intermediate => 'Intermediate report',
            self::Final => 'Final report',
            self::Supplementary => 'Supplementary submission',
        };
    }

    /** Whether more than one row of this kind may exist per obligation. */
    public function repeats(): bool
    {
        return $this === self::Supplementary;
    }
}
