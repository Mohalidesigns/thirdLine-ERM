<?php

namespace Tests\Support;

use App\Models\WorkflowDefinition;
use App\Services\Workflow\WorkflowPublisher;

/**
 * Definitions for the engine tests.
 *
 * Hand-written rather than taken from WorkflowLibrary: the library's shapes are
 * the ones that must not change under a customer, and a test that asserted
 * against them would fail every time somebody legitimately adjusts a shipped
 * SLA. These exercise the ENGINE — fork, join, condition, escalation, return —
 * with the smallest graph that can express each.
 */
trait CreatesWorkflowFixtures
{
    /**
     * A linear approval: start → review → approved | rejected.
     */
    protected function linearDefinition(array $overrides = []): WorkflowDefinition
    {
        return $this->publishDefinition(array_merge([
            'code' => 'linear_review',
            'name' => 'Linear review',
            'entity_type' => 'risk_assessment',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Submitted'],
                    [
                        'code' => 'review', 'type' => 'approval', 'name' => 'Review',
                        'assignee_rule' => 'role',
                        'assignee_config' => ['roles' => ['risk-manager']],
                        'sla_hours' => 24,
                        'on_timeout' => 'escalate',
                        'escalate_to' => ['rule' => 'role', 'config' => ['roles' => ['chief-risk-officer']]],
                        'allow_delegate' => true,
                        'allow_return' => true,
                    ],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                    ['code' => 'rejected', 'type' => 'end', 'name' => 'Rejected', 'outcome' => 'rejected'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'review', 'to' => 'approved'],
                ],
            ],
        ], $overrides));
    }

    /**
     * A fork and a join: two reviews run at once and the process continues only
     * when both are in.
     */
    protected function parallelDefinition(array $overrides = []): WorkflowDefinition
    {
        return $this->publishDefinition(array_merge([
            'code' => 'parallel_review',
            'name' => 'Parallel review',
            'entity_type' => 'loss_event',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Recorded'],
                    ['code' => 'fork', 'type' => 'parallel_gateway', 'name' => 'Both at once'],
                    [
                        'code' => 'risk_review', 'type' => 'approval', 'name' => 'Risk review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']],
                    ],
                    [
                        'code' => 'compliance_review', 'type' => 'approval', 'name' => 'Compliance review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['compliance-officer']],
                    ],
                    ['code' => 'join', 'type' => 'join', 'name' => 'Both complete'],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'fork'],
                    ['from' => 'fork', 'to' => 'risk_review'],
                    ['from' => 'fork', 'to' => 'compliance_review'],
                    ['from' => 'risk_review', 'to' => 'join'],
                    ['from' => 'compliance_review', 'to' => 'join'],
                    ['from' => 'join', 'to' => 'approved'],
                ],
            ],
        ], $overrides));
    }

    /**
     * Two sequential human steps, so a return has somewhere to go back to.
     */
    protected function twoStepDefinition(array $overrides = []): WorkflowDefinition
    {
        return $this->publishDefinition(array_merge([
            'code' => 'two_step_review',
            'name' => 'Two-step review',
            'entity_type' => 'risk_assessment',
            'definition' => [
                'nodes' => [
                    ['code' => 'start', 'type' => 'start', 'name' => 'Submitted'],
                    [
                        'code' => 'first', 'type' => 'approval', 'name' => 'First review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['risk-manager']],
                    ],
                    [
                        'code' => 'second', 'type' => 'approval', 'name' => 'Second review',
                        'assignee_rule' => 'role', 'assignee_config' => ['roles' => ['chief-risk-officer']],
                        'allow_return' => true,
                    ],
                    ['code' => 'approved', 'type' => 'end', 'name' => 'Approved', 'outcome' => 'approved'],
                    ['code' => 'rejected', 'type' => 'end', 'name' => 'Rejected', 'outcome' => 'rejected'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'first'],
                    ['from' => 'first', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'first', 'to' => 'second'],
                    ['from' => 'second', 'to' => 'rejected', 'when' => "outcome == 'reject'"],
                    ['from' => 'second', 'to' => 'approved'],
                ],
            ],
        ], $overrides));
    }

    /** @param array<string, mixed> $attributes */
    protected function publishDefinition(array $attributes): WorkflowDefinition
    {
        $publisher = app(WorkflowPublisher::class);

        $definition = $publisher->saveDraft(null, array_merge([
            'organization_id' => $this->organization->id,
            'trigger' => 'manual',
            'is_active' => true,
        ], $attributes));

        return $publisher->publish($definition);
    }
}
