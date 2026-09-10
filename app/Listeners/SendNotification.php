<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Spatie\Permission\Models\Role;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-13 — turn a domain event into notifications for the people it concerns.
 *
 * This listener used to write `user_id => auth()->id()`, which is wrong twice
 * over and was the reason no domain-event notification ever arrived in a real
 * deployment:
 *
 *   1. The listener is ShouldQueue. On a worker there is no session and no
 *      guard user, so auth()->id() is null. The previous code detected that,
 *      logged a warning, and returned — leaving a SUCCESSFUL job and an empty
 *      queue, so nothing surfaced in Horizon or failed_jobs either. Every one
 *      of the eight events was silently dropped in production.
 *   2. Even where a user existed, `auth()->id()` is the ACTOR, not the
 *      recipient. The person who submits an RCSA worksheet does not need to be
 *      told they submitted it; their reviewer does. So on the one connection
 *      where it did write a row — the test suite, which runs sync — it wrote
 *      the notification to the wrong person and every assertion about it would
 *      have been asserting the wrong thing.
 *
 * Both are the same mistake: reading the recipient from ambient request state
 * instead of from the event. Events carry the models. The models carry owners,
 * reviewers and reporters. `recipientsFor()` derives the audience from the
 * payload, so it is identical whether the listener runs inline in a request,
 * on a worker, or from a scheduled command with no user at all.
 *
 * Delivery goes through NotificationService rather than a hand-rolled insert.
 * The old insert put category/priority/action_url inside the `metadata` JSON
 * while the columns of those names stayed NULL — and NotificationController
 * reads the COLUMN, so every notification this listener produced was
 * unclickable. sendMany() also batches: a red KRI breach at a bank with two
 * hundred risk managers is a fan-out job, not two hundred inline inserts.
 */
class SendNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /** Roles that carry organization-wide oversight of risk. */
    private const OVERSIGHT_ROLES = ['risk-manager', 'chief-risk-officer'];

    public $tries = 3;

    public $backoff = [10, 60, 300];

    public function handle(object $event): void
    {
        $eventType = class_basename($event);
        $orgId = $this->getOrganizationId($event);

        $notification = $this->notificationFor($eventType, $event);

        if ($notification === null) {
            return;
        }

        if ($orgId === null) {
            // Previously this case was dropped with no log at all — quieter
            // than the missing-actor case it sat next to, and therefore
            // harder to find.
            logger()->warning('Notification not sent: event carries no organization', [
                'event' => $eventType,
            ]);

            return;
        }

        // The worker has no tenant. Set one for the duration: recipient
        // lookups below query users and role holders, and without a tenant
        // OrganizationScope is inert — a role query would return risk
        // managers from every bank on the instance.
        TenantContext::actingAs($orgId, function () use ($event, $eventType, $orgId, $notification) {
            $recipients = $this->recipientsFor($eventType, $event);

            if ($recipients === []) {
                // A real condition, not an error: a KRI with no owner and no
                // linked risk genuinely has nobody to tell. Log it so the gap
                // is visible as a data problem rather than a lost notification.
                logger()->info('Notification has no recipients', [
                    'event' => $eventType,
                    'organization_id' => $orgId,
                ]);

                return;
            }

            NotificationService::sendMany(
                $orgId,
                $recipients,
                $notification['type'],
                $notification['subject'],
                $notification['body'],
                $notification['metadata'] ?? [],
                $notification['action_url'],
                $notification['priority'],
                $notification['category'],
            );
        });
    }

    /**
     * Who this event concerns.
     *
     * Two rules run through all of these. First, the owner of the thing that
     * changed is always in the list — that is the person accountable for it.
     * Second, oversight roles are added only where the event is one a CRO is
     * expected to see unprompted: a red KRI breach, a loss event, a near miss
     * that became one. Adding them everywhere would make the bell useless
     * inside a week, which is the failure mode that gets notifications turned
     * off entirely.
     *
     * @return list<int>
     */
    private function recipientsFor(string $eventType, object $event): array
    {
        $recipients = match ($eventType) {
            'ControlUpdated' => [
                $event->control->owner_id,
                ...$this->riskOwnersOf($event->control),
            ],

            // The KRI owner reads every breach. A red one is a board-level
            // number, so oversight is added; amber stays with the owner and
            // whoever owns the risks it monitors.
            'KriBreachDetected' => [
                $event->kri->owner_id,
                ...$this->riskOwnersOf($event->kri),
                ...($event->breachLevel === 'red' ? $this->oversight() : []),
            ],

            'LossEventCreated' => [
                $event->lossEvent->responsible_officer_id,
                $event->lossEvent->reported_by,
                ...$this->oversight(),
            ],

            // The risk owner is the point of this one: their number is about
            // to move. The treatment owner is told their plan landed.
            'TreatmentCompleted' => [
                $event->risk->risk_owner_id,
                $event->treatment->owner_id,
            ],

            'IssueOverdue' => [
                $event->issue->responsible_owner_id,
                ...($event->daysOverdue > 30 ? $this->oversight() : []),
            ],

            'AssessmentApproved' => [
                $event->risk->risk_owner_id,
                $event->assessment->reviewer_id,
            ],

            'NearMissConverted' => [
                $event->nearMiss->reported_by,
                ...$this->oversight(),
            ],

            // Deliberately NOT the respondent. They pressed Submit; they know.
            // This notification exists to put the worksheet in front of the
            // person who has to review it, which is the step the old code
            // skipped entirely by notifying the submitter instead.
            'RcsaWorksheetSubmitted' => [
                $event->assignment->reviewer_id
                    ?? $event->campaign->reviewer_id
                    ?? $event->campaign->created_by,
            ],

            default => [],
        };

        return array_values(array_unique(array_filter(
            array_map(fn ($id) => $id === null ? null : (int) $id, $recipients)
        )));
    }

    /**
     * Owners of the risks a control or KRI is mapped to.
     *
     * Wrapped: these traverse a pivot, and a notification is not worth failing
     * a job over if the relation is missing on some model that reaches here.
     *
     * @return list<int>
     */
    private function riskOwnersOf(object $model): array
    {
        try {
            if (! method_exists($model, 'risks')) {
                return [];
            }

            return $model->risks()
                ->pluck('risks.risk_owner_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            logger()->warning('Could not resolve linked risk owners for a notification', [
                'model' => $model::class,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Holders of an organization-wide oversight role.
     *
     * Runs inside TenantContext::actingAs(), so OrganizationScope confines it
     * to this tenant. The explicit organization_id below is belt and braces —
     * users is a tenant table, and getting this wrong would mail one bank's
     * loss events to another's CRO.
     *
     * @return list<int>
     */
    private function oversight(): array
    {
        try {
            // Spatie's role() scope THROWS RoleDoesNotExist for any name it
            // cannot find, and it throws on the first missing name even when
            // the others exist. A tenant that has not seeded 'risk-manager'
            // would therefore lose its CRO notifications too — and, because a
            // notification is not worth failing a job over, the catch below
            // would turn that into silence. Resolve the names that exist and
            // ask only for those.
            $known = Role::query()
                ->whereIn('name', self::OVERSIGHT_ROLES)
                ->pluck('name')
                ->all();

            if ($known === []) {
                logger()->warning('No oversight role exists in this organization', [
                    'looked_for' => self::OVERSIGHT_ROLES,
                    'organization_id' => TenantContext::organizationIdOrNull(),
                ]);

                return [];
            }

            return User::role($known)
                ->where('organization_id', TenantContext::organizationIdOrNull())
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable $e) {
            logger()->warning('Could not resolve oversight recipients', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** @return array<string, mixed>|null */
    private function notificationFor(string $eventType, object $event): ?array
    {
        return match ($eventType) {
            'ControlUpdated' => [
                'type' => 'control_updated',
                'subject' => "Control Updated: {$event->control->control_code}",
                'body' => "Control '{$event->control->name}' effectiveness has been updated. Linked risk scores have been recalculated.",
                'category' => 'control',
                'priority' => 'medium',
                'action_url' => "/risk/controls/{$event->control->id}",
                'metadata' => ['entity_type' => 'control', 'entity_id' => $event->control->id],
            ],
            'KriBreachDetected' => [
                'type' => 'kri_breach',
                'subject' => "KRI Breach Alert: {$event->kri->kri_code}",
                'body' => "KRI '{$event->kri->name}' has breached {$event->breachLevel} threshold. Linked risks have been flagged for review.",
                'category' => 'breach_alert',
                'priority' => $event->breachLevel === 'red' ? 'critical' : 'high',
                'action_url' => "/risk/kri/{$event->kri->id}",
                'metadata' => ['entity_type' => 'kri', 'entity_id' => $event->kri->id, 'breach_level' => $event->breachLevel],
            ],
            'LossEventCreated' => [
                'type' => 'loss_event_created',
                'subject' => "New Loss Event: {$event->lossEvent->event_reference}",
                'body' => "A new loss event '{$event->lossEvent->title}' has been recorded with gross loss of ".number_format((int) ($event->lossEvent->gross_loss_amount_kobo ?? $event->lossEvent->gross_loss_amount ?? 0) / 100, 2).' NGN.',
                'category' => 'loss_event',
                'priority' => 'high',
                'action_url' => "/risk/loss-events/{$event->lossEvent->id}",
                'metadata' => ['entity_type' => 'loss_event', 'entity_id' => $event->lossEvent->id],
            ],
            'TreatmentCompleted' => [
                'type' => 'treatment_completed',
                'subject' => "Treatment Completed: {$event->treatment->treatment_code}",
                'body' => "Treatment plan '{$event->treatment->action_title}' for risk '{$event->risk->title}' has been completed.",
                'category' => 'treatment',
                'priority' => 'medium',
                'action_url' => "/risk/treatments/{$event->treatment->id}",
                'metadata' => ['entity_type' => 'treatment', 'entity_id' => $event->treatment->id],
            ],
            'IssueOverdue' => [
                'type' => 'issue_overdue',
                'subject' => "Issue Overdue: {$event->issue->issue_reference}",
                'body' => "Issue '{$event->issue->title}' is {$event->daysOverdue} days overdue.",
                'category' => 'overdue_reminder',
                'priority' => $event->daysOverdue > 30 ? 'critical' : 'high',
                'action_url' => "/risk/issues/{$event->issue->id}",
                'metadata' => ['entity_type' => 'issue', 'entity_id' => $event->issue->id],
            ],
            'AssessmentApproved' => [
                'type' => 'assessment_approved',
                'subject' => "Assessment Approved for Risk: {$event->risk->risk_code}",
                'body' => "Risk assessment for '{$event->risk->title}' has been approved. Risk scores have been updated.",
                'category' => 'assessment',
                'priority' => 'medium',
                'action_url' => "/risk/register/{$event->risk->id}",
                'metadata' => ['entity_type' => 'risk', 'entity_id' => $event->risk->id],
            ],
            'NearMissConverted' => [
                'type' => 'near_miss_converted',
                'subject' => "Near Miss Converted to Loss Event: {$event->nearMiss->near_miss_reference}",
                'body' => "Near miss '{$event->nearMiss->title}' has been converted to a loss event.",
                'category' => 'near_miss',
                'priority' => 'high',
                'action_url' => "/risk/loss-events/{$event->lossEvent->id}",
                'metadata' => ['entity_type' => 'loss_event', 'entity_id' => $event->lossEvent->id],
            ],
            'RcsaWorksheetSubmitted' => [
                'type' => 'rcsa_worksheet_submitted',
                'subject' => "RCSA Worksheet Submitted: {$event->campaign->campaign_code}",
                'body' => "{$event->responseCount} risk assessment line(s) submitted for review against campaign '{$event->campaign->title}'.",
                'category' => 'assessment',
                'priority' => 'medium',
                'action_url' => "/risk/campaigns/{$event->campaign->id}",
                'metadata' => ['entity_type' => 'campaign', 'entity_id' => $event->campaign->id],
            ],
            default => null,
        };
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
            isset($event->campaign) => $event->campaign->organization_id,
            default => null,
        };
    }
}
