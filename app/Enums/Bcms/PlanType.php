<?php

namespace App\Enums\Bcms;

/** The plan types the builder produces (Blueprint §9.2). */
enum PlanType: string
{
    // The BC policy (clause 5.2) is filed as a plan record: it is a versioned,
    // approved, supersedable document with an owner and an approver, which is
    // exactly what `bcms_plans` already models. A table of its own would
    // duplicate the version chain and the approval trail to hold one row per
    // tenant. Recorded as an assumption in the Phase 0 clause map.
    case Policy = 'policy';
    case Bcp = 'bcp';
    case Drp = 'drp';
    case Cmp = 'cmp';
    case Irp = 'irp';
    case Pandemic = 'pandemic';
    case Site = 'site';
    case Department = 'department';
    case EmergencyResponse = 'emergency_response';

    public function label(): string
    {
        return match ($this) {
            self::Policy => 'Business continuity policy',
            self::Bcp => 'Business continuity plan',
            self::Drp => 'Disaster recovery plan',
            self::Cmp => 'Crisis management plan',
            self::Irp => 'Incident response plan',
            self::Pandemic => 'Pandemic plan',
            self::Site => 'Site plan',
            self::Department => 'Department plan',
            self::EmergencyResponse => 'Emergency response plan',
        };
    }

    /** The clause a plan of this type is evidence for. */
    public function clauseRef(): IsoClauseRef
    {
        return match ($this) {
            self::Policy => IsoClauseRef::Iso22301_5_2,
            self::Cmp => IsoClauseRef::Iso22361_crisis,
            self::Irp, self::EmergencyResponse => IsoClauseRef::Iso22301_8_4_2,
            default => IsoClauseRef::Iso22301_8_4_4,
        };
    }
}
