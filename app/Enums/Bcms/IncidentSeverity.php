<?php

namespace App\Enums\Bcms;

/**
 * `bcms_incidents.severity` — Phase 10's first writer (ADR 0020 §3, clause
 * map §1.3).
 *
 * The bands are a configurable default over the tenant's own BIA numbers and
 * CBN's 30-minute Open Banking threshold, per the clause map — this enum
 * fixes the four SPELLINGS a regulator pack can rely on, not the trigger
 * logic itself, which lives in `IncidentService::suggestSeverity()` and
 * `config('bcms.incident.severity_matrix')`.
 */
enum IncidentSeverity: string
{
    case Sev1 = 'sev1';
    case Sev2 = 'sev2';
    case Sev3 = 'sev3';
    case Sev4 = 'sev4';

    public function label(): string
    {
        return match ($this) {
            self::Sev1 => 'Sev1 — critical',
            self::Sev2 => 'Sev2 — major',
            self::Sev3 => 'Sev3 — contained',
            self::Sev4 => 'Sev4 — minor',
        };
    }

    /** The activation level this severity defaults to, absent an override. */
    public function defaultActivationLevel(): ActivationLevel
    {
        return match ($this) {
            self::Sev1 => ActivationLevel::Full,
            self::Sev2 => ActivationLevel::Partial,
            self::Sev3 => ActivationLevel::Standby,
            self::Sev4 => ActivationLevel::Monitor,
        };
    }
}
