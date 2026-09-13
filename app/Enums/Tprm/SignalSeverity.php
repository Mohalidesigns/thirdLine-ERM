<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * How serious a monitoring signal is, as normalised on ingest.
 *
 * This is the signal's OWN severity, not its SU contribution. SU is driven by
 * SignalType through the penalty table in TRD §7.5, because a low-severity
 * sanctions signal and a low-severity news signal are not worth the same
 * number of points. Severity here drives alerting, ordering and the console.
 */
enum SignalSeverity: string
{
    use EnumHelpers;

    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Informational = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::Informational => 'Informational',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'critical',
            self::High => 'high',
            self::Medium => 'medium',
            self::Low => 'low',
            self::Informational => 'neutral',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Informational => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }
}
