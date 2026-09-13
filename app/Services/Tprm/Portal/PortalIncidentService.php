<?php

namespace App\Services\Tprm\Portal;

use App\Models\Tprm\AuditLog;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Incident;
use App\Models\Tprm\PortalUser;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use ThirdLine\Platform\Tenancy\OrganizationScope;

/**
 * A vendor telling us about an incident — FR-PRT-07.
 *
 * SUBMISSION STAMPS AN AUDITABLE RECEIPT AND SETS THE REPORTABILITY FLAGS. It
 * does NOT compute the regulatory deadlines: Phase 9 owns
 * `ObligationClockService`, and a deadline computed here from a half-built
 * rule would be worse than no deadline, because a screen reading "18 hours
 * remaining" gets believed and acted on.
 *
 * THE RECEIPT MATTERS TO BOTH SIDES AND FOR DIFFERENT REASONS. For us,
 * `reported_to_us_at` is the recorded clock-start under NDPA §40(1) and the
 * thing an examiner asks about when our own 72 hours are counted. For the
 * vendor it is the evidence THEY notified their controller in time — a portal
 * that takes the report and gives them nothing to keep makes itself the reason
 * they cannot answer their own regulator.
 *
 * NOTHING IS AUTO-SUBMITTED ANYWHERE. The bank is told immediately, loudly and
 * to a person; the regulator hears from a named officer in Phase 9, never from
 * this code.
 */
class PortalIncidentService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function report(PortalUser $user, array $attributes): Incident
    {
        return DB::transaction(function () use ($user, $attributes): Incident {
            $engagements = Engagement::query()
                ->where('third_party_id', $user->third_party_id)
                ->whereNotIn('status', ['draft', 'terminated', 'archived'])
                ->get();

            $incident = Incident::create([
                'organization_id' => $user->organization_id,
                'third_party_id' => $user->third_party_id,
                'engagement_ids' => $engagements->modelKeys(),
                'reference' => $this->nextReference((int) $user->organization_id),
                'type' => $attributes['type'] ?? 'other',
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'detected_at' => $attributes['detected_at'] ?? null,
                'severity' => $attributes['severity'] ?? null,
                'customer_impact' => (bool) ($attributes['customer_impact'] ?? false),
                'customers_affected' => $attributes['customers_affected'] ?? null,
                'personal_data_involved' => (bool) ($attributes['personal_data_involved'] ?? false),
                'data_subjects_affected' => $attributes['data_subjects_affected'] ?? null,
            ]);

            $incident->forceFill([
                // Write-once, set here and nowhere else. See the model.
                'reported_to_us_at' => now(),
                'reported_by' => Incident::SOURCE_PORTAL,
                /*
                 * FLAGS, NOT DEADLINES. Personal data involved makes an NDPC
                 * notification a live question; whether it is actually
                 * reportable, and by when, is Phase 9's to decide with the
                 * materiality test and the shareholders'-funds figure.
                 */
                'ndpc_reportable' => (bool) ($attributes['personal_data_involved'] ?? false),
                'cbn_reportable' => (bool) ($attributes['customer_impact'] ?? false),
            ])->save();

            $this->auditReceipt($incident, $user);
            $this->alert($incident, $engagements);

            return $incident->refresh();
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Incident>
     */
    public function reportedBy(PortalUser $user)
    {
        return Incident::query()
            ->where('third_party_id', $user->third_party_id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The receipt lands in the append-only, hash-chained log as well as on the
     * row, because the row is editable by our own staff and the log is not.
     */
    private function auditReceipt(Incident $incident, PortalUser $user): void
    {
        AuditLog::create([
            'organization_id' => $incident->organization_id,
            'auditable_type' => Incident::class,
            'auditable_id' => $incident->getKey(),
            'event' => 'incident_reported_by_vendor',
            'actor_type' => 'portal',
            'actor_id' => null,
            'before' => null,
            'after' => [
                'reference' => $incident->reference,
                'reported_to_us_at' => $incident->reported_to_us_at?->toIso8601String(),
                'reported_by_email' => $user->email,
                'personal_data_involved' => $incident->personal_data_involved,
                'customer_impact' => $incident->customer_impact,
            ],
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500) ?: null,
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Engagement>  $engagements
     */
    private function alert(Incident $incident, $engagements): void
    {
        $owners = $engagements->pluck('relationship_owner_id')->filter()->unique();

        foreach ($owners as $ownerId) {
            NotificationService::send(
                organizationId: (int) $incident->organization_id,
                userId: (int) $ownerId,
                type: 'tprm.portal.incident',
                subject: sprintf('%s reported an incident: %s', $incident->thirdParty?->legal_name, $incident->title),
                body: sprintf(
                    'Received %s.%s%s Assess reportability now — the statutory clock runs from when they told us.',
                    $incident->reported_to_us_at?->toDayDateTimeString() ?? 'just now',
                    $incident->personal_data_involved ? ' Personal data is involved.' : '',
                    $incident->customer_impact ? ' Customers are affected.' : '',
                ),
                metadata: ['incident_id' => $incident->getKey(), 'reference' => $incident->reference],
                priority: 'high',
            );
        }
    }

    private function nextReference(int $organizationId): string
    {
        $count = Incident::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->count();

        return sprintf('TPI-%s-%04d', now()->format('Y'), $count + 1);
    }
}
