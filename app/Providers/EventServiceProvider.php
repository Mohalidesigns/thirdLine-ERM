<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        \App\Events\ControlUpdated::class => [
            \App\Listeners\RecalculateResidualRisk::class,
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\KriBreachDetected::class => [
            \App\Listeners\EscalateRiskOnKriBreach::class,
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\LossEventCreated::class => [
            \App\Listeners\EvaluateRegulatoryThresholds::class,
            \App\Listeners\FlagRiskForReview::class,
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\LossEventAmountChanged::class => [
            \App\Listeners\EvaluateRegulatoryThresholds::class,
        ],
        \App\Events\TreatmentCompleted::class => [
            \App\Listeners\TriggerRiskReassessment::class,
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\IssueOverdue::class => [
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\AssessmentApproved::class => [
            \App\Listeners\UpdateRiskFromAssessment::class,
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\NearMissConverted::class => [
            \App\Listeners\SendNotification::class,
        ],
    ];
}
