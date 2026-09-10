<?php

namespace App\Services\Tprm\Portal;

use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ScoreRun;
use Illuminate\Support\Collection;

/**
 * What the vendor sees about itself — TRD §7.7. "No Nigerian competitor does
 * this."
 *
 * THE SCORE IS `100 − RR`, THE SAME NUMBER THE CLIENT SEES, INVERTED. Not a
 * separate vendor-friendly metric, not a curve, not a rounding that flatters.
 * The moment the two numbers can disagree, the vendor is being managed rather
 * than informed, and the first time somebody notices — in a contract
 * negotiation, usually — the feature has cost more trust than it built.
 *
 * IT SHOWS THE VENDOR ITS OWN FINDINGS AS THE IMPROVEMENT LIST, ordered by
 * what closing each would actually return. A score with no route to changing
 * it is a grade, and a grade makes a vendor argue with the number instead of
 * fixing the thing.
 *
 * WHAT IT DOES NOT SHOW: the client's inherent-risk workings, the tiering
 * ruleset, the knockouts, or any other client's engagements. The vendor learns
 * where it stands and what to do; it does not learn how the bank thinks, which
 * is the bank's.
 */
class VendorTrustScore
{
    /**
     * @return array{
     *     score: float|null,
     *     band: string|null,
     *     as_of: string|null,
     *     engagements: int,
     *     domains: list<array<string, mixed>>,
     *     improvements: list<array<string, mixed>>,
     *     unavailable: string|null,
     * }
     */
    public function for(PortalUser $user): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Engagement> $engagements */
        $engagements = Engagement::query()
            ->where('third_party_id', $user->third_party_id)
            ->whereNotIn('status', ['draft', 'terminated', 'archived'])
            ->get();

        if ($engagements->isEmpty()) {
            return $this->unavailable('There is no active engagement to score yet.', 0);
        }

        /** @var Collection<int, ScoreRun> $runs */
        $runs = ScoreRun::query()
            ->whereIn('engagement_id', $engagements->modelKeys())
            ->orderByDesc('created_at')
            ->get()
            ->unique('engagement_id');

        if ($runs->isEmpty()) {
            /*
             * No score yet is its own state, not a zero. A vendor shown "0"
             * before their first assessment has been reviewed will read it as
             * a judgement, complain, and be right to.
             */
            return $this->unavailable(
                'Your score appears once your first assessment has been reviewed.',
                $engagements->count(),
            );
        }

        /*
         * THE WORST ENGAGEMENT, NOT THE AVERAGE. A vendor with one excellent
         * relationship and one poor one is a vendor with a poor one, and
         * averaging tells them the opposite of what their client is looking
         * at.
         */
        $worst = $runs->sortByDesc(fn (ScoreRun $run): float => (float) $run->rr)->first();

        $score = round(100 - (float) $worst->rr, 1);

        return [
            'score' => $score,
            'band' => $this->band($score),
            'as_of' => $worst->created_at?->toDateString(),
            'engagements' => $engagements->count(),
            'domains' => $this->domains($worst),
            'improvements' => $this->improvements($user, $engagements),
            'unavailable' => null,
        ];
    }

    /**
     * The breakdown, in the vendor's terms rather than the model's.
     *
     * The residual formula's own components — IR, AC, EC, M, FU, SU — are the
     * client's vocabulary. A vendor reading "your SU is 4" learns nothing;
     * "unresolved monitoring signals are costing you 4 points" is the same
     * fact and can be acted on.
     *
     * @return list<array<string, mixed>>
     */
    private function domains(ScoreRun $run): array
    {
        $rows = [
            [
                'key' => 'assurance',
                'label' => 'Assurance you have evidenced',
                'value' => round((float) $run->m * 100, 1),
                'unit' => '%',
                'direction' => 'higher_is_better',
                'note' => 'How much of your client\'s inherent risk your controls and evidence offset. '
                    .'Independent assurance — a current SOC 2 or ISO 27001 whose scope covers the service — '
                    .'moves this more than anything else.',
            ],
            [
                'key' => 'findings',
                'label' => 'Open findings',
                'value' => round((float) $run->fu, 1),
                'unit' => 'points added',
                'direction' => 'lower_is_better',
                'note' => 'Points added to your client\'s residual risk by findings that are still open. '
                    .'Overdue findings count for more than open ones.',
            ],
            [
                'key' => 'signals',
                'label' => 'Monitoring signals',
                'value' => round((float) $run->su, 1),
                'unit' => 'points added',
                'direction' => 'lower_is_better',
                'note' => 'Expiring evidence, overdue assessments, breached service levels and anything '
                    .'observed externally.',
            ],
        ];

        return $rows;
    }

    /**
     * What to fix, worth-first.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Engagement>  $engagements
     * @return list<array<string, mixed>>
     */
    private function improvements(PortalUser $user, $engagements): array
    {
        $findings = Finding::query()
            ->whereIn('engagement_id', $engagements->modelKeys())
            ->whereIn('status', ['open', 'assigned', 'in_remediation', 'evidence_submitted', 'under_verification'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('target_date')
            ->limit(5)
            ->get();

        return $findings->map(fn (Finding $finding): array => [
            'reference' => $finding->reference,
            'title' => $finding->title,
            'severity' => $finding->severity->value,
            'severity_label' => $finding->severity->label(),
            'target_date' => $finding->target_date?->toDateString(),
            'overdue' => $finding->target_date !== null && $finding->target_date->isPast(),
            'uuid' => $finding->uuid,
        ])->values()->all();
    }

    private function band(float $score): string
    {
        return match (true) {
            $score >= 80 => 'strong',
            $score >= 60 => 'adequate',
            $score >= 40 => 'needs improvement',
            default => 'weak',
        };
    }

    /**
     * @return array{score: null, band: null, as_of: null, engagements: int, domains: list<mixed>, improvements: list<mixed>, unavailable: string}
     */
    private function unavailable(string $reason, int $engagements): array
    {
        return [
            'score' => null,
            'band' => null,
            'as_of' => null,
            'engagements' => $engagements,
            'domains' => [],
            'improvements' => [],
            'unavailable' => $reason,
        ];
    }
}
