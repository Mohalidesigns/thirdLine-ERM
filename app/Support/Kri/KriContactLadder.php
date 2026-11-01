<?php

namespace App\Support\Kri;

use App\Enums\KriContactRole;
use App\Models\BusinessUnit;
use App\Models\KriDepartmentContact;
use Illuminate\Support\Collection;

/**
 * The tiered reach of a department's KRI contacts (ADR 0026 section 7) - ONE
 * function with two consumers: reminders (level = the reminder's sequence) and
 * Issue escalation (level = the effective escalation level).
 *
 * | level | roles reached (cumulative)       |
 * |   1   | risk_champion                    |
 * |   2   | + team_lead                      |
 * |   3   | + unit_head                      |
 * |  >=4  | + group_head                     |
 */
class KriContactLadder
{
    /**
     * Level => the minimum days overdue an Issue must be to reach it. The floor
     * that applies to a KRI-path Issue when a tenant has no escalation rules
     * (production has none). 7/14/30 is the cadence EnterpriseGapSeeder uses.
     * The day counts are this one constant.
     *
     * @var array<int, int>
     */
    public const ISSUE_FLOOR_DAYS = [1 => 1, 2 => 7, 3 => 14, 4 => 30];

    public const MAX_LEVEL = 4;

    /** @return list<KriContactRole> */
    public static function rolesForLevel(int $level): array
    {
        $level = max(1, min($level, self::MAX_LEVEL));

        return array_values(array_filter(
            KriContactRole::cases(),
            fn (KriContactRole $role) => $role->tier() <= $level,
        ));
    }

    /** The highest floor level an Issue that many days overdue has reached; 0 below the first rung. */
    public static function issueLevelForDaysOverdue(int $daysOverdue): int
    {
        $level = 0;

        foreach (self::ISSUE_FLOOR_DAYS as $rung => $days) {
            if ($daysOverdue >= $days) {
                $level = $rung;
            }
        }

        return $level;
    }

    /** The tier a reminder with this sequence reaches: min(sequence, 4), at least 1. */
    public static function tierForSequence(int $sequence): int
    {
        return max(1, min($sequence, self::MAX_LEVEL));
    }

    /**
     * Who is reached at this level, resolved NOW.
     *
     * A person is deduplicated and keeps their lowest tier. Inactive users and
     * users without an email are RETURNED, not hidden, so a caller can record
     * them as skipped.
     *
     * @return Collection<int, array{user: \App\Models\User, role: KriContactRole}>
     */
    public static function recipientsFor(BusinessUnit|int $unit, int $level): Collection
    {
        $unitId = $unit instanceof BusinessUnit ? $unit->id : $unit;
        $roles = self::rolesForLevel($level);

        $contacts = KriDepartmentContact::query()
            ->where('business_unit_id', $unitId)
            ->whereIn('role', array_map(fn (KriContactRole $r) => $r->value, $roles))
            ->with('user')
            ->orderBy('id')
            ->get();

        $byUser = [];

        foreach ($contacts as $contact) {
            if ($contact->user === null) {
                continue;
            }

            $current = $byUser[$contact->user_id] ?? null;

            if ($current === null || $contact->role->tier() < $current['role']->tier()) {
                $byUser[$contact->user_id] = ['user' => $contact->user, 'role' => $contact->role];
            }
        }

        return collect(array_values($byUser))
            ->sortBy(fn (array $row) => [$row['role']->tier(), $row['user']->id])
            ->values();
    }
}
