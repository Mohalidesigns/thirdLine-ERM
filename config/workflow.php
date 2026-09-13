<?php

use App\Services\Workflow\Subjects\ControlTestBinding;
use App\Services\Workflow\Subjects\GenericSubjectBinding;
use App\Services\Workflow\Subjects\IcaapAssessmentBinding;
use App\Services\Workflow\Subjects\IssueClosureBinding;
use App\Services\Workflow\Subjects\LossEventBinding;
use App\Services\Workflow\Subjects\MeasureThresholdBinding;
use App\Services\Workflow\Subjects\RiskAcceptanceBinding;
use App\Services\Workflow\Subjects\RiskAppetiteBinding;
use App\Services\Workflow\Subjects\RiskAssessmentBinding;
use App\Services\Workflow\Subjects\TreatmentPlanBinding;

return [

    /*
    |--------------------------------------------------------------------------
    | Subject bindings
    |--------------------------------------------------------------------------
    |
    | morph alias => the class that knows which Gate guards a decision on this
    | kind of thing, which owner the `owner` assignee rule means, and which
    | legacy columns the engine must keep writing.
    |
    | An alias with no entry falls back to GenericSubjectBinding, which moves a
    | lifecycle_state if there is one and otherwise leaves the subject alone.
    |
    */

    'subjects' => [
        'risk_assessment' => RiskAssessmentBinding::class,
        'treatment_plan' => TreatmentPlanBinding::class,
        'control_test' => ControlTestBinding::class,
        'loss_event' => LossEventBinding::class,
        'issue' => IssueClosureBinding::class,
        'risk_appetite' => RiskAppetiteBinding::class,
        'risk' => RiskAcceptanceBinding::class,
        'measure_threshold' => MeasureThresholdBinding::class,
        'icaap_assessment' => IcaapAssessmentBinding::class,
        'graph_object' => GenericSubjectBinding::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation fallback
    |--------------------------------------------------------------------------
    |
    | Where a task goes when neither its node nor its definition says. An empty
    | list means an escalation with nowhere to go, which the engine logs and
    | refuses to act on rather than silently dropping the task off everybody's
    | list.
    |
    */

    'default_escalation_roles' => ['chief-risk-officer'],

    /*
    |--------------------------------------------------------------------------
    | Risk acceptance term
    |--------------------------------------------------------------------------
    |
    | How long an approved risk acceptance stands before the risk comes back for
    | a fresh decision. An acceptance with no expiry is indistinguishable from a
    | risk nobody is looking at.
    |
    */

    'acceptance_months' => (int) env('WORKFLOW_ACCEPTANCE_MONTHS', 12),

    /*
    |--------------------------------------------------------------------------
    | SLA sweeper
    |--------------------------------------------------------------------------
    |
    | grace_minutes stops a task being escalated the instant its due time passes
    | while somebody is mid-decision on it.
    |
    */

    'sla' => [
        'grace_minutes' => (int) env('WORKFLOW_SLA_GRACE_MINUTES', 15),
    ],

];
