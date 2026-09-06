<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * Finding severity, its penalty in FU (TRD §7.5) and its default remediation
 * SLA.
 *
 * Both numbers come from config rather than from here, and the SLA is then
 * overridden by the engagement's tier policy: a Medium finding against a
 * Critical vendor is not the same 90 days as a Medium against a Low one. The
 * default is the fallback for an engagement with no policy row, not the rule.
 */
enum FindingSeverity: string
{
    use EnumHelpers;

    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'critical',
            self::High => 'high',
            self::Medium => 'medium',
            self::Low => 'low',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    /** The FU penalty for one open finding at this severity. */
    public function penalty(): int
    {
        /** @var array<string, int> $penalties */
        $penalties = config('tprm.scoring.findings_uplift.severity');

        return (int) $penalties[$this->value];
    }

    /** Default remediation SLA in days, absent a tier policy. */
    public function defaultSlaDays(): int
    {
        /** @var array<string, int> $days */
        $days = config('tprm.defaults.remediation_sla_days');

        return (int) $days[$this->value];
    }
}
