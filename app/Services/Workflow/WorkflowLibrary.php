<?php

namespace App\Services\Workflow;

/**
 * WP-06 TASK 4 — the ten processes the platform ships with.
 *
 * These reproduce, as graphs, exactly the approval behaviour the modules had as
 * hardcoded controller branches, so an organization upgrading sees the same
 * decisions asked of the same people. The differences are the ones the old
 * shape could not express and which every one of these customers asked for:
 * an SLA, a real escalation, a second level above a threshold, and a parallel
 * review where two functions genuinely review in parallel rather than queue.
 *
 * SLAs ARE NOT INVENTED. Where a regulator sets the clock, the SLA is that
 * clock less a working margin — a loss event over the CBN reporting threshold
 * has to reach the regulator within 7 days (CBN BSD/DIR/GEN/LAB/07/014), so the
 * internal decision cannot take 7 days. Everything else is set to a business
 * week and is meant to be changed in the designer, which is the point of having
 * one.
 */
class WorkflowLibrary
{
    public const APPROVER_ROLES = ['risk-manager', 'chief-risk-officer'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::riskAssessmentApproval(),
            self::treatmentPlanApproval(),
            self::controlTestReview(),
            self::lossEventApproval(),
            self::issueClosureApproval(),
            self::riskAppetiteApproval(),
            self::riskAcceptanceApproval(),
            self::thresholdRebaselining(),
            self::policyApproval(),
            self::icaapSignOff(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function byCode(): array
    {
        return collect(self::definitions())->keyBy('code')->all();
    }

    /* ================================================================== */

    private static function riskAssessmentApproval(): array
    {
        return [
            'code' => 'risk_assessment_approval',
            'name' => 'Risk assessment approval',
            'description' => 'A submitted assessment is reviewed by the assigned reviewer, or by a risk manager '
                .'when none is assigned. Approval pushes the scores onto the parent risk.',
            'entity_type' => 'risk_assessment',
            'trigger' => 'manual',
            'escalation_rules' => [
                ['after_hours' => 48, 'action' => 'escalate', 'escalate_to' => ['rule' => 'role', 'roles' => ['chief-risk-officer']]],
            ],
            'definition' => [
                'nodes' => [
                    self::start('submitted', 'Submitted for review'),
                    [
                        'code' => 'review',
                        'type' => 'approval',
                        'name' => 'Reviewer decision',
                        'assignee_rule' => 'delegate',
                        'assignee_config' => ['fallback_roles' => self::APPROVER_ROLES],
                        'sla_hours' => 72,
                        'on_timeout' => 'escalate',
                        'escalate_to' => ['rule' => 'role', 'config' => ['roles' => ['chief-risk-officer']]],
                        'allow_delegate' => true,
                        'allow_return' => true,
                        'instructions' => 'Review the likelihood, impact and residual scores against the evidence attached.',
                        'x' => 240, 'y' => 40,
                    ],
                    self::end('approved', 'Approved', 'approved', 460, 0),
                    self::end('rejected', 'Rejected', 'rejected', 460, 120),
                ],
                'edges' => [
                    ['from' => 'submitted', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'rejected', 'when' => "outcome == 'reject'", 'label' => 'Rejected'],
                    ['from' => 'review', 'to' => 'approved', 'label' => 'Approved'],
                ],
            ],
        ];
    }

    private static function treatmentPlanApproval(): array
    {
        return [
            'code' => 'treatment_plan_approval',
            'name' => 'Treatment plan approval',
            'description' => 'A plan submitted for review is approved by a risk manager. Plans above the '
                .'cost threshold need the CRO as well.',
            'entity_type' => 'treatment_plan',
            'trigger' => 'manual',
            'definition' => [
                'nodes' => [
                    self::start('submitted', 'Submitted for review'),
                    [
                        'code' => 'risk_review',
                        'type' => 'approval',
                        'name' => 'Risk manager review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['risk-manager']],
                        'sla_hours' => 120,
                        'on_timeout' => 'escalate',
                        'escalate_to' => ['rule' => 'role', 'config' => ['roles' => ['chief-risk-officer']]],
                        'allow_return' => true,
                        'x' => 220, 'y' => 40,
                    ],
                    [
                        'code' => 'cost_check',
                        'type' => 'exclusive_gateway',
                        'name' => 'Above the cost threshold?',
                        'x' => 420, 'y' => 40,
                    ],
                    [
                        'code' => 'cro_review',
                        'type' => 'approval',
                        'name' => 'CRO sign-off',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['chief-risk-officer']],
                        'sla_hours' => 120,
                        'on_timeout' => 'escalate',
                        'x' => 620, 'y' => 0,
                        'instructions' => 'This plan is above the delegated cost limit and needs your sign-off.',
                    ],
                    self::end('approved', 'Approved', 'approved', 820, 40),
                    self::end('rejected', 'Rejected', 'rejected', 420, 180),
                ],
                'edges' => [
                    ['from' => 'submitted', 'to' => 'risk_review'],
                    ['from' => 'risk_review', 'to' => 'rejected', 'when' => "outcome == 'reject'", 'label' => 'Rejected'],
                    ['from' => 'risk_review', 'to' => 'cost_check', 'label' => 'Approved'],
                    [
                        'from' => 'cost_check', 'to' => 'cro_review',
                        // 50,000,000 naira. cost_estimate_ngn is the canonical
                        // column (WP-01); the duplicate estimated_cost is not read.
                        'when' => "get(subject, 'cost_estimate_ngn') > 50000000",
                        'label' => 'Over ₦50m',
                    ],
                    ['from' => 'cost_check', 'to' => 'approved', 'label' => 'Within limit'],
                    ['from' => 'cro_review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'cro_review', 'to' => 'approved'],
                ],
            ],
        ];
    }

    private static function controlTestReview(): array
    {
        return [
            'code' => 'control_test_review',
            'name' => 'Control test review',
            'description' => 'A completed test is reviewed by its assigned reviewer. Rejection sends the test '
                .'back to the tester rather than closing it.',
            'entity_type' => 'control_test',
            'trigger' => 'manual',
            'definition' => [
                'nodes' => [
                    self::start('submitted', 'Test submitted'),
                    [
                        'code' => 'review',
                        'type' => 'approval',
                        'name' => 'Reviewer decision',
                        'assignee_rule' => 'delegate',
                        'assignee_config' => ['fallback_roles' => ['risk-manager', 'compliance-officer', 'chief-risk-officer']],
                        'sla_hours' => 72,
                        'on_timeout' => 'escalate',
                        'allow_return' => true,
                        'return_to' => 'submitted',
                        'instructions' => 'Check the test evidence supports the result recorded.',
                        'x' => 240, 'y' => 40,
                    ],
                    self::end('approved', 'Completed', 'approved', 460, 0),
                    self::end('rejected', 'Rejected', 'rejected', 460, 120),
                ],
                'edges' => [
                    ['from' => 'submitted', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'review', 'to' => 'approved'],
                ],
            ],
        ];
    }

    /**
     * The multi-stage one, matching the level_1 / level_2 / level_3 stage model
     * already in loss_event_approvals — and adding the thing that model never
     * had: the regulatory clock.
     */
    private static function lossEventApproval(): array
    {
        return [
            'code' => 'loss_event_approval',
            'name' => 'Loss event approval',
            'description' => 'Three levels, escalating with the size of the loss. A CBN-reportable event runs '
                .'the compliance review in parallel so the regulatory clock is not spent queueing.',
            'entity_type' => 'loss_event',
            'trigger' => 'manual',
            'escalation_rules' => [
                ['after_hours' => 24, 'action' => 'escalate', 'escalate_to' => ['rule' => 'role', 'roles' => ['chief-risk-officer']]],
            ],
            'definition' => [
                'nodes' => [
                    self::start('recorded', 'Recorded'),
                    [
                        'code' => 'level_1',
                        'type' => 'approval',
                        'name' => 'Level 1 — loss event manager',
                        'assignee_rule' => 'owner',
                        'assignee_config' => ['fallback_roles' => ['loss-event-manager']],
                        'sla_hours' => 24,
                        'on_timeout' => 'escalate',
                        'escalate_to' => ['rule' => 'role', 'config' => ['roles' => ['chief-risk-officer']]],
                        'allow_return' => true,
                        'x' => 200, 'y' => 60,
                    ],
                    [
                        'code' => 'reportable_check',
                        'type' => 'exclusive_gateway',
                        'name' => 'CBN reportable?',
                        'x' => 380, 'y' => 60,
                    ],
                    [
                        'code' => 'regulatory_fork',
                        'type' => 'parallel_gateway',
                        'name' => 'Risk and compliance, in parallel',
                        'x' => 540, 'y' => 60,
                    ],
                    [
                        'code' => 'level_2',
                        'type' => 'approval',
                        'name' => 'Level 2 — CRO',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['chief-risk-officer']],
                        // Two days, inside the CBN seven-day reporting window,
                        // leaving the compliance team time to file.
                        'sla_hours' => 48,
                        'on_timeout' => 'escalate',
                        'x' => 720, 'y' => 0,
                    ],
                    [
                        'code' => 'compliance_review',
                        'type' => 'approval',
                        'name' => 'Regulatory reporting review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['compliance-officer']],
                        'sla_hours' => 48,
                        'on_timeout' => 'escalate',
                        'instructions' => 'Confirm the CBN/NFIU reporting position and the filing deadline for this event.',
                        'x' => 720, 'y' => 130,
                    ],
                    [
                        'code' => 'regulatory_join',
                        'type' => 'join',
                        'name' => 'Both reviews complete',
                        'x' => 900, 'y' => 60,
                    ],
                    self::end('approved', 'Approved', 'approved', 1080, 60),
                    self::end('rejected', 'Returned to investigation', 'rejected', 380, 200),
                ],
                'edges' => [
                    ['from' => 'recorded', 'to' => 'level_1'],
                    ['from' => 'level_1', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'level_1', 'to' => 'reportable_check'],
                    [
                        'from' => 'reportable_check', 'to' => 'regulatory_fork',
                        'when' => "get(subject, 'is_regulatory_reportable') == true or get(subject, 'net_loss_kobo') > 100000000000",
                        'label' => 'Reportable or over ₦1bn',
                    ],
                    ['from' => 'reportable_check', 'to' => 'approved', 'label' => 'Within delegated limit'],
                    ['from' => 'regulatory_fork', 'to' => 'level_2'],
                    ['from' => 'regulatory_fork', 'to' => 'compliance_review'],
                    ['from' => 'level_2', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'level_2', 'to' => 'regulatory_join'],
                    ['from' => 'compliance_review', 'to' => 'regulatory_join'],
                    ['from' => 'regulatory_join', 'to' => 'approved'],
                ],
            ],
        ];
    }

    private static function issueClosureApproval(): array
    {
        return [
            'code' => 'issue_closure_approval',
            'name' => 'Issue closure approval',
            'description' => 'Closure of an issue is approved by the issue manager. A regulatory issue also '
                .'needs compliance sign-off before it may be closed.',
            'entity_type' => 'issue',
            'trigger' => 'manual',
            'definition' => [
                'nodes' => [
                    self::start('requested', 'Closure requested'),
                    [
                        'code' => 'issue_review',
                        'type' => 'approval',
                        'name' => 'Issue manager review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['issue-manager', 'risk-manager']],
                        'sla_hours' => 72,
                        'on_timeout' => 'escalate',
                        'allow_return' => true,
                        'instructions' => 'Confirm the remediation evidence closes the finding.',
                        'x' => 220, 'y' => 40,
                    ],
                    [
                        'code' => 'regulatory_check',
                        'type' => 'exclusive_gateway',
                        'name' => 'Regulatory issue?',
                        'x' => 420, 'y' => 40,
                    ],
                    [
                        'code' => 'compliance_signoff',
                        'type' => 'approval',
                        'name' => 'Compliance sign-off',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['compliance-officer']],
                        'sla_hours' => 72,
                        'on_timeout' => 'escalate',
                        'x' => 620, 'y' => 0,
                    ],
                    self::end('approved', 'Closed', 'approved', 820, 40),
                    self::end('rejected', 'Closure refused', 'rejected', 420, 180),
                ],
                'edges' => [
                    ['from' => 'requested', 'to' => 'issue_review'],
                    ['from' => 'issue_review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'issue_review', 'to' => 'regulatory_check'],
                    [
                        'from' => 'regulatory_check', 'to' => 'compliance_signoff',
                        'when' => "get(subject, 'regulatory_reportable') == true or get(subject, 'cbn_reportable') == true",
                        'label' => 'Regulatory',
                    ],
                    ['from' => 'regulatory_check', 'to' => 'approved', 'label' => 'Internal'],
                    ['from' => 'compliance_signoff', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'compliance_signoff', 'to' => 'approved'],
                ],
            ],
        ];
    }

    private static function riskAppetiteApproval(): array
    {
        return [
            'code' => 'risk_appetite_approval',
            'name' => 'Risk appetite approval',
            'description' => 'An appetite statement is proposed by the risk function and approved by the board. '
                .'Until it is approved it is not in force.',
            'entity_type' => 'risk_appetite',
            'trigger' => 'manual',
            'definition' => [
                'nodes' => [
                    self::start('proposed', 'Proposed'),
                    [
                        'code' => 'cro_review',
                        'type' => 'approval',
                        'name' => 'CRO review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['chief-risk-officer']],
                        'sla_hours' => 168,
                        'on_timeout' => 'notify',
                        'allow_return' => true,
                        'x' => 220, 'y' => 40,
                    ],
                    [
                        'code' => 'board_approval',
                        'type' => 'approval',
                        'name' => 'Board approval',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['board-member']],
                        // A board meets monthly at best; escalating an appetite
                        // statement after two days would be noise, so this one
                        // reminds rather than escalates.
                        'sla_hours' => 720,
                        'on_timeout' => 'notify',
                        'allow_return' => true,
                        'x' => 440, 'y' => 40,
                    ],
                    self::end('approved', 'In force', 'approved', 660, 0),
                    self::end('rejected', 'Not approved', 'rejected', 660, 120),
                ],
                'edges' => [
                    ['from' => 'proposed', 'to' => 'cro_review'],
                    ['from' => 'cro_review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'cro_review', 'to' => 'board_approval'],
                    ['from' => 'board_approval', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'board_approval', 'to' => 'approved'],
                ],
            ],
        ];
    }

    private static function riskAcceptanceApproval(): array
    {
        return [
            'code' => 'risk_acceptance_approval',
            'name' => 'Risk acceptance approval',
            'description' => 'Accepting a risk rather than treating it. A high or critical residual rating '
                .'requires the CRO; anything above that goes to the board.',
            'entity_type' => 'risk',
            'trigger' => 'manual',
            'definition' => [
                'nodes' => [
                    self::start('requested', 'Acceptance requested'),
                    [
                        'code' => 'risk_review',
                        'type' => 'approval',
                        'name' => 'Risk manager review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['risk-manager']],
                        'sla_hours' => 120,
                        'on_timeout' => 'escalate',
                        'allow_return' => true,
                        'required_fields' => ['comments'],
                        'instructions' => 'State why treating this risk is not proportionate, and for how long the acceptance should stand.',
                        'x' => 220, 'y' => 40,
                    ],
                    [
                        'code' => 'severity_check',
                        'type' => 'exclusive_gateway',
                        'name' => 'How severe is the residual risk?',
                        'x' => 420, 'y' => 40,
                    ],
                    [
                        'code' => 'cro_approval',
                        'type' => 'approval',
                        'name' => 'CRO approval',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['chief-risk-officer']],
                        'sla_hours' => 120,
                        'on_timeout' => 'escalate',
                        'x' => 620, 'y' => 0,
                    ],
                    self::end('approved', 'Accepted', 'approved', 840, 40),
                    self::end('rejected', 'Acceptance refused', 'rejected', 420, 180),
                ],
                'edges' => [
                    ['from' => 'requested', 'to' => 'risk_review'],
                    ['from' => 'risk_review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'risk_review', 'to' => 'severity_check'],
                    [
                        'from' => 'severity_check', 'to' => 'cro_approval',
                        'when' => "in_list(get(subject, 'residual_rating'), ['High', 'Critical', 'Very High'])",
                        'label' => 'High or above',
                    ],
                    ['from' => 'severity_check', 'to' => 'approved', 'label' => 'Medium or below'],
                    ['from' => 'cro_approval', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'cro_approval', 'to' => 'approved'],
                ],
            ],
        ];
    }

    /**
     * WP-04's re-baselining task, now a workflow rather than a bare approval
     * request. The subject is the threshold in force; approval writes its
     * effective-dated replacement.
     */
    private static function thresholdRebaselining(): array
    {
        return [
            'code' => 'threshold_rebaselining_approval',
            'name' => 'Threshold re-baselining approval',
            'description' => 'A formula threshold whose computed band has drifted past tolerance at period close. '
                .'Approving puts a new effective-dated band set in force; the band it replaces is retained.',
            'entity_type' => 'measure_threshold',
            'trigger' => 'on_event',
            'trigger_config' => ['event' => 'measure.threshold.drifted'],
            'definition' => [
                'nodes' => [
                    self::start('raised', 'Drift detected at period close'),
                    [
                        'code' => 'owner_review',
                        'type' => 'approval',
                        'name' => 'Measure owner review',
                        'assignee_rule' => 'owner',
                        'assignee_config' => ['fallback_roles' => ['risk-manager']],
                        'sla_hours' => 120,
                        'on_timeout' => 'escalate',
                        'escalate_to' => ['rule' => 'role', 'config' => ['roles' => ['chief-risk-officer']]],
                        'allow_return' => false,
                        'instructions' => 'The computed limit has moved. Approving supersedes the band in force from '
                            .'the day after period end; rejecting leaves the current band untouched.',
                        'x' => 240, 'y' => 40,
                    ],
                    self::end('approved', 'Re-baselined', 'approved', 470, 0),
                    self::end('rejected', 'Band unchanged', 'rejected', 470, 120),
                ],
                'edges' => [
                    ['from' => 'raised', 'to' => 'owner_review'],
                    ['from' => 'owner_review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'owner_review', 'to' => 'approved'],
                ],
            ],
        ];
    }

    /**
     * Policy approval runs over a graph object of type Policy.
     *
     * There is no policies table, and inventing one here would be a schema
     * decision belonging to a later work package. WP-03 already gives every
     * governed thing a typed node, and WP-05 lets a configurer add attributes
     * to it, so a policy IS an object — and this definition proves the engine
     * runs over configurer-defined types, not only over the ten tables that
     * happen to exist.
     */
    private static function policyApproval(): array
    {
        return [
            'code' => 'policy_approval',
            'name' => 'Policy approval',
            'description' => 'Review and approval of a policy document held in the object graph. Approval moves '
                .'the policy to its in-force lifecycle state.',
            'entity_type' => 'graph_object',
            'object_type_code' => 'Policy',
            'trigger' => 'manual',
            'trigger_config' => ['approved_state' => 'approved', 'rejected_state' => 'draft', 'draft_state' => 'draft'],
            'definition' => [
                'nodes' => [
                    self::start('drafted', 'Drafted'),
                    [
                        'code' => 'compliance_review',
                        'type' => 'approval',
                        'name' => 'Compliance review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['compliance-officer']],
                        'sla_hours' => 168,
                        'on_timeout' => 'escalate',
                        'allow_return' => true,
                        'x' => 220, 'y' => 40,
                    ],
                    [
                        'code' => 'owner_approval',
                        'type' => 'approval',
                        'name' => 'Policy owner approval',
                        'assignee_rule' => 'owner',
                        'assignee_config' => ['fallback_roles' => ['chief-risk-officer']],
                        'sla_hours' => 168,
                        'on_timeout' => 'escalate',
                        'allow_return' => true,
                        'x' => 440, 'y' => 40,
                    ],
                    self::end('approved', 'In force', 'approved', 660, 0),
                    self::end('rejected', 'Returned to draft', 'rejected', 660, 120),
                ],
                'edges' => [
                    ['from' => 'drafted', 'to' => 'compliance_review'],
                    ['from' => 'compliance_review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'compliance_review', 'to' => 'owner_approval'],
                    ['from' => 'owner_approval', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'owner_approval', 'to' => 'approved'],
                ],
            ],
        ];
    }

    private static function icaapSignOff(): array
    {
        return [
            'code' => 'icaap_signoff',
            'name' => 'ICAAP sign-off',
            'description' => 'Internal Capital Adequacy Assessment Process sign-off. Board approval is what CBN '
                .'expects on the submitted document, so it is the last step and the only one that dates it.',
            'entity_type' => 'icaap_assessment',
            'trigger' => 'manual',
            'definition' => [
                'nodes' => [
                    self::start('prepared', 'Prepared'),
                    [
                        'code' => 'cro_review',
                        'type' => 'approval',
                        'name' => 'CRO review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['chief-risk-officer']],
                        'sla_hours' => 168,
                        'on_timeout' => 'escalate',
                        'allow_return' => true,
                        'x' => 200, 'y' => 60,
                    ],
                    [
                        'code' => 'adequacy_check',
                        'type' => 'exclusive_gateway',
                        'name' => 'CAR below the CBN minimum?',
                        'x' => 400, 'y' => 60,
                    ],
                    [
                        'code' => 'capital_plan',
                        'type' => 'approval',
                        'name' => 'Capital plan required',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['chief-risk-officer']],
                        'sla_hours' => 72,
                        'on_timeout' => 'escalate',
                        'required_fields' => ['comments'],
                        'instructions' => 'The capital adequacy ratio is below the CBN minimum. Attach the capital '
                            .'restoration plan before this goes to the board.',
                        'x' => 600, 'y' => 0,
                    ],
                    [
                        'code' => 'board_signoff',
                        'type' => 'approval',
                        'name' => 'Board sign-off',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['board-member']],
                        'sla_hours' => 720,
                        'on_timeout' => 'notify',
                        'allow_return' => true,
                        'x' => 800, 'y' => 60,
                    ],
                    self::end('approved', 'Approved by the board', 'approved', 1000, 60),
                    self::end('rejected', 'Returned to draft', 'rejected', 400, 200),
                ],
                'edges' => [
                    ['from' => 'prepared', 'to' => 'cro_review'],
                    ['from' => 'cro_review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'cro_review', 'to' => 'adequacy_check'],
                    [
                        'from' => 'adequacy_check', 'to' => 'capital_plan',
                        'when' => "get(subject, 'below_minimum') == true",
                        'label' => 'Below minimum',
                    ],
                    ['from' => 'adequacy_check', 'to' => 'board_signoff', 'label' => 'Adequate'],
                    ['from' => 'capital_plan', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'capital_plan', 'to' => 'board_signoff'],
                    ['from' => 'board_signoff', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'board_signoff', 'to' => 'approved'],
                ],
            ],
        ];
    }

    /* ================================================================== */

    private static function start(string $code, string $name): array
    {
        return ['code' => $code, 'type' => 'start', 'name' => $name, 'x' => 40, 'y' => 40];
    }

    private static function end(string $code, string $name, string $outcome, int $x, int $y): array
    {
        return ['code' => $code, 'type' => 'end', 'name' => $name, 'outcome' => $outcome, 'x' => $x, 'y' => $y];
    }
}
