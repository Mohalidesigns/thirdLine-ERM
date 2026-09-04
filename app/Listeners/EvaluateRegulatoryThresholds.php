<?php

namespace App\Listeners;

use App\Events\LossEventAmountChanged;
use App\Events\LossEventCreated;
use App\Services\RegulatoryThresholdService;
use Illuminate\Support\Facades\DB;

class EvaluateRegulatoryThresholds
{
    public function __construct(
        private RegulatoryThresholdService $regulatoryService
    ) {}

    /**
     * @return list<array<string, mixed>> the violations found, so the caller
     *                                    that dispatched the event can show
     *                                    them to the reporter without
     *                                    evaluating the whole threshold set a
     *                                    second time (migration Phase 4.3)
     */
    public function handle(LossEventCreated|LossEventAmountChanged $event): array
    {
        $lossEvent = $event->lossEvent;
        $organizationId = $lossEvent->organization_id;

        // Evaluate against regulatory thresholds
        $thresholdViolations = $this->regulatoryService->evaluateThresholds($lossEvent);

        // Create domain events for any violations.
        //
        // The payload keys below are the ones RegulatoryThresholdService
        // actually emits. This listener previously read 'threshold', 'amount'
        // and 'body', none of which the service has ever produced, so it threw
        // an ErrorException for every alert it was handed — which meant a loss
        // event over the NDIC threshold (NGN 500,000) could not be saved at
        // all. It went unnoticed because most of the alerts never fired: the
        // fraud ones were suppressed by the basel_l1_category case mismatch
        // fixed in WP-01 TASK 1.
        foreach ($thresholdViolations as $violation) {
            DB::table('domain_events')->insert([
                'organization_id' => $organizationId,
                'event_type' => 'regulatory_threshold_breached',
                'source_module' => 'loss_event',
                'source_id' => $lossEvent->id,
                'payload' => json_encode([
                    'loss_event_id' => $lossEvent->id,
                    'loss_event_reference' => $lossEvent->event_reference,
                    'violation_type' => $violation['type'],
                    'message' => $violation['message'],
                    // Not every alert carries a statutory clock — NDIC and EFCC
                    // notification have no deadline in this engine.
                    'deadline' => $violation['deadline'] ?? null,
                    'regulatory_reference' => $violation['regulatory_ref'],
                    'gross_loss_amount_kobo' => (int) $lossEvent->gross_loss_amount_kobo,
                    'net_loss_amount_kobo' => (int) $lossEvent->net_loss_amount_kobo,
                ]),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $thresholdViolations;
    }
}
