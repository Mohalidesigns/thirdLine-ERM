<?php

namespace App\Services;

use App\Models\Control;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\IncidentNotification;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Incident management service: intake, triage, investigation, containment,
 * loss capture, control-failure linkage, timeline narrative and regulatory notification.
 */
class IncidentService
{
    public function __construct(
        private NotificationObligationResolver $resolver,
    ) {}

    // ── Intake and triage ────────────────────────────────────────────────

    public function report(array $data, ?User $reporter = null): Incident
    {
        return DB::transaction(function () use ($data, $reporter) {
            $detectedAt = isset($data['detected_at'])
                ? Carbon::parse($data['detected_at'])->setTimezone(config('app.timezone'))
                : now();

            $incident = Incident::create([
                ...collect($data)->except(['actions', 'control_failure_ids', 'risk_ids'])->all(),
                'incident_ref' => Incident::nextReference('INC', true, 'incident_ref'),
                'detected_at' => $detectedAt,
                'reported_at' => now(),
                'reported_by' => $reporter?->id,
                'status' => 'Reported',
                'control_failure_ids' => $data['control_failure_ids'] ?? [],
                'risk_ids' => $data['risk_ids'] ?? [],
            ]);

            $this->recomputeLoss($incident);

            $this->addTimelineEntry($incident, 'reported', 'Incident reported.', $reporter, $incident->reported_at, true);

            if ($detectedAt) {
                $this->addTimelineEntry($incident, 'detected', 'Incident detected via '
                    .($incident->detection_method ?: 'an unrecorded route').'.', $reporter, $detectedAt, true);
            }

            // Notification duties computed at intake
            $this->resolver->resolve($incident);

            $this->linkControlFailures($incident, $data['control_failure_ids'] ?? []);
            $this->linkRisks($incident, $data['risk_ids'] ?? []);

            $incident->auditAction('reported');

            return $incident->fresh(['actions', 'notifications', 'timeline']);
        });
    }

    public function triage(Incident $incident, array $data, User $user): Incident
    {
        $before = ['severity' => $incident->severity, 'incident_type' => $incident->incident_type];

        $incident->update(collect($data)->only([
            'severity', 'incident_type', 'basel_event_type', 'owner_id',
            'investigator_id', 'business_unit_id', 'entity_id', 'process_id', 'near_miss',
        ])->all());

        // A retyped or re-scoped incident may owe a different regulator
        $this->resolver->resolve($incident->fresh());

        $this->transition($incident, 'Triaged');
        $this->addTimelineEntry($incident, 'triaged', "Triaged as {$incident->severity} {$incident->incident_type}.", $user);
        $incident->auditAction('triaged', $before, ['severity' => $incident->severity]);

        return $incident->fresh();
    }

    public function startInvestigation(Incident $incident, User $user): Incident
    {
        $this->transition($incident, 'Under Investigation', [
            'investigator_id' => $incident->investigator_id ?? $user->id,
        ]);

        $this->addTimelineEntry($incident, 'investigation-started', 'Investigation opened.', $user);

        return $incident->fresh();
    }

    public function contain(Incident $incident, User $user, string $note): Incident
    {
        $this->transition($incident, 'Contained');
        $this->addTimelineEntry($incident, 'contained', $note, $user, null, true);

        return $incident->fresh();
    }

    public function markRemediated(Incident $incident, User $user, array $data): Incident
    {
        $this->transition($incident, 'Remediated', collect($data)->only([
            'root_cause', 'root_cause_rich', 'root_cause_category', 'contributing_factors',
            'lessons_learned', 'lessons_learned_rich',
        ])->all());

        $this->addTimelineEntry($incident, 'remediated', 'Remediation complete.', $user, null, true);

        return $incident->fresh();
    }

    /**
     * The closure gate: both conditions are enforced and reported together.
     */
    public function close(Incident $incident, User $user, ?string $note = null): Incident
    {
        $openActions = $incident->openMandatoryActions()->count();
        $outstanding = $incident->outstandingNotifications()->count();

        $errors = [];

        if ($openActions > 0) {
            $errors['actions'] = "This incident has {$openActions} open mandatory action(s); complete or cancel them before closing.";
        }

        if ($outstanding > 0) {
            $errors['notifications'] = "This incident owes {$outstanding} regulatory notification(s); record or waive them before closing.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->transition($incident, 'Closed', [
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);

        $this->addTimelineEntry($incident, 'closed', $note ?? 'Incident closed.', $user, null, true);
        $incident->auditAction('closed', null, ['note' => $note]);

        return $incident->fresh();
    }

    public function reopen(Incident $incident, User $user, string $reason): Incident
    {
        $this->transition($incident, 'Reopened', ['closed_by' => null, 'closed_at' => null]);
        $this->addTimelineEntry($incident, 'reopened', $reason, $user, null, true);
        $incident->auditAction('reopened', null, ['reason' => $reason]);

        return $incident->fresh();
    }

    public function transition(Incident $incident, string $to, array $extra = []): void
    {
        if ($to === $incident->status) {
            $incident->update($extra);

            return;
        }

        $allowed = Incident::TRANSITIONS[$incident->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "An incident cannot move from {$incident->status} to {$to}.",
            ]);
        }

        $incident->update(['status' => $to, ...$extra]);
    }

    // ── Loss capture ─────────────────────────────────────────────────────

    public function recordLoss(Incident $incident, array $data): Incident
    {
        $currency = $data['currency'] ?? $incident->currency;

        if (! $currency) {
            throw ValidationException::withMessages([
                'currency' => 'A loss needs a currency — amounts are stored as minor units plus an ISO-4217 code.',
            ]);
        }

        $gross = (int) ($data['gross_loss_minor'] ?? $incident->gross_loss_minor);
        $recovery = (int) ($data['recovery_minor'] ?? $incident->recovery_minor);

        if ($recovery > $gross) {
            throw ValidationException::withMessages([
                'recovery_minor' => 'Recovery cannot exceed the gross loss.',
            ]);
        }

        $incident->update([
            'currency' => $currency,
            'gross_loss_minor' => $gross,
            'recovery_minor' => $recovery,
            'net_loss_minor' => Money::of($gross, $currency)
                ->subtract(Money::of($recovery, $currency))->amount,
        ]);

        $incident->auditAction('loss-recorded', null, [
            'gross' => $gross, 'recovery' => $recovery, 'currency' => $currency,
        ]);

        return $incident->fresh();
    }

    private function recomputeLoss(Incident $incident): void
    {
        if (! $incident->currency) {
            return;
        }

        $incident->updateQuietly([
            'net_loss_minor' => (int) $incident->gross_loss_minor - (int) $incident->recovery_minor,
        ]);
    }

    // ── Actions ──────────────────────────────────────────────────────────

    public function addAction(Incident $incident, array $data): IncidentAction
    {
        $action = $incident->actions()->create([
            ...$data,
            'organization_id' => $incident->organization_id,
            'status' => 'Open',
        ]);

        $this->addTimelineEntry(
            $incident,
            'action-raised',
            ucfirst($action->action_type).' action raised: '.$action->title,
            null,
        );

        return $action;
    }

    public function completeAction(IncidentAction $action, User $user, ?int $evidenceId = null): IncidentAction
    {
        $action->update([
            'status' => 'Completed',
            'completed_at' => now(),
            'evidence_id' => $evidenceId ?? $action->evidence_id,
        ]);

        $this->addTimelineEntry($action->incident, 'action-completed', 'Action completed: '.$action->title, $user);

        return $action;
    }

    public function verifyAction(IncidentAction $action, User $verifier): IncidentAction
    {
        if ($action->owner_id !== null && $action->owner_id === $verifier->id) {
            throw ValidationException::withMessages([
                'verification' => 'An incident action cannot be verified by the person who owns it.',
            ]);
        }

        if ($action->status !== 'Completed') {
            throw ValidationException::withMessages([
                'status' => 'Only a completed action can be verified.',
            ]);
        }

        $action->update(['status' => 'Verified', 'verified_by' => $verifier->id, 'verified_at' => now()]);
        $action->auditAction('verified');

        return $action;
    }

    public function cancelAction(IncidentAction $action, User $user, string $reason): IncidentAction
    {
        $action->update(['status' => 'Cancelled']);
        $action->auditAction('cancelled', null, ['reason' => $reason, 'by' => $user->id]);

        return $action;
    }

    // ── Regulatory notification ──────────────────────────────────────────

    public function refreshNotifications(Incident $incident)
    {
        return $this->resolver->resolve($incident);
    }

    public function recordNotification(
        IncidentNotification $notification,
        User $user,
        string $reference,
        ?string $notes = null,
    ): IncidentNotification {
        if (trim($reference) === '') {
            throw ValidationException::withMessages([
                'notification_reference' => 'Record the regulator’s acknowledgement reference.',
            ]);
        }

        $notification->update([
            'status' => 'Notified',
            'notified_at' => now(),
            'notified_by' => $user->id,
            'notification_reference' => $reference,
            'notes' => $notes,
        ]);

        $notification->auditAction('notified', null, ['reference' => $reference]);

        $incident = $notification->incident;

        $this->addTimelineEntry(
            $incident,
            'regulator-notified',
            ($notification->regulator_code ?? 'Regulator').' notified — reference '.$reference,
            $user,
            null,
            true,
        );

        $incident->updateQuietly([
            'notified_at' => $incident->notified_at ?? now(),
            'notification_reference' => $incident->notification_reference ?? $reference,
        ]);

        return $notification->fresh();
    }

    public function waiveNotification(IncidentNotification $notification, User $user, string $reason): IncidentNotification
    {
        $notification->update([
            'status' => 'Waived',
            'is_required' => false,
            'notes' => $reason,
            'notified_by' => $user->id,
        ]);

        $notification->auditAction('waived', null, ['reason' => $reason, 'by' => $user->id]);

        return $notification->fresh();
    }

    // ── Linkages ─────────────────────────────────────────────────────────

    public function linkControlFailures(Incident $incident, array $controlIds): int
    {
        $linked = 0;

        $ids = array_values(array_unique(array_map('intval', $controlIds)));
        if ($ids !== []) {
            $incident->updateQuietly([
                'control_failure_ids' => array_values(array_unique([
                    ...($incident->control_failure_ids ?? []),
                    ...$ids,
                ])),
            ]);
            $linked = count($ids);
        }

        return $linked;
    }

    public function linkRisks(Incident $incident, array $riskIds): int
    {
        $linked = 0;

        $ids = array_values(array_unique(array_map('intval', $riskIds)));
        if ($ids !== []) {
            $incident->updateQuietly([
                'risk_ids' => array_values(array_unique([
                    ...($incident->risk_ids ?? []),
                    ...$ids,
                ])),
            ]);
            $linked = count($ids);
        }

        return $linked;
    }

    // ── Timeline ─────────────────────────────────────────────────────────

    public function addTimelineEntry(
        Incident $incident,
        string $eventType,
        string $description,
        ?User $actor = null,
        ?Carbon $occurredAt = null,
        bool $isMaterial = false,
    ) {
        return $incident->timeline()->create([
            'organization_id' => $incident->organization_id,
            'occurred_at' => $occurredAt ?? now(),
            'actor_id' => $actor?->id ?? auth()->id(),
            'event_type' => $eventType,
            'description' => $description,
            'is_material' => $isMaterial,
        ]);
    }

    // ── Analysis ─────────────────────────────────────────────────────────

    /**
     * Loss by Basel event type, per currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lossByBaselEventType(?int $organizationId = null): array
    {
        return Incident::query()
            ->when($organizationId, fn ($q, $o) => $q->where('organization_id', $o))
            ->whereNotNull('basel_event_type')
            ->whereNotNull('currency')
            ->where('near_miss', false)
            ->selectRaw('basel_event_type, currency, COUNT(*) as incident_count, SUM(net_loss_minor) as net_loss_total')
            ->groupBy('basel_event_type', 'currency')
            ->get()
            ->map(fn ($row) => [
                'basel_event_type' => $row->basel_event_type,
                'incidents' => (int) $row->incident_count,
                'net_loss' => Money::of((int) $row->net_loss_total, $row->currency)->jsonSerialize(),
            ])
            ->all();
    }
}
