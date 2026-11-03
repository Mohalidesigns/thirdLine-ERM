<?php

namespace App\Enums;

/**
 * The four tiers of a department's KRI contacts (ADR 0026 section 2).
 *
 * The set is frozen by the ADR. `tier()` is the rung of the ladder the role
 * first appears on; KriContactLadder reads it, so the order lives in one place.
 */
enum KriContactRole: string
{
    case RiskChampion = 'risk_champion';
    case TeamLead = 'team_lead';
    case UnitHead = 'unit_head';
    case GroupHead = 'group_head';

    public function tier(): int
    {
        return match ($this) {
            self::RiskChampion => 1,
            self::TeamLead => 2,
            self::UnitHead => 3,
            self::GroupHead => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RiskChampion => 'Risk champion',
            self::TeamLead => 'Team lead',
            self::UnitHead => 'Unit head',
            self::GroupHead => 'Group head',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
