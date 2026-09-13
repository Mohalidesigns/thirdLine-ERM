<?php

namespace App\Services\Bcms\Strategy;

use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Process;
use App\Models\Bcms\Strategy;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;

/**
 * The gap analysis — where the bank cannot recover as fast as it has decided it
 * must.
 *
 * THIS IS THE SCREEN THAT GETS BUDGET APPROVED. Every other view in the module
 * describes what the organisation has; this one says "these six Tier-1
 * processes cannot meet the recovery times you signed, and here is by how many
 * hours". A continuity function that can put that on one page has an investment
 * case; one that cannot has a filing cabinet.
 *
 * THE GAP IS RECOMPUTED HERE, NOT READ FROM THE COLUMN. `Strategy.gap_vs_
 * required_hours` is deliberately a SNAPSHOT against the assessment it was
 * judged on, so last year's approved paper still says what it said. This view
 * asks a different question — "is there a gap TODAY" — and answering it from a
 * stale snapshot would report a gap that a new BIA has already closed, or hide
 * one it has opened. Both numbers are shown, and the difference between them is
 * itself worth seeing: it is the list of strategies that need reassessing.
 *
 * A PROCESS WITH NO STRATEGY AT ALL IS THE LARGEST GAP THERE IS, and it is easy
 * to leave out of a report built by joining strategies to processes. It is
 * included, with a null achievable RTO and an explicit reason, because a table
 * that silently omitted the unprotected processes would be the most dangerous
 * page in the pack.
 */
class GapAnalysisService
{
    public function __construct(private readonly RcsaScope $scope) {}

    /**
     * @param  int|null  $maxTier  Tier 1 only by default — the phase's criterion 4.
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   summary: array{processes: int, with_gap: int, unprotected: int, total_shortfall_hours: ?float, stale_assessments: int}
     * }
     */
    public function analyse(?User $user = null, ?int $maxTier = 1): array
    {
        $processes = Process::query()
            ->where('status', 'active')
            ->when($maxTier !== null, fn ($q) => $q->where('criticality_tier', '<=', $maxTier))
            ->with('businessUnit:id,name')
            ->orderByRaw('COALESCE(criticality_tier, 99)')
            ->orderBy('code')
            ->get();

        if ($user !== null) {
            $units = $this->scope->unitIdsFor($user);

            if ($units !== null) {
                $processes = $processes->filter(
                    fn (Process $p) => $p->business_unit_id === null
                        || in_array((int) $p->business_unit_id, $units, true)
                )->values();
            }
        }

        if ($processes->isEmpty()) {
            return ['rows' => [], 'summary' => [
                'processes' => 0, 'with_gap' => 0, 'unprotected' => 0,
                'total_shortfall_hours' => null, 'stale_assessments' => 0,
            ]];
        }

        $processIds = $processes->map(fn (Process $p) => (int) $p->getKey())->all();

        $required = $this->requiredRtos($processIds);
        $selected = $this->selectedStrategies($processIds);

        $rows = [];

        foreach ($processes as $process) {
            $id = (int) $process->getKey();
            $assessment = $required[$id] ?? null;
            $strategy = $selected[$id] ?? null;

            $requiredHours = $assessment?->rto_hours === null ? null : (float) $assessment->rto_hours;
            $achievableHours = $strategy?->rto_achievable_hours === null
                ? null : (float) $strategy->rto_achievable_hours;

            $liveGap = ($requiredHours === null || $achievableHours === null)
                ? null
                : round($achievableHours - $requiredHours, 2);

            $storedGap = $strategy?->gap_vs_required_hours === null
                ? null : (float) $strategy->gap_vs_required_hours;

            $rows[] = [
                'process_id' => $id,
                // The route key. The register links straight into a process's
                // option comparison, and processes are addressed by uuid.
                'process_uuid' => $process->uuid,
                'code' => $process->code,
                'name' => $process->name,
                'tier' => $process->criticality_tier,
                'is_critical_service' => (bool) $process->is_critical_service,
                'business_unit' => $process->businessUnit?->name,
                'rto_required_hours' => $requiredHours,
                'rto_achievable_hours' => $achievableHours,
                'shortfall_hours' => ($liveGap !== null && $liveGap > 0) ? $liveGap : null,
                'gap_at_assessment_hours' => $storedGap,
                // The strategy was judged against a BIA that has since been
                // superseded. Not an error, and not a gap — it is the list of
                // strategies somebody should look at again.
                'assessment_is_stale' => $strategy !== null
                    && $assessment !== null
                    && $strategy->assessed_against_assessment_id !== null
                    && (int) $strategy->assessed_against_assessment_id !== (int) $assessment->getKey(),
                'strategy_id' => $strategy?->getKey(),
                'strategy_uuid' => $strategy?->uuid,
                'strategy_type' => $strategy?->strategy_type->value,
                'strategy_label' => $strategy?->strategy_type->label(),
                'strategy_title' => $strategy?->title,
                'cost_estimate_minor' => $strategy?->cost_estimate_minor === null
                    ? null : (int) $strategy->cost_estimate_minor,
                'currency' => $strategy?->currency,
                'approval_status' => $strategy?->approval_status,
                'reason' => $this->reason($assessment, $strategy, $requiredHours, $achievableHours),
            ];
        }

        // Ranked by exposure: the biggest shortfall first, then the processes
        // with no strategy at all, then everything that is fine. A gap table
        // ordered by process code is one nobody works down.
        usort($rows, function (array $a, array $b) {
            $rank = fn (array $r) => match (true) {
                $r['shortfall_hours'] !== null => [0, -$r['shortfall_hours']],
                $r['strategy_id'] === null => [1, 0],
                default => [2, 0],
            };

            return [$rank($a), $a['tier'] ?? 99, $a['code']] <=> [$rank($b), $b['tier'] ?? 99, $b['code']];
        });

        $shortfalls = array_values(array_filter(array_column($rows, 'shortfall_hours'), fn ($v) => $v !== null));

        return [
            'rows' => $rows,
            'summary' => [
                'processes' => count($rows),
                'with_gap' => count($shortfalls),
                'unprotected' => count(array_filter($rows, fn (array $r) => $r['strategy_id'] === null)),
                // Null when nothing has a gap, rather than 0.0 — "no shortfall"
                // and "we could not compute a shortfall" read the same as a
                // zero on a board slide.
                'total_shortfall_hours' => $shortfalls === [] ? null : round(array_sum($shortfalls), 2),
                'stale_assessments' => count(array_filter($rows, fn (array $r) => $r['assessment_is_stale'])),
            ],
        ];
    }

    /**
     * @param  list<int>  $processIds
     * @return array<int, BiaAssessment>
     */
    private function requiredRtos(array $processIds): array
    {
        $out = [];

        foreach (
            BiaAssessment::query()
                ->whereIn('process_id', $processIds)
                ->where('status', 'approved')
                ->orderBy('approved_at')
                ->orderBy('id')
                ->get() as $assessment
        ) {
            $out[(int) $assessment->process_id] = $assessment;
        }

        return $out;
    }

    /**
     * @param  list<int>  $processIds
     * @return array<int, Strategy>
     */
    private function selectedStrategies(array $processIds): array
    {
        $out = [];

        foreach (
            Strategy::query()
                ->whereIn('process_id', $processIds)
                ->where('is_selected', true)
                ->orderBy('id')
                ->get() as $strategy
        ) {
            $out[(int) $strategy->process_id] = $strategy;
        }

        return $out;
    }

    /**
     * Why this row is not a clean pass, in a sentence a reader can act on.
     *
     * An empty cell in a gap table is read as "fine" by everybody who is not
     * the person who built the table.
     */
    private function reason(?BiaAssessment $assessment, ?Strategy $strategy, ?float $required, ?float $achievable): ?string
    {
        if ($assessment === null) {
            return 'No approved business impact assessment, so there is no agreed recovery time to measure '
                .'a strategy against.';
        }

        if ($required === null) {
            return 'The approved assessment does not state an RTO.';
        }

        if ($strategy === null) {
            return 'No continuity strategy has been selected for this process.';
        }

        if ($achievable === null) {
            return 'The selected strategy does not say what recovery time it can actually achieve.';
        }

        if ($achievable > $required) {
            return null;
        }

        return null;
    }
}
