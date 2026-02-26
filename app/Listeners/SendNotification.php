<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

class SendNotification implements ShouldQueue
{
    public function handle(object $event): void
    {
        $eventType = class_basename($event);
        $orgId = $this->getOrganizationId($event);

        $notificationData = match ($eventType) {
            'ControlUpdated' => [
                'type' => 'control_updated',
                'subject' => "Control Updated: {$event->control->control_code}",
                'body' => "Control '{$event->control->name}' effectiveness has been updated. Linked risk scores have been recalculated.",
                'category' => 'control',
                'priority' => 'medium',
                'action_url' => "/risk/controls/{$event->control->id}",
            ],
            'KriBreachDetected' => [
                'type' => 'kri_breach',
                'subject' => "KRI Breach Alert: {$event->kri->kri_code}",
                'body' => "KRI '{$event->kri->name}' has breached {$event->breachLevel} threshold. Linked risks have been flagged for review.",
                'category' => 'breach_alert',
                'priority' => $event->breachLevel === 'red' ? 'critical' : 'high',
                'action_url' => "/risk/kri/{$event->kri->id}",
            ],
            'LossEventCreated' => [
                'type' => 'loss_event_created',
                'subject' => "New Loss Event: {$event->lossEvent->event_reference}",
                'body' => "A new loss event '{$event->lossEvent->title}' has been recorded with gross loss of " . number_format((int) ($event->lossEvent->gross_loss_amount_kobo ?? $event->lossEvent->gross_loss_amount ?? 0) / 100, 2) . " NGN.",
                'category' => 'loss_event',
                'priority' => 'high',
                'action_url' => "/risk/loss-events/{$event->lossEvent->id}",
            ],
            'TreatmentCompleted' => [
                'type' => 'treatment_completed',
                'subject' => "Treatment Completed: {$event->treatment->treatment_code}",
                'body' => "Treatment plan '{$event->treatment->action_title}' for risk '{$event->risk->title}' has been completed.",
                'category' => 'treatment',
                'priority' => 'medium',
                'action_url' => "/risk/treatments/{$event->treatment->id}",
            ],
            'IssueOverdue' => [
                'type' => 'issue_overdue',
                'subject' => "Issue Overdue: {$event->issue->issue_reference}",
                'body' => "Issue '{$event->issue->title}' is {$event->daysOverdue} days overdue.",
                'category' => 'overdue_reminder',
                'priority' => $event->daysOverdue > 30 ? 'critical' : 'high',
                'action_url' => "/risk/issues/{$event->issue->id}",
            ],
            'AssessmentApproved' => [
                'type' => 'assessment_approved',
                'subject' => "Assessment Approved for Risk: {$event->risk->risk_code}",
                'body' => "Risk assessment for '{$event->risk->title}' has been approved. Risk scores have been updated.",
                'category' => 'assessment',
                'priority' => 'medium',
                'action_url' => "/risk/register/{$event->risk->id}",
            ],
            'NearMissConverted' => [
                'type' => 'near_miss_converted',
                'subject' => "Near Miss Converted to Loss Event: {$event->nearMiss->near_miss_reference}",
                'body' => "Near miss '{$event->nearMiss->title}' has been converted to a loss event.",
                'category' => 'near_miss',
                'priority' => 'high',
                'action_url' => "/risk/loss-events/{$event->lossEvent->id}",
            ],
            default => null,
        };

        if ($notificationData && $orgId) {
            DB::table('notifications_log')->insert([
                'organization_id' => $orgId,
                'user_id' => auth()->id() ?? 1,
                'channel' => 'database',
                'type' => $notificationData['type'],
                'subject' => $notificationData['subject'],
                'body' => $notificationData['body'],
                'status' => 'sent',
                'metadata' => json_encode([
                    'category' => $notificationData['category'],
                    'priority' => $notificationData['priority'],
                    'action_url' => $notificationData['action_url'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function getOrganizationId(object $event): ?int
    {
        return match (true) {
            isset($event->control) => $event->control->organization_id,
            isset($event->kri) => $event->kri->organization_id,
            isset($event->lossEvent) => $event->lossEvent->organization_id,
            isset($event->treatment) => $event->treatment->organization_id,
            isset($event->issue) => $event->issue->organization_id,
            isset($event->risk) => $event->risk->organization_id,
            isset($event->assessment) => $event->assessment->organization_id,
            isset($event->nearMiss) => $event->nearMiss->organization_id,
            default => null,
        };
    }
}
