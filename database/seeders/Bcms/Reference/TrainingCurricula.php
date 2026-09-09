<?php

namespace Database\Seeders\Bcms\Reference;

use App\Enums\Bcms\IsoClauseRef;

/**
 * The shipped BC training curricula (ISO 22301 clauses 7.2 and 7.3).
 *
 * AWARENESS AND COMPETENCE ARE DIFFERENT CLAUSES AND DIFFERENT RECORDS. 7.3
 * asks whether people are aware; 7.2 asks whether the people whose work affects
 * BCMS performance are COMPETENT, and requires documented evidence of it. A
 * curriculum with `requires_assessment` false produces an attendance record and
 * nothing more, which is the correct artefact for awareness and the wrong one
 * for competence. The three role-based curricula below therefore assess; the
 * all-staff one does not.
 */
class TrainingCurricula
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'BC-AWARE',
                'name' => 'Business continuity awareness (all staff)',
                'description' => 'What the BCMS is, what to do when the alarm sounds, how you will be contacted and what you are expected to do about it.',
                'roles' => ['*'],
                'modules' => [
                    'Why continuity matters here, and what a disruption costs',
                    'How you will be contacted in an emergency, and keeping your details current',
                    'Evacuation, assembly points and the roll-call',
                    'Your department\'s plan and where to find it',
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => false,
                'clause' => IsoClauseRef::Iso22301_7_3,
            ],
            [
                'code' => 'BC-CHAMPION',
                'name' => 'Department BC champion',
                'description' => 'For the person who owns their unit\'s BIA, plan, call tree and readiness tasks.',
                'roles' => ['risk-owner'],
                'modules' => [
                    'Completing a business impact analysis: MTPD, RTO, RPO and MBCO',
                    'Identifying dependencies, including the ones nobody writes down',
                    'Maintaining the department plan and its call tree',
                    'Running the readiness checklist before an exercise',
                    'Raising a finding, and what makes a corrective action verifiable',
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => true,
                'pass_mark' => 70,
                'clause' => IsoClauseRef::Iso22301_7_2,
            ],
            [
                'code' => 'BC-FACILITATOR',
                'name' => 'Exercise facilitator',
                'description' => 'For the person who designs, runs and evaluates an exercise.',
                'roles' => ['risk-manager'],
                'modules' => [
                    'The ISO 22398 ladder and choosing the right rung',
                    'Writing aims, objectives and a scenario that validates them',
                    'Running the day: injects, the timeline and the decision log',
                    'Evaluating against objectives rather than against effort',
                    'The after-action report and turning observations into tracked actions',
                ],
                'frequency_months' => 24,
                'mandatory' => false,
                'assess' => true,
                'pass_mark' => 70,
                'clause' => IsoClauseRef::Iso22301_7_2,
            ],
            [
                'code' => 'BC-CRISIS',
                'name' => 'Crisis management team',
                'description' => 'For the executives who declare an incident, activate plans and authorise communication.',
                'roles' => ['chief-risk-officer'],
                'modules' => [
                    'Activation criteria and the authority to declare',
                    'Decision making under uncertainty, and logging as you go',
                    'Stakeholder, customer and regulator communication',
                    'The CBN notification thresholds and their clocks',
                    'Authorising a live emergency notification, and the dual-approval rule',
                ],
                'frequency_months' => 12,
                'mandatory' => true,
                'assess' => true,
                'pass_mark' => 70,
                'clause' => IsoClauseRef::Iso22361_crisis,
            ],
        ];
    }
}
