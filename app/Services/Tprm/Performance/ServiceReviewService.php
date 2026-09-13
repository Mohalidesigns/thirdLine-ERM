<?php

namespace App\Services\Tprm\Performance;

use App\Enums\Tprm\FindingSeverity;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\ServiceReview;
use App\Models\Tprm\ServiceReviewAction;
use App\Models\Tprm\Sla;
use App\Models\Tprm\SlaMeasurement;
use App\Models\Tprm\TierPolicy;
use App\Services\Tprm\Findings\FindingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled service reviews and the pack that goes into them — FR-PRF.
 *
 * "A review that does not happen becomes an overdue obligation" is the whole
 * requirement, and the reason `scheduled_for` is a stored date rather than
 * something derived from a cadence when a screen renders: you cannot be
 * overdue against a calculation.
 *
 * THE PACK IS ASSEMBLED AND SNAPSHOTTED, NOT LINKED. A minute referring to
 * "the SLA pack" is worthless once the measurements have moved on, and a
 * review held in March that somebody opens in November should show what was in
 * front of the room in March.
 *
 * THE PACK LEADS WITH BREACHES AND WITH WHAT WAS NOT MEASURED. A service
 * review that opens on green metrics is a meeting nobody prepares for; the
 * interesting rows are the target that was missed and the SLA with no
 * measurement recorded at all — the second being the one a vendor benefits
 * from nobody noticing.
 */
class ServiceReviewService
{
    public function __construct(private readonly FindingService $findings) {}

    /**
     * Schedule the next review for an engagement.
     *
     * The cadence comes from the tier policy where one is set; a Critical
     * vendor reviewed annually and a Low one reviewed annually is a programme
     * that is not really reviewing anything.
     */
    public function schedule(Engagement $engagement, ?Carbon $on = null, ?int $userId = null): ServiceReview
    {
        $months = $this->cadenceMonths($engagement);

        return ServiceReview::create([
            'organization_id' => $engagement->organization_id,
            'engagement_id' => $engagement->getKey(),
            'cadence' => $months.'_months',
            'scheduled_for' => ($on ?? now()->addMonths($months))->toDateString(),
            'owner_id' => $engagement->relationship_owner_id,
            'created_by' => $userId,
            'agenda' => $this->defaultAgenda(),
        ]);
    }

    /**
     * Record that the review happened, with the pack as it stood.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function hold(ServiceReview $review, array $attributes, ?int $userId = null): ServiceReview
    {
        return DB::transaction(function () use ($review, $attributes, $userId): ServiceReview {
            $review->fill([
                'attendees' => $attributes['attendees'] ?? $review->attendees,
                'agenda' => $attributes['agenda'] ?? $review->agenda,
            ]);

            $review->forceFill([
                'status' => ServiceReview::STATUS_HELD,
                'held_on' => $attributes['held_on'] ?? now()->toDateString(),
                'minutes' => $attributes['minutes'] ?? null,
                'performance_snapshot' => $this->performancePack($review->engagement),
                'completed_by' => $userId,
            ])->save();

            // The next one is scheduled now, from the date this was held. A
            // programme that schedules the next review when somebody remembers
            // is a programme with a gap in it.
            $this->schedule(
                $review->engagement,
                Carbon::parse($review->held_on)->addMonths($this->cadenceMonths($review->engagement)),
                $userId,
            );

            return $review->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addAction(ServiceReview $review, array $attributes): ServiceReviewAction
    {
        return ServiceReviewAction::create([
            'organization_id' => $review->organization_id,
            'service_review_id' => $review->getKey(),
        ] + $attributes);
    }

    /**
     * Mark missed reviews and raise the finding each owes.
     *
     * @return array{missed: int, findings: int}
     */
    public function markMissed(?int $organizationId = null, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $reviews = ServiceReview::query()
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->overdue()
            ->with('engagement')
            ->get();

        $missed = 0;
        $raised = 0;

        foreach ($reviews as $review) {
            if ($review->engagement === null) {
                continue;
            }

            $review->forceFill(['status' => ServiceReview::STATUS_MISSED])->save();
            $missed++;

            $finding = $this->findings->raise(
                $review->engagement,
                'monitoring',
                FindingSeverity::Medium,
                'Service review not held',
                [
                    'source_id' => $review->getKey(),
                    'description' => sprintf(
                        "The service review scheduled for %s was not held.\n\nA review that does not happen is "
                        .'not a gap in a diary: it is the control that would have caught a degrading service '
                        ."before the SLA breached, and its absence is why nobody noticed.\n\nHold it and record "
                        .'the minutes, or record why it was not needed.',
                        $review->scheduled_for->toFormattedDateString(),
                    ),
                    'regulatory_citation' => 'CBN Outsourcing Guidelines §5.2; DORA Art. 28(3)',
                ],
            );

            if ($finding->wasRecentlyCreated) {
                $raised++;
            }
        }

        return ['missed' => $missed, 'findings' => $raised];
    }

    /**
     * The SLA performance pack for a review.
     *
     * @return array<string, mixed>
     */
    public function performancePack(Engagement $engagement, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();
        $since = $asOf->copy()->subMonths($this->cadenceMonths($engagement));

        $slas = Sla::query()->where('engagement_id', $engagement->getKey())->get();

        $rows = [];
        $breached = 0;
        $unmeasured = 0;

        foreach ($slas as $sla) {
            $measurements = SlaMeasurement::query()
                ->where('sla_id', $sla->getKey())
                ->whereDate('period_end', '>=', $since->toDateString())
                ->orderBy('period_start')
                ->get();

            $misses = $measurements->filter(fn (SlaMeasurement $m): bool => ! $this->meetsTarget($sla, $m));

            if ($measurements->isEmpty()) {
                $unmeasured++;
            }

            if ($misses->isNotEmpty()) {
                $breached++;
            }

            $rows[] = [
                'metric' => $sla->metric_name,
                'target' => sprintf('%s %s %s', $sla->target_operator, $sla->target_value, $sla->unit ?? ''),
                'periods_measured' => $measurements->count(),
                'periods_missed' => $misses->count(),
                'latest' => $measurements->last()?->actual_value,
                // The row a reviewer should read first: a metric with no
                // measurement at all is the one a vendor benefits from nobody
                // noticing.
                'unmeasured' => $measurements->isEmpty(),
                'credits_claimed_minor' => (int) $measurements->sum('credit_claimed_minor'),
                'credits_received_minor' => (int) $measurements->sum('credit_received_minor'),
            ];
        }

        return [
            'period_from' => $since->toDateString(),
            'period_to' => $asOf->toDateString(),
            'slas' => $rows,
            'breached_count' => $breached,
            'unmeasured_count' => $unmeasured,
            'open_findings' => Finding::query()
                ->where('engagement_id', $engagement->getKey())
                ->whereIn('status', ['open', 'assigned', 'in_remediation', 'evidence_submitted', 'under_verification'])
                ->count(),
            'note' => $unmeasured > 0
                ? sprintf(
                    '%d service level%s no measurement recorded in this period. An unmeasured target is not a '
                    .'met target.',
                    $unmeasured,
                    $unmeasured === 1 ? ' has' : 's have',
                )
                : null,
        ];
    }

    private function meetsTarget(Sla $sla, SlaMeasurement $measurement): bool
    {
        $actual = (float) $measurement->actual_value;
        $target = (float) $sla->target_value;

        return match ($sla->target_operator) {
            '>=' => $actual >= $target,
            '>' => $actual > $target,
            '<=' => $actual <= $target,
            '<' => $actual < $target,
            '=' => abs($actual - $target) < 0.0001,
            // An operator nobody recognises is not a pass. Treating it as one
            // would silently mark every measurement compliant.
            default => false,
        };
    }

    private function cadenceMonths(Engagement $engagement): int
    {
        $policy = TierPolicy::query()
            ->where('organization_id', $engagement->organization_id)
            ->where('tier', $engagement->effective_tier?->value)
            ->first();

        return (int) ($policy?->assessment_frequency_months ?: 12);
    }

    /** @return list<string> */
    private function defaultAgenda(): array
    {
        return [
            'Service level performance since the last review',
            'Open findings and remediation progress',
            'Incidents and their root causes',
            'Changes to sub-processors, locations or key personnel',
            'Contract and obligation status',
            'Exit plan currency',
        ];
    }
}
