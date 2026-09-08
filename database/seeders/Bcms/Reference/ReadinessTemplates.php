<?php

namespace Database\Seeders\Bcms\Reference;

/**
 * The shipped readiness checklists — the gating feature Blueprint §3.2 shows no
 * competitor has, and which is worthless if it is advisory.
 *
 * A BLOCKING TASK STOPS THE EXERCISE. That is the whole point: a DR failover
 * where nobody confirmed the restore point, or a fire drill where the assembly
 * marshals were never briefed, is an exercise that produces a finding about the
 * exercise rather than about the plan. `is_blocking` is set sparingly and
 * deliberately — on the tasks whose absence makes the exercise meaningless, not
 * on every task, because a checklist that blocks on everything is a checklist
 * people learn to override.
 *
 * `due_offset_days` IS SIGNED AND THE POSITIVE ONES MATTER. Returning a DR site
 * to production and distributing an AAR are readiness tasks for the NEXT
 * exercise as much as closing tasks for this one, and a checklist that ends on
 * the day of the exercise leaves them to nobody.
 */
class ReadinessTemplates
{
    /**
     * @return list<array{code: string, name: string, description: string, tasks: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'GENERIC',
                'name' => 'General exercise readiness',
                'description' => 'The default checklist applied to any exercise type without one of its own.',
                'tasks' => [
                    ['title' => 'Confirm the scenario and objectives with the exercise owner', 'offset' => -10, 'blocking' => true, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Confirm the participant list and their deputies', 'offset' => -9, 'blocking' => true, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Verify contact details for every participant', 'offset' => -8, 'blocking' => false, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Book the venue or the bridge and confirm access', 'offset' => -7, 'blocking' => false, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Circulate the plan version being exercised', 'offset' => -5, 'blocking' => true, 'evidence' => true, 'role' => 'facilitator'],
                    ['title' => 'Confirm evaluators and brief them on the scoring', 'offset' => -3, 'blocking' => false, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Confirm carried-forward corrective actions from the last exercise are on the agenda', 'offset' => -2, 'blocking' => true, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Draft the after-action report', 'offset' => 3, 'blocking' => false, 'evidence' => true, 'role' => 'facilitator'],
                    ['title' => 'Distribute the approved after-action report', 'offset' => 10, 'blocking' => false, 'evidence' => true, 'role' => 'coordinator'],
                ],
            ],
            [
                'code' => 'DRFAILOVER',
                'name' => 'DR failover readiness',
                'description' => 'For a failover or failback exercise, where the cost of being unprepared is a real outage.',
                'tasks' => [
                    ['title' => 'Confirm the change window and obtain change approval', 'offset' => -10, 'blocking' => true, 'evidence' => true, 'role' => 'it_dr_manager'],
                    ['title' => 'Confirm and verify the restore point and the last successful backup', 'offset' => -8, 'blocking' => true, 'evidence' => true, 'role' => 'it_dr_manager'],
                    ['title' => 'Confirm the runbook version and that every step has a named executor', 'offset' => -7, 'blocking' => true, 'evidence' => true, 'role' => 'it_dr_manager'],
                    ['title' => 'Confirm the rollback plan and the decision point for invoking it', 'offset' => -7, 'blocking' => true, 'evidence' => true, 'role' => 'it_dr_manager'],
                    ['title' => 'Notify affected business units and confirm they can absorb the window', 'offset' => -5, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Confirm vendor support cover for the window', 'offset' => -5, 'blocking' => false, 'evidence' => false, 'role' => 'it_dr_manager'],
                    ['title' => 'Confirm the RTO and RPO targets being measured against', 'offset' => -3, 'blocking' => true, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Confirm monitoring and timing are in place to measure actual RTO', 'offset' => -2, 'blocking' => true, 'evidence' => false, 'role' => 'it_dr_manager'],
                    ['title' => 'Return the environment to production and confirm normal service', 'offset' => 1, 'blocking' => true, 'evidence' => true, 'role' => 'it_dr_manager'],
                    ['title' => 'Record actual RTO and RPO against target', 'offset' => 2, 'blocking' => true, 'evidence' => true, 'role' => 'it_dr_manager'],
                ],
            ],
            [
                'code' => 'FIREDRILL',
                'name' => 'Evacuation drill readiness',
                'description' => 'For a fire drill or evacuation, where the readiness tasks are about people and premises rather than systems.',
                'tasks' => [
                    ['title' => 'Confirm the date with facilities and building management', 'offset' => -10, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Confirm assembly point and that it is accessible and safe', 'offset' => -8, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Brief floor marshals and confirm deputies for anyone on leave', 'offset' => -5, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Confirm the roster used for the headcount is current', 'offset' => -3, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Confirm arrangements for anyone needing assisted evacuation', 'offset' => -3, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Notify security, the alarm monitoring provider and the fire service of a planned drill', 'offset' => -2, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Confirm visitors and contractors on site are accounted for in the roster', 'offset' => -1, 'blocking' => false, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Reconcile the headcount and record anyone unaccounted for', 'offset' => 0, 'blocking' => true, 'evidence' => true, 'role' => 'facilitator'],
                ],
            ],
            [
                'code' => 'CALLTREE',
                'name' => 'Call tree test readiness',
                'description' => 'Short lead time on purpose: a cascade announced ten days out measures whether people remembered, not whether the tree works.',
                'tasks' => [
                    ['title' => 'Confirm the tree version being tested and that it is approved', 'offset' => -5, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Run the contact hygiene report and fix anything flagged invalid', 'offset' => -3, 'blocking' => true, 'evidence' => true, 'role' => 'coordinator'],
                    ['title' => 'Confirm the test is in simulation mode and the exercise prefix is applied', 'offset' => -1, 'blocking' => true, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Confirm the escalation path for a node that does not respond', 'offset' => -1, 'blocking' => false, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Raise corrective actions for every broken branch', 'offset' => 2, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                ],
            ],
            [
                'code' => 'CYBER',
                'name' => 'Cyber incident exercise readiness',
                'description' => 'For a cyber scenario, where the regulatory clock and the assumption of compromise are what make it different.',
                'tasks' => [
                    ['title' => 'Agree the scenario with the CISO and confirm it is severe but plausible', 'offset' => -10, 'blocking' => true, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Confirm which systems the scenario assumes are compromised, and that recovery does not depend on them', 'offset' => -7, 'blocking' => true, 'evidence' => false, 'role' => 'it_dr_manager'],
                    ['title' => 'Confirm out-of-band communication for the crisis team', 'offset' => -5, 'blocking' => true, 'evidence' => false, 'role' => 'coordinator'],
                    ['title' => 'Confirm the CBN and NigFinCERT notification thresholds and timings being exercised', 'offset' => -5, 'blocking' => true, 'evidence' => true, 'role' => 'compliance'],
                    ['title' => 'Confirm no exercise traffic can be mistaken for a real incident by an external party', 'offset' => -2, 'blocking' => true, 'evidence' => false, 'role' => 'facilitator'],
                    ['title' => 'Draft the regulatory notification produced during the exercise and file it as evidence', 'offset' => 2, 'blocking' => false, 'evidence' => true, 'role' => 'compliance'],
                ],
            ],
        ];
    }
}
