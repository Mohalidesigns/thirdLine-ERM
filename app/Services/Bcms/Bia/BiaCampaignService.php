<?php

namespace App\Services\Bcms\Bia;

use App\Enums\Bcms\BiaAssessmentStatus;
use App\Enums\Bcms\IsoClauseRef;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\BiaCampaign;
use App\Models\Bcms\Process;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BIA campaigns: distribution, progress, chasing and escalation.
 *
 * DISTRIBUTION *IS* THE CREATION OF ASSESSMENT ROWS. There is no separate target
 * list, because a second place to record who is in scope is a second place for
 * the answer to drift — and it drifts the first time somebody is added
 * mid-campaign (ADR 0009). An assessment with `campaign_id` set IS the
 * distribution, and the unique constraint on (campaign, process) makes
 * re-distributing idempotent rather than duplicating.
 *
 * A PROCESS WITH NO OWNER IS REPORTED, NOT SKIPPED. Distributing to forty
 * processes and silently creating thirty-six assessments is how a campaign
 * closes at "100% response" having never asked four owners. The four come back
 * as a named list for the coordinator to fix.
 *
 * ESCALATION IS RECORDED SO IT HAPPENS ONCE. `chased_at`, `chase_count` and
 * `escalated_at` exist because a nightly sweep with no memory either chases
 * every night — which teaches people to filter the mail — or cannot tell whether
 * it has chased at all.
 */
class BiaCampaignService
{
    /** Days after the deadline before the owner's manager is brought in. */
    public const ESCALATION_GRACE_DAYS = 3;

    /** The shortest gap between two chases for the same assessment. */
    public const CHASE_INTERVAL_DAYS = 3;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?int $userId = null): BiaCampaign
    {
        return BiaCampaign::query()->create(array_merge([
            'status' => 'draft',
            'cycle' => 'annual',
            'iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));
    }

    /**
     * Open the campaign and create an assessment for every process in scope.
     *
     * @param  Collection<int, Process>|null  $processes  null means every active process
     * @return array{created: int, existing: int, without_owner: list<array{code: string, name: string}>}
     */
    public function distribute(BiaCampaign $campaign, ?Collection $processes = null, ?int $userId = null): array
    {
        if ($campaign->status === 'closed') {
            throw new InvalidArgumentException('A closed campaign cannot be distributed.');
        }

        $processes ??= Process::query()->where('status', 'active')->get();

        $created = 0;
        $existing = 0;
        $withoutOwner = [];

        DB::transaction(function () use ($campaign, $processes, $userId, &$created, &$existing, &$withoutOwner) {
            foreach ($processes as $process) {
                if ($process->owner_id === null) {
                    // Named, not skipped. A campaign that reports 100% having
                    // never asked four owners is worse than one that reports 90%.
                    $withoutOwner[] = ['code' => $process->code, 'name' => $process->name];
                }

                $assessment = BiaAssessment::query()->firstOrNew([
                    'campaign_id' => $campaign->getKey(),
                    'process_id' => $process->getKey(),
                ]);

                if ($assessment->exists) {
                    $existing++;

                    continue;
                }

                $assessment->fill([
                    'assessor_id' => $process->owner_id,
                    'status' => BiaAssessmentStatus::Draft->value,
                    'iso_clause_ref' => IsoClauseRef::Iso22301_8_2_2->value,
                    'created_by' => $userId ?? auth()->id(),
                ])->save();

                $created++;
            }

            $campaign->update([
                'status' => 'open',
                'opens_at' => $campaign->opens_at ?? now(),
                'updated_by' => $userId ?? auth()->id(),
            ]);
        });

        return ['created' => $created, 'existing' => $existing, 'without_owner' => $withoutOwner];
    }

    /**
     * Where the campaign has got to, by department.
     *
     * @return array<string, mixed>
     */
    public function progress(BiaCampaign $campaign): array
    {
        $assessments = BiaAssessment::query()
            ->where('campaign_id', $campaign->getKey())
            ->with(['process.businessUnit:id,name,head_id', 'assessor:id,name'])
            ->get();

        $total = $assessments->count();

        $counts = [
            'total' => $total,
            'not_started' => 0,
            'in_progress' => 0,
            'submitted' => 0,
            'approved' => 0,
            'returned' => 0,
            'unassigned' => $assessments->whereNull('assessor_id')->count(),
        ];

        $byUnit = [];

        foreach ($assessments as $assessment) {
            $key = match ($assessment->status) {
                BiaAssessmentStatus::Draft => 'not_started',
                BiaAssessmentStatus::InProgress => 'in_progress',
                BiaAssessmentStatus::Submitted => 'submitted',
                BiaAssessmentStatus::Approved => 'approved',
                BiaAssessmentStatus::Returned => 'returned',
            };

            $counts[$key]++;

            $unit = $assessment->process === null || $assessment->process->businessUnit === null
                ? 'Unassigned'
                : $assessment->process->businessUnit->name;
            $byUnit[$unit] ??= ['unit' => $unit, 'total' => 0, 'responded' => 0, 'outstanding' => 0];
            $byUnit[$unit]['total']++;

            if ($assessment->status->isResponded()) {
                $byUnit[$unit]['responded']++;
            } else {
                $byUnit[$unit]['outstanding']++;
            }
        }

        $responded = $counts['submitted'] + $counts['approved'];

        // NULL, not zero, on an undistributed campaign. A response rate over no
        // assessments is undefined, and 0% reads as "nobody replied".
        $rate = $total === 0 ? null : round($responded / $total * 100, 1);

        $byUnit = array_values($byUnit);
        usort($byUnit, fn (array $a, array $b) => $b['outstanding'] <=> $a['outstanding']);

        return [
            'counts' => $counts,
            'response_rate' => $rate,
            'by_unit' => $byUnit,
            'overdue' => $this->overdue($campaign)->map(fn (BiaAssessment $a) => [
                'id' => $a->getKey(),
                'process' => $a->process?->name,
                'code' => $a->process?->code,
                'assessor' => $a->assessor?->name,
                'chase_count' => (int) $a->chase_count,
                'chased_at' => $a->chased_at?->toDateString(),
                'escalated_at' => $a->escalated_at?->toDateString(),
            ])->values()->all(),
        ];
    }

    /**
     * Close the campaign and freeze its response rate.
     *
     * STORED at close, never recomputed. Clause 8.2.2 evidence is what the
     * campaign achieved; a rate recomputed on read changes every time a process
     * is added to the catalogue, and a board pack that moves retrospectively is
     * worse than none.
     */
    public function close(BiaCampaign $campaign, ?int $userId = null): BiaCampaign
    {
        $progress = $this->progress($campaign);

        $campaign->update([
            'status' => 'closed',
            'closes_at' => $campaign->closes_at ?? now(),
            'response_rate' => $progress['response_rate'],
            'updated_by' => $userId ?? auth()->id(),
        ]);

        return $campaign->refresh();
    }

    /* ------------------------------------------------------------------ */
    /*  Chasing and escalation */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, BiaAssessment> */
    public function overdue(BiaCampaign $campaign): Collection
    {
        if ($campaign->closes_at === null) {
            return collect();
        }

        return BiaAssessment::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereIn('status', [
                BiaAssessmentStatus::Draft->value,
                BiaAssessmentStatus::InProgress->value,
                BiaAssessmentStatus::Returned->value,
            ])
            ->where(fn ($q) => $q->whereDate('bcms_bia_assessments.created_at', '<=', now()))
            // `head_id` IS IN THE SELECT because `managerFor()` reads it. A
            // constrained eager load that omits a column the code then reads
            // returns null forever and nothing fails — the escalation simply
            // never happens (development standard §6).
            ->with(['process.businessUnit:id,name,head_id', 'assessor:id,name'])
            ->get()
            ->filter(fn () => $campaign->closes_at->isPast())
            ->values();
    }

    /**
     * Chase the outstanding assessments, and escalate the ones already chased.
     *
     * @return array{chased: int, escalated: int, no_manager: list<string>}
     */
    public function chase(BiaCampaign $campaign, ?int $userId = null): array
    {
        $chased = 0;
        $escalated = 0;
        $noManager = [];

        foreach ($this->overdue($campaign) as $assessment) {
            $lastChase = $assessment->chased_at;

            // Not more often than the interval, so a daily sweep does not teach
            // people to filter these into a folder.
            if ($lastChase !== null && $lastChase->diffInDays(now()) < self::CHASE_INTERVAL_DAYS) {
                continue;
            }

            $graceExpired = $campaign->closes_at->copy()->addDays(self::ESCALATION_GRACE_DAYS)->isPast();

            if ($graceExpired && $assessment->escalated_at === null) {
                $manager = $this->managerFor($assessment);

                if ($manager === null) {
                    // Reported rather than silently dropped: an owner with no
                    // manager on record is a data gap somebody has to close,
                    // not a reason to stop chasing.
                    $noManager[] = $assessment->process === null
                        ? (string) $assessment->getKey()
                        : $assessment->process->code;
                } else {
                    $assessment->forceFill([
                        'escalated_at' => now(),
                        'escalated_to_user_id' => $manager->getKey(),
                    ])->save();

                    $escalated++;
                }
            }

            $assessment->forceFill([
                'chased_at' => now(),
                'chase_count' => (int) $assessment->chase_count + 1,
            ])->save();

            $chased++;
        }

        return ['chased' => $chased, 'escalated' => $escalated, 'no_manager' => $noManager];
    }

    /**
     * The assessor's line manager.
     *
     * Read from the BCMS contact roster first, because that is where the
     * directory sync writes it (Phase 2C), then from the business unit's head.
     * Never invented: no manager means no escalation and a reported gap.
     */
    private function managerFor(BiaAssessment $assessment): ?User
    {
        $assessorId = $assessment->assessor_id;

        if ($assessorId !== null) {
            $managerId = DB::table('bcms_contacts')
                ->where('organization_id', $assessment->organization_id)
                ->where('user_id', $assessorId)
                ->value('manager_user_id');

            if ($managerId !== null) {
                return User::query()->find($managerId);
            }
        }

        $headId = $assessment->process?->businessUnit?->head_id;

        return $headId === null ? null : User::query()->find($headId);
    }
}
