<?php

namespace App\Services\Tprm\Incidents;

use App\Enums\Tprm\Regulator;
use App\Models\Organization;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\Incident;
use App\Models\Tprm\NotificationDraft;
use App\Models\Tprm\TprmSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Assembling, approving and recording a regulatory notification — TRD §12.7,
 * AC-07.
 *
 * THE DRAFT IS DETERMINISTIC AND USES NO LANGUAGE MODEL. TRD §12.7 lists this
 * under the AI services, and it could be one — but a notification to a
 * supervisor, written under a twenty-four-hour clock, in the bank's name, is
 * the last place in this product where a fluent invention is acceptable. Every
 * sentence below is assembled from a recorded fact or is a gap the officer is
 * told to fill. That also means it works with `tprm.ai.enabled` false, which
 * AC-16 requires of every workflow.
 *
 * `gaps` IS THE HONEST HALF. A draft built three hours after an incident is
 * missing most of what a regulator asks for; a document that reads fluently
 * while omitting the number of data subjects invites somebody to send it as it
 * stands.
 */
class NotificationDraftService
{
    public function __construct(private readonly ObligationClockService $clocks) {}

    /**
     * Build (or rebuild) the draft for one regulator.
     *
     * REBUILDING A SUBMITTED DRAFT IS REFUSED. What went to a regulator is a
     * record of what was said, and regenerating it from later facts would
     * rewrite history — the follow-up is a new document, not an edit.
     *
     * @throws RuntimeException
     */
    public function build(Incident $incident, Regulator $regulator, ?int $userId = null): NotificationDraft
    {
        $existing = NotificationDraft::query()
            ->where('incident_id', $incident->getKey())
            ->where('regulator', $regulator->value)
            ->first();

        if ($existing?->isSubmitted()) {
            throw new RuntimeException(
                'That notification has already been recorded as submitted. Raise a follow-up rather than '
                .'rewriting what was sent.'
            );
        }

        $facts = $this->facts($incident, $regulator);
        $gaps = $this->gaps($incident, $regulator);

        $attributes = [
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->getKey(),
            'regulator' => $regulator->value,
            'title' => sprintf('%s notification — %s', $regulator->shortLabel(), $incident->reference),
            'body' => $this->body($incident, $regulator, $facts, $gaps),
            'facts' => $facts,
            'gaps' => $gaps,
            'created_by' => $userId,
        ];

        if ($existing === null) {
            return NotificationDraft::create($attributes);
        }

        /*
         * Rebuilding an APPROVED draft clears the approval. The officer
         * approved words that no longer exist, and carrying their name onto
         * new text would be putting a signature on a document they never read.
         */
        $existing->fill($attributes);
        $existing->forceFill([
            'status' => NotificationDraft::STATUS_DRAFT,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        return $existing->refresh();
    }

    /**
     * Build whichever drafts the clocks say are owed.
     *
     * @return list<NotificationDraft>
     */
    public function buildDue(Incident $incident, ?int $userId = null): array
    {
        $drafts = [];

        foreach (Regulator::cases() as $regulator) {
            if (! $incident->{$regulator->reportableColumn()}) {
                continue;
            }

            $drafts[] = $this->build($incident, $regulator, $userId);
        }

        return $drafts;
    }

    /**
     * An officer says the words are right.
     */
    public function approve(NotificationDraft $draft, User $user): NotificationDraft
    {
        if ($draft->isSubmitted()) {
            throw new RuntimeException('That notification has already been submitted.');
        }

        $draft->forceFill([
            'status' => NotificationDraft::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();

        $this->audit($draft, 'notification_draft_approved', ['approved_by' => $user->id]);

        return $draft->refresh();
    }

    /**
     * An officer records that they sent it.
     *
     * THIS IS THE ONLY THING IN THE MODULE THAT STOPS A REGULATORY CLOCK, and
     * it is a human act with a reference from the regulator attached. Nothing
     * automatic may call it.
     *
     * @throws RuntimeException
     */
    public function recordSubmission(
        NotificationDraft $draft,
        User $user,
        string $reference,
        ?Carbon $submittedAt = null,
    ): NotificationDraft {
        if (! $draft->canBeSubmitted()) {
            throw new RuntimeException($draft->isSubmitted()
                ? 'That notification is already recorded as submitted.'
                : 'The draft has to be approved before it can be recorded as submitted — the point of the two '
                    .'steps is that somebody read the words before the bank\'s name went on them.');
        }

        $submittedAt ??= now();

        $draft->forceFill([
            'status' => NotificationDraft::STATUS_SUBMITTED,
            'submitted_at' => $submittedAt,
            'submission_reference' => $reference,
        ])->save();

        // The clock stops on the incident, not merely on the draft, because
        // that is where the register and the escalation sweep read it.
        $draft->incident?->forceFill([
            $draft->regulator->reportedColumn() => $submittedAt,
        ])->save();

        if ($draft->regulator === Regulator::Cbn) {
            $draft->incident?->forceFill(['cbn_reference' => $reference])->save();
        }

        $this->audit($draft, 'notification_recorded_submitted', [
            'submitted_by' => $user->id,
            'reference' => $reference,
            'submitted_at' => $submittedAt->toIso8601String(),
        ]);

        return $draft->refresh();
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function facts(Incident $incident, Regulator $regulator): array
    {
        $incident->loadMissing('thirdParty');
        $start = $this->clocks->clockStart($incident);

        return array_filter([
            'incident_reference' => $incident->reference,
            'third_party' => $incident->thirdParty?->legal_name,
            'nature' => $incident->title,
            'description' => $incident->description,
            'detected_at' => $incident->detected_at?->toDayDateTimeString(),
            'notified_to_us_at' => $incident->reported_to_us_at?->toDayDateTimeString(),
            'became_aware_at' => $start?->toDayDateTimeString(),
            'deadline_at' => $incident->{$regulator->deadlineColumn()}?->toDayDateTimeString(),
            'personal_data_involved' => $incident->personal_data_involved ? 'Yes' : 'No',
            'data_subjects_affected' => $incident->data_subjects_affected,
            'customers_affected' => $incident->customers_affected,
            'estimated_loss' => $incident->estimated_loss_minor === null
                ? null
                : sprintf('%s %s', $incident->currency ?? '', number_format($incident->estimated_loss_minor / 100, 2)),
            'root_cause' => $incident->root_cause,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * What a regulator will ask for that we cannot yet answer.
     *
     * @return list<string>
     */
    private function gaps(Incident $incident, Regulator $regulator): array
    {
        $gaps = [];

        if ($incident->root_cause === null || trim((string) $incident->root_cause) === '') {
            $gaps[] = 'The root cause is not yet established.';
        }

        if ($regulator === Regulator::Ndpc) {
            if ($incident->data_subjects_affected === null) {
                $gaps[] = 'The number of data subjects affected is not recorded — NDPA §40(2)(a) asks for the '
                    .'categories and approximate number.';
            }

            if (! $incident->data_subject_notification_required) {
                $gaps[] = 'Whether the breach is likely to result in high risk to data subjects has not been '
                    .'decided; §40(3) turns on it.';
            }
        }

        if ($regulator === Regulator::Cbn) {
            if ($incident->estimated_loss_minor === null) {
                $gaps[] = 'No estimated financial loss is recorded.';
            }

            if ($incident->customers_affected === null && $incident->customer_impact) {
                $gaps[] = 'Customers are marked as affected but the number is not recorded.';
            }
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  list<string>  $gaps
     */
    private function body(Incident $incident, Regulator $regulator, array $facts, array $gaps): string
    {
        $organization = Organization::query()->find($incident->organization_id);
        $setting = TprmSetting::forOrganization((int) $incident->organization_id);

        $lines = [];

        $lines[] = $regulator->addressee();
        $lines[] = '';
        $lines[] = sprintf('Re: Notification of a third-party incident — %s', $incident->reference);
        $lines[] = '';
        $lines[] = sprintf(
            '%s notifies the %s of an incident affecting a third-party service provider, under %s.',
            $organization->name ?? 'The institution',
            $regulator->label(),
            $regulator->citation(),
        );
        $lines[] = '';

        $lines[] = 'FACTS AS PRESENTLY KNOWN';
        foreach ($facts as $key => $value) {
            $lines[] = sprintf('  %s: %s', ucfirst(str_replace('_', ' ', $key)), $value);
        }

        if ($gaps !== []) {
            $lines[] = '';
            $lines[] = 'NOT YET ESTABLISHED';
            foreach ($gaps as $gap) {
                $lines[] = '  - '.$gap;
            }
            $lines[] = '';
            $lines[] = 'The institution will provide a supplementary report as these are established.';
        }

        $lines[] = '';
        $lines[] = 'Yours faithfully,';
        $lines[] = $setting->regulatory_contact_name ?? '[Name of the officer submitting this notification]';
        $lines[] = $setting->regulatory_contact_title ?? '[Title]';
        $lines[] = $organization->name ?? '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(NotificationDraft $draft, string $event, array $after): void
    {
        AuditLog::create([
            'organization_id' => $draft->organization_id,
            'auditable_type' => NotificationDraft::class,
            'auditable_id' => $draft->getKey(),
            'event' => $event,
            'actor_type' => 'user',
            'actor_id' => $after['approved_by'] ?? $after['submitted_by'] ?? null,
            'before' => null,
            'after' => $after + ['regulator' => $draft->regulator->value, 'incident_id' => $draft->incident_id],
        ]);
    }
}
