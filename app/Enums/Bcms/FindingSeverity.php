<?php

namespace App\Enums\Bcms;

/**
 * The management axis of a finding — `bcms_findings.severity`, an existing
 * free string column with no enum and no validator until Phase 9 (compliance
 * analyst refinement 4, ADR 0019 §6). An enum over an existing column is not a
 * structural migration, so this needed no ADR of its own.
 *
 * NOT THE SAME AXIS AS `FindingClassification`. Classification
 * (observation/improvement/nonconformity) is the ISO axis: does this fail a
 * stated requirement. Severity is how much it matters operationally, and the
 * two vary independently — an observation can be scored `low` and a
 * nonconformity can be `critical`.
 *
 * THE DEFAULT DUE DATE IS A STARTING POINT, NOT THE RULE. The actual due date
 * is `min(defaultDueDays(), next occurrence of this definition − 5 working
 * days)`, floored at `raised_at + 5 working days` — see
 * `App\Services\Bcms\Findings\CorrectiveActionDueDateCalculator`, which is
 * where the working-day arithmetic (Phase 4's `WorkingCalendar`) actually
 * lives. This enum only carries the severity-only default.
 */
enum FindingSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /** Words used on screen — never a bare number or a colour alone. */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Critical => 'Critical',
        };
    }

    /**
     * The default CAPA due date, in calendar days from raising — before the
     * next-occurrence and 5-working-day floor rules are applied.
     */
    public function defaultDueDays(): int
    {
        return match ($this) {
            self::Critical => 30,
            self::High => 60,
            self::Medium => 90,
            // "180 days or next programme cycle" — the calculator resolves
            // the "or next cycle" half; this is the calendar-day half.
            self::Low => 180,
        };
    }

    /**
     * Whether an action of this severity requires its owner to be a function
     * head or above.
     *
     * Not enforced here — there is no "seniority" the product can check
     * mechanically — but the label the screen must show while asking a human
     * to confirm it, per the compliance analyst's table.
     */
    public function requiresSeniorOwner(): bool
    {
        return $this === self::Critical;
    }

    /** Whether accepting risk at this severity must carry an expiry date. */
    public function acceptanceRequiresExpiry(): bool
    {
        return in_array($this, [self::Critical, self::High], true);
    }
}
