<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * WP-13 — turn OFF automatic listener discovery.
     *
     * Laravel registers its own base EventServiceProvider, and that instance
     * scans app/Listeners and binds every listener whose handle() is typed
     * against a concrete event class. This provider ALSO binds them, through
     * the $listen map below. The result was that every synchronous listener in
     * this application was registered twice and ran twice on every dispatch —
     * `php artisan event:list` showed both `App\Listeners\X` and
     * `App\Listeners\X@handle` under seven different events.
     *
     * What that actually did:
     *   - RecalculateResidualRisk and UpdateRiskFromAssessment recomputed and
     *     rewrote the same risk row twice per event;
     *   - RecordAssessmentMeasures wrote each period-stamped measure twice;
     *   - EvaluateRegulatoryThresholds evaluated every loss event twice, which
     *     on a threshold breach is a duplicated regulatory filing;
     *   - TriggerRiskReassessment would have raised two reassessments for one
     *     completed treatment.
     *
     * `SendNotification` escaped only by accident: its handle() takes `object`
     * rather than a concrete event, so discovery could not match it. That is
     * luck, not design — narrowing that signature would have silently doubled
     * every notification in the product.
     *
     * $listen is the explicit registry and it is authoritative. Discovery is
     * disabled here rather than in bootstrap/app.php so the reason sits next
     * to the map it duplicates. Verified before switching it off: every
     * discovered listener was already declared below, so nothing is lost.
     */
    public function register(): void
    {
        parent::register();

        static::disableEventDiscovery();
    }

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
            // Runs after the risk columns are refreshed, so the period-stamped
            // copy and the denormalised current value agree.
            \App\Listeners\RecordAssessmentMeasures::class,
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\NearMissConverted::class => [
            \App\Listeners\SendNotification::class,
        ],
        \App\Events\RcsaWorksheetSubmitted::class => [
            \App\Listeners\SendNotification::class,
        ],

        /*
         * BCMS Track B's seam. Phase 4's generator places dates and emits this;
         * Phase 5 arms the countdown and the readiness checklist off it. The
         * generator does not know Phase 5 exists, which is the point —
         * Orchestration §5 has the exercise engine and the notification path in
         * different tracks on purpose.
         *
         * Declared here rather than discovered: discovery is off (see above),
         * and a listener that ran twice would materialise the ladder twice.
         * That is survivable — the idempotency key would refuse the second —
         * but "survivable because a unique index caught it" is not a design.
         */
        \App\Events\Bcms\ExerciseOccurrenceScheduled::class => [
            \App\Listeners\Bcms\MaterialiseReminderLadder::class,
        ],
    ];
}
