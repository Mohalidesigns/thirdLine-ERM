<?php

namespace App\Services\Tprm\Incidents;

use App\Events\Tprm\EngagementScoreInvalidated;
use App\Models\LossEvent;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Incident;
use Illuminate\Support\Facades\DB;

/**
 * A third-party incident reaching the ERM loss register — Phase 9, build
 * item 3, and the other half of "TPRM is part of the ERM solution".
 *
 * THE LOSS REGISTER IS THE BANK'S, NOT THIS MODULE'S. A third-party incident
 * that cost money is an operational loss whatever caused it, and a bank whose
 * ORM figures excluded vendor losses would under-report to the CBN's ORMS
 * return. So it is mirrored rather than kept here — the same reasoning that
 * put findings into `issues` in Phase 5.
 *
 * BASEL CATEGORY IS SET AND IT IS THE HONEST ONE. `basel_l1_category` is
 * required, and a third-party incident is Basel's "Business Disruption and
 * System Failures" or "External Fraud" depending on what happened — never
 * "Other", which is the category that makes a capital calculation meaningless.
 * Where the incident type does not map cleanly the mirror is SKIPPED and the
 * reason recorded, rather than a wrong category being written into a
 * regulatory return.
 *
 * IT MIRRORS ONCE. `erm_loss_event_id` on the incident is the idempotency key;
 * a re-run updates the amounts rather than creating a second loss for the same
 * event, because a duplicated loss overstates the bank's operational risk
 * capital.
 */
class IncidentErmBridge
{
    /**
     * Incident type => [Basel level 1, Basel level 2].
     *
     * DELIBERATELY INCOMPLETE. A type that is not here does not get a guess;
     * `mirror()` returns null and says why, and somebody classifies it by
     * hand. Basel categories drive capital, and a wrong one is worse than a
     * missing one.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const BASEL_MAP = [
        'outage' => ['Business Disruption and System Failures', 'Systems'],
        'security' => ['Business Disruption and System Failures', 'Systems'],
        'data_breach' => ['Business Disruption and System Failures', 'Systems'],
        'fraud' => ['External Fraud', 'Systems Security'],
    ];

    /**
     * Post the incident to the loss register.
     *
     * @return array{loss_event: LossEvent|null, reason: string|null}
     */
    public function mirror(Incident $incident): array
    {
        if ($incident->estimated_loss_minor === null || $incident->estimated_loss_minor === 0) {
            /*
             * No loss is not a loss event. An incident with no financial
             * consequence belongs in the incident register and nowhere else;
             * mirroring it would fill the ORM register with zero-value rows
             * and make the loss distribution wrong.
             */
            return ['loss_event' => null, 'reason' => 'No financial loss is recorded against this incident.'];
        }

        $basel = self::BASEL_MAP[(string) $incident->type] ?? null;

        if ($basel === null) {
            return [
                'loss_event' => null,
                'reason' => sprintf(
                    'No Basel category maps to an incident of type "%s". Classify it by hand in the loss '
                    .'register — a wrong category is worse than a missing one, because it reaches the ORMS '
                    .'return.',
                    $incident->type,
                ),
            ];
        }

        return DB::transaction(function () use ($incident, $basel): array {
            $incident->loadMissing('thirdParty');

            $attributes = [
                'organization_id' => $incident->organization_id,
                'title' => sprintf(
                    'Third-party incident: %s (%s)',
                    $incident->title,
                    $incident->thirdParty->legal_name ?? 'unnamed provider',
                ),
                'description' => $incident->description,
                'initial_root_cause' => $incident->root_cause,
                'date_of_loss' => ($incident->detected_at ?? $incident->reported_to_us_at)?->toDateString(),
                'date_discovered' => $incident->reported_to_us_at?->toDateString(),
                'basel_l1_category' => $basel[0],
                'basel_l2_category' => $basel[1],
                /*
                 * Always Operational Risk. A third-party failure is an
                 * operational loss by definition — the vendor's failure is the
                 * bank's process, people or systems failing through a party it
                 * chose. Leaving this to be guessed per incident would produce
                 * vendor losses filed under Credit Risk on a CBN return.
                 */
                'cbn_risk_category' => 'Operational Risk',
                'gross_loss_amount_kobo' => (int) $incident->estimated_loss_minor,
                'responsible_officer_id' => $this->owner($incident),

                /*
                 * `actual`, in the Basel sense of an actual loss as against a
                 * near miss or a potential one. Two seeders in this repository
                 * disagree about this column — one writes `actual`, the other
                 * writes `Operational`, which is what `cbn_risk_category`
                 * holds — and the first is the meaning the column has. A
                 * third-party incident with a recorded loss is money gone.
                 */
                'loss_category' => 'actual',
                'event_severity' => $this->severity((int) $incident->estimated_loss_minor),
                'created_by' => $this->owner($incident),
            ];

            if ($incident->erm_loss_event_id !== null) {
                $existing = LossEvent::query()->find($incident->erm_loss_event_id);

                if ($existing !== null) {
                    // Amounts move as an investigation proceeds; the event
                    // does not become a second event.
                    $existing->fill($attributes)->save();
                    $incident->forceFill(['erm_synced_at' => now()])->save();

                    return ['loss_event' => $existing->refresh(), 'reason' => null];
                }
            }

            $lossEvent = LossEvent::create($attributes + [
                'event_reference' => $this->nextReference((int) $incident->organization_id),
            ]);

            $incident->forceFill([
                'erm_loss_event_id' => $lossEvent->getKey(),
                'erm_synced_at' => now(),
            ])->save();

            return ['loss_event' => $lossEvent, 'reason' => null];
        });
    }

    /**
     * Apply the incident's SU uplift to every engagement it touched.
     *
     * THE SIGNAL IS `confirmed_breach_12m`, which Phase 5 already weights at
     * 10 — the heaviest single signal in the model. That is not this service's
     * decision to make differently: an incident is exactly what that weight
     * was set for, and a second, lighter "incident" signal would let the same
     * event count for less depending on which door it came through.
     */
    public function applyScoreUplift(Incident $incident): int
    {
        $engagements = Engagement::query()
            ->whereIn('id', (array) ($incident->engagement_ids ?? []))
            ->get();

        foreach ($engagements as $engagement) {
            EngagementScoreInvalidated::dispatch($engagement, 'incident_recorded');
        }

        return $engagements->count();
    }

    /**
     * Severity from the amount, on the thresholds the ERM seeders already use.
     *
     * Derived rather than taken from the incident's own `severity`: that field
     * is the VENDOR's assessment of their incident, and the loss register's
     * severity is about what it cost the bank. A provider calling an outage
     * "medium" does not make a two-hundred-million-naira loss medium.
     */
    private function severity(int $kobo): string
    {
        return match (true) {
            $kobo >= 20_000_000_00 => 'HIGH',
            $kobo >= 5_000_000_00 => 'MEDIUM',
            default => 'LOW',
        };
    }

    private function owner(Incident $incident): ?int
    {
        return Engagement::query()
            ->whereIn('id', (array) ($incident->engagement_ids ?? []))
            ->value('relationship_owner_id');
    }

    private function nextReference(int $organizationId): string
    {
        $count = LossEvent::query()->where('organization_id', $organizationId)->count();

        return sprintf('LE-%s-%05d', now()->format('Y'), $count + 1);
    }
}
