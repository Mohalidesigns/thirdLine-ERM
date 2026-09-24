<?php

namespace Database\Seeders\Bcms\Reference;

use App\Enums\Bcms\IsoClauseRef;

/**
 * The shipped BC training curricula (ISO 22301 clauses 7.2 and 7.3) —
 * Phase 11 spec §2.1, the six role-based curricula.
 *
 * AWARENESS AND COMPETENCE ARE DIFFERENT CLAUSES AND DIFFERENT RECORDS. 7.3
 * asks whether people are aware; 7.2 asks whether the people whose work
 * affects BCMS performance are COMPETENT, and requires documented evidence of
 * it. `BC-AWARE-ALL` is deliberately the only unassessed curriculum: a
 * curriculum that claims competence without an assessment produces a record
 * that proves attendance, and nothing else.
 *
 * EMNS OPERATOR RE-CERTIFIES AT SIX MONTHS, not twelve like every other
 * curriculum — that role dispatches to every handset the bank holds, and the
 * cost of a mistake there is the highest in the module.
 *
 * TARGET ROLES NAME THE ROLES A BANK WOULD ASSIGN, NOT NECESSARILY ONES THIS
 * INSTALLATION HAS CREATED YET. Automatic enrolment from AD/Entra groups is
 * Phase 2C's, and is not built (phase-11-spec §7). Until it is, a curriculum
 * whose `target_roles` matches no current platform role holder resolves to
 * zero assigned people, and the training-compliance screen says so plainly
 * rather than pretending enrolment ran and found nobody.
 *
 * MODULE CONTENT IS LOCALLY GROUNDED, NOT GENERIC (phase-11-spec §2.1): the
 * warden curriculum covers a multi-tenant Lagos office tower and assisting
 * mobility-impaired colleagues; the ITDR curriculum covers the 30-minute Open
 * Banking failover threshold and failback; the crisis curriculum covers CBN
 * and NigFinCERT notification timing and a holding statement.
 *
 * SUPERSEDED CODES, FROM THE PHASE 0 PLACEHOLDER PACK. Phase 0 shipped four
 * curricula (`BC-AWARE`, `BC-CHAMPION`, `BC-FACILITATOR`, `BC-CRISIS`) as a
 * placeholder ahead of this phase's real content. `BC-CHAMPION` and
 * `BC-CRISIS` keep their codes here and are simply updated in place.
 * `BC-AWARE` and `BC-FACILITATOR` do not — a tenant that lived through the
 * placeholder pack would otherwise keep both the old and the new rows live,
 * reporting eight curricula where six exist. `superseded()` is how the
 * seeder retires the two that do not carry their code forward: no schema
 * change (ADR 0021), because `bcms_training_curricula.is_active` already
 * exists to say a curriculum is retired.
 */
class TrainingCurricula
{
    /**
     * Old code => the code that replaced it, or null where nothing did.
     *
     * `BC-AWARE` was renamed and enriched into `BC-AWARE-ALL` — same
     * audience (`*`), same clause (7.3), more modules. `BC-FACILITATOR` (the
     * exercise-facilitator curriculum) has no successor in the six-curriculum
     * pack; it is retired outright, not folded into `BC-WARDEN`, which trains
     * a different role for a different clause.
     *
     * @return array<string, string|null>
     */
    public static function superseded(): array
    {
        return [
            'BC-AWARE' => 'BC-AWARE-ALL',
            'BC-FACILITATOR' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'BC-AWARE-ALL',
                'name' => 'Business continuity awareness',
                'description' => 'What the BCMS is, what to do when the alarm sounds, how you will be contacted and what you are expected to do about it.',
                'roles' => ['*'],
                'modules' => [
                    ['code' => 'why-continuity-matters', 'title' => 'Why continuity matters here, and what a disruption costs', 'minutes' => 15, 'outcome' => 'Explain what the BCMS protects and why it exists'],
                    ['code' => 'staying-reachable', 'title' => 'How you will be contacted in an emergency, and keeping your details current', 'minutes' => 10, 'outcome' => 'Recognise an EMNS alert and know how to respond'],
                    ['code' => 'evacuation-assembly', 'title' => 'Evacuation, assembly points and the roll-call', 'minutes' => 15, 'outcome' => 'State your building assembly point'],
                    ['code' => 'my-departments-plan', 'title' => 'Your department\'s plan and where to find it', 'minutes' => 10, 'outcome' => 'Locate your department\'s continuity plan'],
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => false,
                'clause' => IsoClauseRef::Iso22301_7_3,
            ],
            [
                'code' => 'BC-WARDEN',
                'name' => 'Floor warden / fire marshal',
                'description' => 'For the person responsible for a floor or zone during an evacuation.',
                'roles' => ['floor-warden', 'facilities'],
                'modules' => [
                    ['code' => 'warden-duties', 'title' => 'The floor warden\'s duties before, during and after an evacuation', 'minutes' => 20, 'outcome' => 'List the warden\'s five duties in sequence'],
                    ['code' => 'assembly-points-tower', 'title' => 'Assembly points in a multi-tenant Lagos office tower, and shared building procedures', 'minutes' => 20, 'outcome' => 'Direct occupants to the correct assembly point for a shared tower'],
                    ['code' => 'assisting-mobility-impaired', 'title' => 'Assisting mobility-impaired colleagues during an evacuation', 'minutes' => 20, 'outcome' => 'Describe the buddy system and refuge-area procedure'],
                    ['code' => 'roll-call-reporting', 'title' => 'Conducting and reporting the roll-call', 'minutes' => 15, 'outcome' => 'Complete a roll-call and report the result to the crisis team'],
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => true,
                'pass_mark' => 80,
                'clause' => IsoClauseRef::Iso22301_7_2,
            ],
            [
                'code' => 'BC-CRISIS',
                'name' => 'Crisis management team',
                'description' => 'For the executives who declare an incident, activate plans and authorise communication.',
                'roles' => ['crisis-manager', 'exec-sponsor'],
                'modules' => [
                    ['code' => 'activation-authority', 'title' => 'Activation criteria and the authority to declare', 'minutes' => 20, 'outcome' => 'State who may declare an incident and at what activation level'],
                    ['code' => 'decisions-under-uncertainty', 'title' => 'Decision making under uncertainty, and logging as you go', 'minutes' => 20, 'outcome' => 'Maintain a decision log during a live incident'],
                    ['code' => 'cbn-nigfincert-timing', 'title' => 'CBN and NigFinCERT notification timing and a holding statement', 'minutes' => 25, 'outcome' => 'State the notification clock and draft a holding statement'],
                    ['code' => 'stakeholder-communication', 'title' => 'Stakeholder, customer and regulator communication', 'minutes' => 20, 'outcome' => 'Identify who must be told what, and by when'],
                    ['code' => 'live-alert-dual-approval', 'title' => 'Authorising a live emergency notification, and the dual-approval rule', 'minutes' => 15, 'outcome' => 'Explain why a live send from an exercise context needs two approvers'],
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => true,
                'pass_mark' => 80,
                'clause' => IsoClauseRef::Iso22301_7_2,
            ],
            [
                'code' => 'BC-EMNS',
                'name' => 'EMNS operator',
                'description' => 'For the person who composes, approves and dispatches emergency mass notifications.',
                'roles' => ['emns-operator', 'emns-approver'],
                'modules' => [
                    ['code' => 'audience-and-templates', 'title' => 'Building an audience rule and choosing a template', 'minutes' => 20, 'outcome' => 'Compose an alert against the correct audience and template'],
                    ['code' => 'quiet-hours-life-safety', 'title' => 'Quiet hours, throttling and why life-safety traffic bypasses both', 'minutes' => 15, 'outcome' => 'Explain which traffic is never deferred or throttled'],
                    ['code' => 'exercise-vs-live', 'title' => 'Exercise simulation mode versus a live dispatch, and the dual-approval escalation', 'minutes' => 20, 'outcome' => 'Distinguish a simulated send from a live one before dispatching'],
                    ['code' => 'delivery-evidence', 'title' => 'Reading delivery evidence and the acknowledgement roll-call', 'minutes' => 15, 'outcome' => 'Interpret per-recipient delivery and acknowledgement status'],
                ],
                'frequency_months' => 6,
                'mandatory' => true,
                'assess' => true,
                'pass_mark' => 90,
                'clause' => IsoClauseRef::Iso22301_7_2,
            ],
            [
                'code' => 'BC-CHAMPION',
                'name' => 'Department BC champion',
                'description' => 'For the person who owns their unit\'s BIA, plan, call tree and readiness tasks.',
                'roles' => ['department-champion'],
                'modules' => [
                    ['code' => 'bia-fundamentals', 'title' => 'Completing a business impact analysis: MTPD, RTO, RPO and MBCO', 'minutes' => 25, 'outcome' => 'Complete a BIA for a departmental process'],
                    ['code' => 'finding-dependencies', 'title' => 'Identifying dependencies, including the ones nobody writes down', 'minutes' => 20, 'outcome' => 'List a process\'s upstream and downstream dependencies'],
                    ['code' => 'maintaining-plan-tree', 'title' => 'Maintaining the department plan and its call tree', 'minutes' => 20, 'outcome' => 'Keep a department plan and call tree current between reviews'],
                    ['code' => 'readiness-checklist', 'title' => 'Running the readiness checklist before an exercise', 'minutes' => 15, 'outcome' => 'Clear a readiness checklist ahead of T-1'],
                    ['code' => 'raising-findings', 'title' => 'Raising a finding, and what makes a corrective action verifiable', 'minutes' => 15, 'outcome' => 'Raise a finding with a verifiable corrective action'],
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => true,
                'pass_mark' => 75,
                'clause' => IsoClauseRef::Iso22301_7_2,
            ],
            [
                'code' => 'BC-ITDR',
                'name' => 'IT DR responder',
                'description' => 'For the person who executes a runbook, a failover or a failback.',
                'roles' => ['it-dr-manager', 'on-call'],
                'modules' => [
                    ['code' => 'runbook-execution', 'title' => 'Executing a failover runbook under time pressure', 'minutes' => 25, 'outcome' => 'Execute a failover runbook against its recorded steps'],
                    ['code' => 'open-banking-30-min', 'title' => 'The 30-minute Open Banking failover threshold, and what breaches it', 'minutes' => 20, 'outcome' => 'State the 30-minute threshold and the actions that protect it'],
                    ['code' => 'failback-and-verification', 'title' => 'Failback, data reconciliation and verifying recovery objectives were met', 'minutes' => 25, 'outcome' => 'Verify RTO/RPO achievement after a failback'],
                    ['code' => 'recording-a-dr-test', 'title' => 'Recording a DR test result, its issues and its evidence', 'minutes' => 15, 'outcome' => 'Record a complete DR test result including any rollback'],
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => true,
                'pass_mark' => 80,
                'clause' => IsoClauseRef::Iso22301_7_2,
            ],
        ];
    }
}
