<?php

namespace App\Listeners;

use App\Events\LossEventCreated;
use App\Events\LossEventAmountChanged;
use App\Services\RegulatoryThresholdService;
use Illuminate\Support\Facades\DB;

class EvaluateRegulatoryThresholds
{
    public function __construct(
        private RegulatoryThresholdService $regulatoryService
    ) {}

    public function handle(LossEventCreated|LossEventAmountChanged $event): void
    {
        $lossEvent = $event->lossEvent;
        $organizationId = $lossEvent->organization_id;

        // Evaluate against regulatory thresholds
        $thresholdViolations = $this->regulatoryService->evaluateThresholds($lossEvent);

        // Create domain events for any violations
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
                    'violation_threshold' => $violation['threshold'],
                    'current_amount' => $violation['amount'],
                    'regulatory_body' => $violation['body'],
                ]),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
