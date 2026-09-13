<?php

namespace App\Services\Tprm\Incidents;

use App\Enums\Tprm\Regulator;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Incident;
use App\Models\Tprm\IncidentEscalation;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Escalating a running clock at 50% and 80% elapsed — AC-07.
 *
 * IT ESCALATES ONCE PER THRESHOLD, ENFORCED BY A UNIQUE INDEX RATHER THAN BY
 * THE SWEEP REMEMBERING. The sweep runs every fifteen minutes; a check that
 * relied on comparing timestamps would re-fire whenever a run was slow, and
 * the second escalation of the same warning teaches people to ignore the
 * third.
 *
 * THE 80% ESCALATION GOES HIGHER UP THAN THE 50% ONE. Halfway through a
 * twenty-four-hour window is a reminder to the relationship owner; four hours
 * from a supervisory deadline is a matter for whoever can actually sign the
 * notification. An escalation that goes to the same person twice is not an
 * escalation.
 */
class ClockEscalationService
{
    public function __construct(private readonly ObligationClockService $clocks) {}

    /**
     * Sweep every incident with a live clock.
     *
     * @return array{checked: int, escalated: int}
     */
    public function sweep(?int $organizationId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $incidents = Incident::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->where(fn ($q) => $q
                ->where(fn ($inner) => $inner->where('ndpc_reportable', true)->whereNull('ndpc_reported_at'))
                ->orWhere(fn ($inner) => $inner->where('cbn_reportable', true)->whereNull('cbn_reported_at')))
            ->with('thirdParty')
            ->get();

        $escalated = 0;

        foreach ($incidents as $incident) {
            foreach (Regulator::cases() as $regulator) {
                $escalated += $this->escalateFor($incident, $regulator, $asOf);
            }
        }

        return ['checked' => $incidents->count(), 'escalated' => $escalated];
    }

    private function escalateFor(Incident $incident, Regulator $regulator, Carbon $asOf): int
    {
        $countdown = $this->clocks->countdown($incident, $regulator, $asOf);

        if ($countdown === null || $countdown['reported_at'] !== null) {
            return 0;
        }

        /** @var list<int> $thresholds */
        $thresholds = config('tprm.clocks.escalate_at_elapsed_pct', [50, 80]);

        $fired = 0;

        foreach ($thresholds as $threshold) {
            if ($countdown['elapsed_pct'] < $threshold) {
                continue;
            }

            if ($this->record($incident, $regulator, (int) $threshold, $asOf)) {
                $fired++;
            }
        }

        return $fired;
    }

    /**
     * Write the escalation, or do nothing if it already fired.
     *
     * The unique index is the guard. Catching its violation rather than
     * checking first is deliberate: two sweep workers running concurrently
     * would both pass a check-then-insert.
     */
    private function record(Incident $incident, Regulator $regulator, int $threshold, Carbon $asOf): bool
    {
        $recipients = $this->recipients($incident, $threshold);

        try {
            DB::transaction(function () use ($incident, $regulator, $threshold, $asOf, $recipients): void {
                IncidentEscalation::create([
                    'organization_id' => $incident->organization_id,
                    'incident_id' => $incident->getKey(),
                    'regulator' => $regulator->value,
                    'threshold_pct' => $threshold,
                    'fired_at' => $asOf,
                    'deadline_at' => $incident->{$regulator->deadlineColumn()},
                    'notified_user_ids' => $recipients,
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        }

        $this->notify($incident, $regulator, $threshold, $recipients);

        return true;
    }

    /**
     * Who hears about it.
     *
     * At 50% the relationship owner; at 80% the executive sponsor as well,
     * because that is the person who can clear a diary to get a notification
     * signed. Where an engagement has no sponsor the owner is told twice
     * rather than nobody being told — a gap in the register must not silence
     * a statutory deadline.
     *
     * @return list<int>
     */
    private function recipients(Incident $incident, int $threshold): array
    {
        $engagements = Engagement::query()
            ->whereIn('id', (array) ($incident->engagement_ids ?? []))
            ->get();

        $ids = $engagements->pluck('relationship_owner_id')->filter();

        if ($threshold >= 80) {
            $ids = $ids->merge($engagements->pluck('executive_sponsor_id')->filter());
        }

        return $ids->unique()->map(static fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @param  list<int>  $recipients
     */
    private function notify(Incident $incident, Regulator $regulator, int $threshold, array $recipients): void
    {
        $deadline = $incident->{$regulator->deadlineColumn()};

        foreach ($recipients as $userId) {
            NotificationService::send(
                organizationId: (int) $incident->organization_id,
                userId: $userId,
                type: 'tprm.incident.clock',
                subject: sprintf(
                    '%s deadline %d%% elapsed — %s',
                    $regulator->shortLabel(),
                    $threshold,
                    $incident->reference,
                ),
                body: sprintf(
                    '%s. The %s notification for %s is due by %s (%s). Nothing is sent automatically — a named '
                    .'officer must approve the draft and record the submission.',
                    $incident->title,
                    $regulator->shortLabel(),
                    $incident->thirdParty->legal_name ?? 'a third party',
                    $deadline?->toDayDateTimeString() ?? 'an unrecorded time',
                    $regulator->citation(),
                ),
                metadata: [
                    'incident_id' => $incident->getKey(),
                    'regulator' => $regulator->value,
                    'threshold_pct' => $threshold,
                ],
                priority: $threshold >= 80 ? 'critical' : 'high',
            );
        }
    }
}
