<?php

namespace App\Services\Bcms\Exercises;

use App\Enums\Bcms\ExerciseOutcome;
use App\Enums\Bcms\LadderLevel;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\Process;
use Illuminate\Support\Carbon;

/**
 * The ISO 22398 ladder, enforced as advice rather than as a gate.
 *
 * EVERY RULE HERE WARNS AND NONE OF THEM BLOCKS, and that is a decision worth
 * defending. A bank that has never run a tabletop for its payments process and
 * has a full-scale failover booked for next month has a real problem — but the
 * exercise is booked, the regulator is watching, and a system that refused to
 * record it would be a system they work around. What it must do instead is put
 * the sentence in front of the person approving the programme, and keep it on
 * the record afterwards. That is the same argument `BiaValidator` makes about
 * the CBN's 30-minute threshold, and it is the same answer.
 *
 * THE LADDER IS ABOUT A PROCESS, NOT A DEFINITION. "Has this been walked
 * through" is asked of the activity being tested, across every definition that
 * has ever tested it, over years. So the history is assembled per process from
 * completed occurrences, and a definition's warnings are the union of its
 * processes' warnings.
 *
 * A PASS WITH FINDINGS STILL COUNTS AS HAVING RUN THE LEVEL. Only an outright
 * fail or an inconclusive result does not — `ExerciseOutcome::satisfiesCadence()`
 * is where that judgement lives, and it belongs there rather than being
 * re-decided here.
 */
class LadderAdvisor
{
    /** A Tier-1 process that has not been exercised above a walkthrough in this long is worth flagging. */
    public const TIER1_STALE_MONTHS = 18;

    /**
     * Everything the ladder has to say about this definition.
     *
     * @return list<array{rule: string, severity: string, message: string, process_id: ?int}>
     */
    public function adviseDefinition(ExerciseDefinition $definition): array
    {
        $level = $this->levelOf($definition);

        if ($level === null) {
            return [];
        }

        $processes = $this->processesFor($definition);

        if ($processes === []) {
            return [[
                'rule' => 'no_scope',
                'severity' => 'warning',
                'message' => 'This exercise is not bound to any process, so nothing it proves can be credited to '
                    .'one. The ladder coverage matrix will not show it.',
                'process_id' => null,
            ]];
        }

        $warnings = [];

        foreach ($processes as $process) {
            $history = $this->historyFor($process);

            $warnings = array_merge(
                $warnings,
                $this->missingLowerRung($process, $level, $history),
                $this->tierOneOnlyWalkedThrough($process, $level, $history),
                $this->unverifiedActionsBelow($process, $level, $history),
            );
        }

        return $warnings;
    }

    /**
     * Rule 1 — a full-scale exercise for a process that has never had a
     * successful tabletop.
     *
     * Generalised beyond the prompt's wording, because the same reasoning holds
     * at every rung: the level immediately below this one should have been run
     * and passed at least once. A functional test on a process that has never
     * been walked through is the same mistake at a smaller scale.
     *
     * @param  array<string, array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    private function missingLowerRung(Process $process, LadderLevel $level, array $history): array
    {
        $below = $level->previous();

        if ($below === null) {
            return [];
        }

        // Any successful exercise at or above the rung below counts. Having run
        // a functional test is not invalidated by never having done the
        // walkthrough; the concern is jumping the whole ladder.
        foreach ($history as $ranLevel => $entry) {
            $ran = LadderLevel::tryFrom($ranLevel);

            if ($ran !== null && $ran->isAtOrAbove($below) && $entry['successful'] > 0) {
                return [];
            }
        }

        return [[
            'rule' => 'ladder_skipped',
            'severity' => 'warning',
            'message' => $process->code.' has never had a successful '.$below->label().' or higher, and this is a '
                .$level->label().'. An exercise that skips the ladder usually fails for reasons the rung below '
                .'would have found in a meeting room.',
            'process_id' => $process->getKey(),
        ]];
    }

    /**
     * Rule 2 — a Tier-1 critical process that has only ever been walked through.
     *
     * @param  array<string, array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    private function tierOneOnlyWalkedThrough(Process $process, LadderLevel $level, array $history): array
    {
        if ((int) $process->criticality_tier !== 1) {
            return [];
        }

        $highest = null;
        $lastAtDrillOrAbove = null;

        foreach ($history as $ranLevel => $entry) {
            $ran = LadderLevel::tryFrom($ranLevel);

            if ($ran === null || $entry['successful'] === 0) {
                continue;
            }

            if ($highest === null || $ran->rank() > $highest->rank()) {
                $highest = $ran;
            }

            if ($ran->isAtOrAbove(LadderLevel::Drill)) {
                $lastAtDrillOrAbove = max($lastAtDrillOrAbove, $entry['last_at']);
            }
        }

        if ($highest !== null && $highest->isAtOrAbove(LadderLevel::Drill)) {
            if ($lastAtDrillOrAbove !== null
                && Carbon::parse($lastAtDrillOrAbove)->lt(now()->subMonths(self::TIER1_STALE_MONTHS))) {
                return [[
                    'rule' => 'tier1_stale',
                    'severity' => 'warning',
                    'message' => $process->code.' is Tier 1 and has not been exercised above a walkthrough since '
                        .Carbon::parse($lastAtDrillOrAbove)->format('F Y').'. A plan that has only been read is a '
                        .'plan nobody has tried.',
                    'process_id' => $process->getKey(),
                ]];
            }

            return [];
        }

        return [[
            'rule' => 'tier1_never_drilled',
            'severity' => 'warning',
            'message' => $process->code.' is Tier 1 and has never been exercised above a walkthrough. Reading a plan '
                .'aloud is not evidence that it works.',
            'process_id' => $process->getKey(),
        ]];
    }

    /**
     * Rule 3 — climbing the ladder while the rung below still has unverified
     * corrective actions.
     *
     * THE POINT OF AN EXERCISE IS THE ACTIONS IT PRODUCES. Running a bigger one
     * before last time's findings are closed tests the same broken thing at
     * greater expense, and the AAR will say so.
     *
     * @param  array<string, array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    private function unverifiedActionsBelow(Process $process, LadderLevel $level, array $history): array
    {
        $open = 0;

        foreach ($history as $ranLevel => $entry) {
            $ran = LadderLevel::tryFrom($ranLevel);

            if ($ran !== null && $ran->rank() < $level->rank()) {
                $open += $entry['open_actions'];
            }
        }

        if ($open === 0) {
            return [];
        }

        return [[
            'rule' => 'open_actions_below',
            'severity' => 'warning',
            'message' => $open.' corrective actions from lower-level exercises on '.$process->code
                .' are still unverified. A bigger exercise will find the same things again.',
            'process_id' => $process->getKey(),
        ]];
    }

    /**
     * What has actually been run against this process, by ladder level.
     *
     * @return array<string, array{successful: int, total: int, last_at: ?string, open_actions: int}>
     */
    public function historyFor(Process $process): array
    {
        $occurrences = ExerciseOccurrence::query()
            ->where('status', OccurrenceStatus::Completed->value)
            ->whereHas('definition', fn ($q) => $q->whereJsonContains('process_ids', $process->getKey()))
            ->with(['definition.exerciseType:id,ladder_level', 'aar'])
            ->get();

        $history = [];

        foreach ($occurrences as $occurrence) {
            $case = $occurrence->definition?->exerciseType?->ladder_level;

            if ($case === null) {
                continue;
            }

            $level = $case->value;

            $history[$level] ??= ['successful' => 0, 'total' => 0, 'last_at' => null, 'open_actions' => 0];
            $history[$level]['total']++;

            // `outcome` is CAST to the enum on the model. Parsing it as a
            // string throws, and the version that silently returned null would
            // have made every completed exercise look unsuccessful — which is
            // the same class of defect as reading `status` as a string.
            $outcome = $occurrence->outcome;

            if ($outcome instanceof ExerciseOutcome && $outcome->satisfiesCadence()) {
                $history[$level]['successful']++;
            }

            $ran = $occurrence->actual_end ?? $occurrence->scheduled_date;

            if ($ran !== null) {
                $date = $ran instanceof Carbon ? $ran->toDateString() : (string) $ran;
                $history[$level]['last_at'] = max($history[$level]['last_at'], $date);
            }

            $history[$level]['open_actions'] += $this->openActionsFor($occurrence);
        }

        return $history;
    }

    /**
     * The ladder coverage matrix: process × level × when it was last proven.
     *
     * The Programme Dashboard's centrepiece. It answers "which of our critical
     * activities have never been tested properly", which is the question a
     * management review is supposed to ask and usually cannot.
     *
     * @param  iterable<Process>  $processes
     * @return list<array<string, mixed>>
     */
    public function coverageMatrix(iterable $processes): array
    {
        $rows = [];

        foreach ($processes as $process) {
            $history = $this->historyFor($process);
            $levels = [];
            $highest = null;

            foreach (LadderLevel::cases() as $level) {
                $entry = $history[$level->value] ?? null;

                $levels[$level->value] = [
                    'successful' => $entry['successful'] ?? 0,
                    'total' => $entry['total'] ?? 0,
                    'last_at' => $entry['last_at'] ?? null,
                ];

                if (($entry['successful'] ?? 0) > 0 && ($highest === null || $level->rank() > $highest->rank())) {
                    $highest = $level;
                }
            }

            $rows[] = [
                'process_id' => $process->getKey(),
                'process_uuid' => $process->uuid,
                'code' => $process->code,
                'name' => $process->name,
                'tier' => $process->criticality_tier,
                'is_critical_service' => (bool) $process->is_critical_service,
                'levels' => $levels,
                'highest_proven' => $highest?->value,
                'highest_proven_label' => $highest?->label(),
                // Null, not zero. A process nobody has exercised has no
                // coverage rather than a coverage of nothing.
                'never_exercised' => $highest === null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['tier'] ?? 99, $a['code']] <=> [$b['tier'] ?? 99, $b['code']]);

        return $rows;
    }

    /* ------------------------------------------------------------------ */

    private function openActionsFor(ExerciseOccurrence $occurrence): int
    {
        $aar = $occurrence->aar;

        if ($aar === null) {
            return 0;
        }

        return (int) $aar->findings()
            ->join('bcms_corrective_actions', 'bcms_corrective_actions.finding_id', '=', 'bcms_findings.id')
            ->whereNotIn('bcms_corrective_actions.status', ['verified', 'accepted_risk'])
            ->count();
    }

    /**
     * `ExerciseType::ladder_level` is CAST to the enum on the model, so this
     * returns the case rather than parsing a string. Treating it as a string —
     * which the column is — would return null for every type and silently
     * disable every rule in this class.
     */
    private function levelOf(ExerciseDefinition $definition): ?LadderLevel
    {
        return $definition->exerciseType?->ladder_level;
    }

    /** @return list<Process> */
    private function processesFor(ExerciseDefinition $definition): array
    {
        $ids = $definition->process_ids;

        if (! is_array($ids) || $ids === []) {
            return [];
        }

        return Process::query()->whereIn('id', $ids)->orderBy('code')->get()->all();
    }
}
