<?php

namespace App\Enums\Tprm;

use App\Enums\Tprm\Concerns\EnumHelpers;

/**
 * The lifecycle of an engagement — TRD §5.2, and the spine of the module.
 * Risk is assessed at this level, not at the entity (TRD §5.1).
 *
 * TWO TRANSITIONS ARE GATED BY MORE THAN THIS TABLE, and the gates are the
 * product's differentiators rather than incidental validation:
 *
 *   → active      is blocked while an applicable BLOCKING contract clause is
 *                 absent or partial without an approved waiver (FR-CTR-05,
 *                 AC-06), and while a required exit plan is missing (§6.13).
 *   → terminated  is blocked while any connection is open or any access grant
 *                 is unrevoked without an approved exception (FR-ACC-04,
 *                 AC-10), and while the offboarding checklist is incomplete.
 *
 * Those guards live in the transition Action, not here, because they need the
 * database. This enum answers only "is that a shape of move we allow at all".
 */
enum EngagementStatus: string
{
    use EnumHelpers;

    case Draft = 'draft';
    case IntakeSubmitted = 'intake_submitted';
    case IntakeApproved = 'intake_approved';
    case DueDiligence = 'due_diligence';
    case Tiering = 'tiering';
    case Assessment = 'assessment';
    case Contracting = 'contracting';
    case Onboarding = 'onboarding';
    case Active = 'active';
    case MonitoringException = 'monitoring_exception';
    case Reassessment = 'reassessment';
    case ExitPlanning = 'exit_planning';
    case Transitioning = 'transitioning';
    case Terminated = 'terminated';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::IntakeSubmitted => 'Intake submitted',
            self::IntakeApproved => 'Intake approved',
            self::DueDiligence => 'Due diligence',
            self::Tiering => 'Tiering',
            self::Assessment => 'Assessment',
            self::Contracting => 'Contracting',
            self::Onboarding => 'Onboarding',
            self::Active => 'Active',
            self::MonitoringException => 'Monitoring exception',
            self::Reassessment => 'Reassessment',
            self::ExitPlanning => 'Exit planning',
            self::Transitioning => 'Transitioning',
            self::Terminated => 'Terminated',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'low',
            self::MonitoringException => 'high',
            self::Terminated, self::Archived, self::Draft => 'neutral',
            default => 'medium',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::IntakeSubmitted],
            // Rejection returns to Draft; approval moves on.
            self::IntakeSubmitted => [self::IntakeApproved, self::Draft],
            self::IntakeApproved => [self::DueDiligence, self::Tiering],
            self::DueDiligence => [self::Tiering, self::Assessment],
            self::Tiering => [self::Assessment, self::DueDiligence, self::Contracting],
            self::Assessment => [self::Contracting, self::Tiering],
            self::Contracting => [self::Onboarding, self::Assessment],
            self::Onboarding => [self::Active, self::Contracting],
            self::Active => [self::MonitoringException, self::Reassessment, self::ExitPlanning],
            self::MonitoringException => [self::Active, self::Reassessment, self::ExitPlanning],
            self::Reassessment => [self::Active, self::MonitoringException, self::ExitPlanning],
            self::ExitPlanning => [self::Transitioning, self::Active],
            self::Transitioning => [self::Terminated],
            self::Terminated => [self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether the engagement is live — the filter behind "active portfolio" on
     * every dashboard, register export and concentration run. An engagement in
     * `monitoring_exception` or `reassessment` is still being consumed and
     * still counts.
     */
    public function isLive(): bool
    {
        return in_array($this, [
            self::Active,
            self::MonitoringException,
            self::Reassessment,
            self::ExitPlanning,
            self::Transitioning,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Terminated, self::Archived], true);
    }
}
