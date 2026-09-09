<?php

namespace Database\Seeders\Bcms\Reference;

use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\LadderLevel;

/**
 * The shipped exercise-type catalogue — the fourteen the Phase 0 prompt names,
 * each with its rung on the ISO 22398 ladder and its default cadence.
 *
 * THE CADENCES THAT COME FROM A REGULATOR ARE MARKED AND THE ONES THAT DO NOT,
 * ARE NOT. `cadence_clause_ref` is set only where a named rule states the
 * frequency: CBN Open Banking makes failover quarterly and the DR test
 * six-monthly, and those two are not our opinion. Everything else is a starting
 * point a client's own risk assessment should move, and inventing a regulatory
 * driver to make a default look authoritative is the thing the compliance
 * analyst refuses to do.
 *
 * `default_frequency_per_year` IS THE ENGINE'S INPUT. A client declaring "Fire
 * Drill × 2 per year" is choosing this number, and the calendar generator turns
 * it into conflict-checked occurrences. Two is the commonest Nigerian bank
 * practice for a fire drill and it is a default, not a rule.
 *
 * `default_lead_time_days` IS 10 EVERYWHERE EXCEPT WHERE IT CANNOT BE. A call
 * tree test at 10 days' notice is not a test of anything — an unannounced
 * cascade is the point — so it ships with a short lead time for the
 * facilitator's own readiness and `unannounced` is the sensible setting on the
 * definition.
 */
class ExerciseTypes
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'ORIENT', 'name' => 'Orientation / awareness briefing',
                'ladder_level' => LadderLevel::Orientation->value,
                'duration' => 60, 'frequency' => 1, 'lead' => 5,
                'description' => 'A briefing that introduces the plan, the roles and the activation criteria. Validates understanding, not capability.',
                'objectives' => ['Participants can state when the plan activates and who activates it', 'Participants can find their own role in the plan'],
            ],
            [
                'code' => 'TABLETOP', 'name' => 'Tabletop exercise',
                'ladder_level' => LadderLevel::Tabletop->value,
                'duration' => 180, 'frequency' => 2, 'lead' => 10,
                'description' => 'A facilitated discussion against a scenario. No systems are touched and no staff are moved.',
                'objectives' => ['Decisions are made against the documented activation criteria', 'Gaps between the plan and reality are recorded as findings'],
            ],
            [
                'code' => 'WALKTHRU', 'name' => 'Plan walkthrough',
                'ladder_level' => LadderLevel::Walkthrough->value,
                'duration' => 120, 'frequency' => 2, 'lead' => 10,
                'description' => 'A step-by-step read of the plan with the people who would execute it, checking that each step is executable as written.',
                'objectives' => ['Every step has a named owner who can perform it', 'Every reference in the plan resolves to something that exists'],
            ],
            [
                'code' => 'CALLTREE', 'name' => 'Call tree test',
                'ladder_level' => LadderLevel::Drill->value,
                // Quarterly and SHORT NOTICE. A cascade announced ten days out
                // measures nothing except whether people remembered.
                'duration' => 90, 'frequency' => 4, 'lead' => 2,
                'description' => 'A live cascade through the notification tree, timed per node, scored on reach rate, first-attempt rate and deputy activation.',
                'objectives' => ['Every must-reach node is reached', 'The full cascade completes inside its target time', 'No node fails for a wrong or stale contact detail'],
                'clause' => IsoClauseRef::Iso22301_8_4_3,
            ],
            [
                'code' => 'FIREDRILL', 'name' => 'Fire drill / evacuation',
                'ladder_level' => LadderLevel::Drill->value,
                'duration' => 45, 'frequency' => 2, 'lead' => 10,
                'description' => 'A physical evacuation to the assembly point with a headcount reconciliation.',
                'objectives' => ['The building is cleared inside the target time', 'A headcount is reconciled against the roster', 'Assembly point marshals perform their role'],
            ],
            [
                'code' => 'EVAC', 'name' => 'Evacuation and assembly test',
                'ladder_level' => LadderLevel::Drill->value,
                'duration' => 60, 'frequency' => 1, 'lead' => 10,
                'description' => 'Evacuation combined with an EMNS roll-call, testing whether a headcount can be produced remotely as well as at the assembly point.',
                'objectives' => ['A roll-call response is received from every evacuee', 'The unaccounted-for list is accurate'],
            ],
            [
                'code' => 'BACKUP', 'name' => 'Backup restore test',
                'ladder_level' => LadderLevel::Drill->value,
                'duration' => 240, 'frequency' => 4, 'lead' => 10,
                'description' => 'Restore from backup to a verified, usable state. Validates the RPO rather than the failover.',
                'objectives' => ['Data is restored inside the RPO', 'The restored data is verified as usable, not merely present'],
                'clause' => IsoClauseRef::Iso22301_8_4_5,
            ],
            [
                'code' => 'DRFAILOVER', 'name' => 'DR failover test',
                'ladder_level' => LadderLevel::Functional->value,
                // FOUR PER YEAR AND THIS ONE IS A RULE. CBN Open Banking
                // requires quarterly failover exercises for API providers and
                // consumers.
                'duration' => 480, 'frequency' => 4, 'lead' => 10,
                'description' => 'Fail production services over to the DR site and operate from it. Measures actual RTO and RPO against target.',
                'objectives' => ['Service is restored at the DR site inside the RTO', 'Data loss is inside the RPO', 'The runbook is executable as written'],
                'clause' => IsoClauseRef::Cbn_ob_failover,
            ],
            [
                'code' => 'DRFAILBACK', 'name' => 'DR failback test',
                'ladder_level' => LadderLevel::Functional->value,
                'duration' => 480, 'frequency' => 2, 'lead' => 10,
                'description' => 'Return services from the DR site to production. The half of the exercise most organisations never test, and the half that goes wrong.',
                'objectives' => ['Production is resumed inside the target window', 'No data written at DR is lost in the return'],
                'clause' => IsoClauseRef::Cbn_ob_failover,
            ],
            [
                'code' => 'DRTEST', 'name' => 'Disaster recovery test (full)',
                'ladder_level' => LadderLevel::Functional->value,
                // TWO PER YEAR AND THIS ONE IS ALSO A RULE: CBN Open Banking
                // requires the DR plan to be tested every six months.
                'duration' => 600, 'frequency' => 2, 'lead' => 10,
                'description' => 'A full DR test across the in-scope systems, evidencing recovery of the service rather than of a single component.',
                'objectives' => ['Every tier-1 system meets its RTO', 'Dependencies recover in the documented order'],
                'clause' => IsoClauseRef::Cbn_ob_dr_test,
            ],
            [
                'code' => 'FUNCTIONAL', 'name' => 'Functional exercise',
                'ladder_level' => LadderLevel::Functional->value,
                'duration' => 300, 'frequency' => 1, 'lead' => 10,
                'description' => 'A scenario run with real systems and real decisions in a controlled window, without moving the whole business.',
                'objectives' => ['The response structure functions under time pressure', 'Decisions are logged as they are taken, not reconstructed'],
            ],
            [
                'code' => 'FULLSCALE', 'name' => 'Full-scale exercise',
                'ladder_level' => LadderLevel::FullScale->value,
                'duration' => 600, 'frequency' => 1, 'lead' => 10,
                'description' => 'The whole response, end to end, with staff relocated and systems recovered. The exercise the ladder exists to lead up to.',
                'objectives' => ['The MBCO is delivered from the recovery arrangement', 'Every prioritised activity resumes inside its RTO'],
            ],
            [
                'code' => 'CRISISSIM', 'name' => 'Crisis management simulation',
                'ladder_level' => LadderLevel::Functional->value,
                'duration' => 240, 'frequency' => 2, 'lead' => 10,
                'description' => 'An executive crisis team exercise with media, regulator and customer communication injects.',
                'objectives' => ['The crisis team convenes inside its target time', 'A holding statement is approved inside the agreed window', 'The decision log is complete'],
                'clause' => IsoClauseRef::Iso22361_crisis,
            ],
            [
                'code' => 'CYBER', 'name' => 'Cyber incident exercise',
                'ladder_level' => LadderLevel::Functional->value,
                'duration' => 240, 'frequency' => 2, 'lead' => 10,
                'description' => 'A cyber scenario — ransomware, data exfiltration, a destructive attack — testing detection, containment, recovery and regulatory notification.',
                'objectives' => ['The incident is escalated inside the CBN reporting window', 'Recovery does not depend on a system the scenario has compromised', 'NigFinCERT notification is drafted and reviewed'],
                'clause' => IsoClauseRef::Cbn_rcf_drills,
            ],
            [
                'code' => 'PANDEMIC', 'name' => 'Pandemic / mass-absence exercise',
                'ladder_level' => LadderLevel::Tabletop->value,
                'duration' => 180, 'frequency' => 1, 'lead' => 10,
                'description' => 'A prolonged mass-absence scenario testing minimum staffing, remote operation and succession.',
                'objectives' => ['Each prioritised activity runs at its stated minimum staffing', 'Succession for every critical role is exercised'],
            ],
            [
                'code' => 'SUPPLIER', 'name' => 'Supplier continuity test',
                'ladder_level' => LadderLevel::Walkthrough->value,
                'duration' => 120, 'frequency' => 1, 'lead' => 10,
                'description' => 'Validate a critical vendor\'s own continuity arrangement, including their participation in our exercise where the contract requires it.',
                'objectives' => ['The vendor evidences a tested continuity capability', 'Our workaround for their unavailability is executable'],
                'clause' => IsoClauseRef::Iso22318_supply_chain,
            ],
        ];
    }
}
