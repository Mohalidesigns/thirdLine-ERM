<?php

namespace App\Listeners;

use App\Events\KriBreachDetected;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

class EscalateRiskOnKriBreach
{
    public function __construct(
        private AuditTrailService $auditTrailService
    ) {}

    public function handle(KriBreachDetected $event): void
    {
        $kri = $event->kri;

        // Flag all linked risks for review
        foreach ($kri->risks as $risk) {
            $original = $risk->getAttributes();
            $oldVelocity = $original['risk_velocity'] ?? null;

            $risk->update([
                'risk_velocity' => 'increasing',
                'review_required' => true,
                'next_review_date' => now()->addDays(7),
            ]);

            $this->auditTrailService->record(
                $risk,
                'kri_breach_escalation',
                'risk_velocity',
                $oldVelocity,
                'increasing',
                "KRI breach detected: {$kri->kri_code} - {$kri->name} is at {$event->breachLevel} status"
            );
        }

        // Create domain event for notifications
        DB::table('domain_events')->insert([
            'organization_id' => $kri->organization_id,
            'event_type' => 'kri_breach_detected',
            'source_module' => 'kri',
            'source_id' => $kri->id,
            'payload' => json_encode([
                'kri_code' => $kri->kri_code,
                'kri_name' => $kri->name,
                'breach_level' => $event->breachLevel,
                'measurement_value' => $event->measurement->value ?? null,
                'linked_risk_ids' => $kri->risks->pluck('id')->toArray(),
            ]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
