<?php

namespace App\Services\Bcms\CallTrees;

use App\Enums\Bcms\CascadeMode;
use App\Enums\Bcms\CascadeOutcome;
use App\Models\Bcms\CallTreeTest;
use App\Models\Bcms\CallTreeTestNode;
use Illuminate\Support\Collection;

/**
 * The eight metrics of Blueprint §6.3, computed once and STORED.
 *
 * A TEST RUN IN MARCH MUST REPRINT IN DECEMBER WITH MARCH'S NUMBERS. Recomputed
 * from live nodes, every scorecard would silently improve as the tree was
 * repaired — the branch that broke would quietly heal in the record of the day
 * it broke, and the CAPA that fixed it would have no "before" to point at. The
 * columns exist for this and the Phase 0 migration says so.
 *
 * MUST-REACH NODES ARE A SEPARATE VERDICT FROM THE PERCENTAGE. A cascade that
 * reached 96% of a department and missed the crisis manager did not go well,
 * and a single number cannot say that. `must_reach_missed` is reported beside
 * the rate rather than folded into it.
 *
 * WHAT IS NOT HERE IS AS DELIBERATE AS WHAT IS. There is no aggregate "cascade
 * health score" blending eight metrics into one figure. Every input has a
 * different owner and a different fix, and a composite would let a good
 * completion rate hide a data-quality collapse (development standard §5).
 */
class CascadeScorecard
{
    /**
     * Compute and persist. Returns the same array it wrote.
     *
     * @return array<string, mixed>
     */
    public function store(CallTreeTest $test): array
    {
        $card = $this->compute($test);

        $test->forceFill([
            'completion_rate' => $card['completion_rate'],
            'first_attempt_rate' => $card['first_attempt_rate'],
            'deputy_activation_rate' => $card['deputy_activation_rate'],
            'data_quality_failures' => $card['data_quality_failures'],
            'nodes_total' => $card['nodes_total'],
            'nodes_reached' => $card['nodes_reached'],
            'scorecard' => $card,
        ])->save();

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(CallTreeTest $test): array
    {
        /** @var Collection<int, CallTreeTestNode> $rows */
        $rows = CallTreeTestNode::query()
            ->where('test_id', $test->getKey())
            ->with('node:id,is_must_reach,expected_response_minutes,tier')
            ->get();

        $total = $rows->count();
        $reached = $rows->filter(fn (CallTreeTestNode $r) => $r->outcome?->isReached() === true);
        $firstAttempt = $reached->filter(fn (CallTreeTestNode $r) => $r->outcome?->isFirstAttempt() === true && (int) $r->attempts <= 1);
        $viaDeputy = $rows->filter(fn (CallTreeTestNode $r) => (int) $r->attempts >= 2);
        $dataQuality = $rows->filter(fn (CallTreeTestNode $r) => $r->outcome?->isDataQualityFailure() === true);
        $consentBlocked = $rows->filter(fn (CallTreeTestNode $r) => $r->outcome === CascadeOutcome::ConsentBlocked);
        $wrongAction = $rows->filter(fn (CallTreeTestNode $r) => $r->outcome === CascadeOutcome::WrongAction);
        $blocked = $rows->filter(fn (CallTreeTestNode $r) => $r->outcome === CascadeOutcome::Blocked);

        $mustReach = $rows->filter(fn (CallTreeTestNode $r) => $r->node !== null && (bool) $r->node->is_must_reach);
        $mustReachMissed = $mustReach->filter(fn (CallTreeTestNode $r) => $r->outcome?->isReached() !== true);

        return [
            'nodes_total' => $total,
            'nodes_reached' => $reached->count(),
            'completion_rate' => $this->rate($reached->count(), $total),
            'first_attempt_rate' => $this->rate($firstAttempt->count(), $total),
            'deputy_activation_rate' => $this->rate($viaDeputy->count(), $total),
            'data_quality_failures' => $dataQuality->count(),

            // Consent is NOT a data-quality failure and is reported on its own
            // line. Counting it as bad data puts pressure on somebody to "fix"
            // a withdrawal, which is the pressure the NDPA exists to remove.
            'consent_excluded' => $consentBlocked->count(),
            'consent_excluded_names' => $consentBlocked
                ->map(fn (CallTreeTestNode $r) => $r->contact_name_snapshot)->filter()->values()->all(),

            'response_accuracy' => $this->rate(
                $reached->count() - $wrongAction->count(),
                max(1, $reached->count()),
            ),
            'wrong_action' => $wrongAction->count(),
            'blocked_downstream' => $blocked->count(),

            'must_reach_total' => $mustReach->count(),
            'must_reach_missed' => $mustReachMissed->count(),
            'must_reach_missed_names' => $mustReachMissed
                ->map(fn (CallTreeTestNode $r) => $r->contact_name_snapshot)->filter()->values()->all(),

            'total_cascade_minutes' => $test->total_cascade_minutes,
            'median_response_minutes' => $this->median(
                $reached->map(fn (CallTreeTestNode $r) => $r->response_minutes)->filter()->values()->all()
            ),

            'by_tier' => $this->byTier($rows, $test),
            'by_outcome' => $this->byOutcome($rows),
            'by_confirmation' => $this->byConfirmation($rows, $test),
            'mode' => $test->mode->value,
        ];
    }

    /**
     * Per-tier timing against target — Blueprint §6.3's third row.
     *
     * THE TARGET IS THE SLOWEST NODE'S OWN WINDOW, not an invented one. Each
     * node carries `expected_response_minutes`; a tier is on target if it
     * finished inside the longest window anybody in it was given. Inventing a
     * tier-level SLA nobody agreed to would produce a red cell the department
     * head can correctly ignore.
     *
     * @param  Collection<int, CallTreeTestNode>  $rows
     * @return list<array<string, mixed>>
     */
    private function byTier(Collection $rows, CallTreeTest $test): array
    {
        $out = [];

        foreach ($rows->groupBy(fn (CallTreeTestNode $r) => (int) ($r->tier_snapshot ?? 0))->sortKeys() as $tier => $group) {
            $reached = $group->filter(fn (CallTreeTestNode $r) => $r->outcome?->isReached() === true);

            $last = $group->map(fn (CallTreeTestNode $r) => $r->acknowledged_at)->filter()->max();
            $elapsed = $last !== null && $test->initiated_at !== null
                ? max(0, (int) round($test->initiated_at->diffInMinutes($last, absolute: true)))
                : null;

            // A snapshot row whose node has since been deleted declares no
            // window, and contributes nothing rather than a zero that would
            // win the max() and report the tier as having no target at all.
            $target = (int) $group
                ->map(function (CallTreeTestNode $r): int {
                    $node = $r->node;

                    return $node === null ? 0 : (int) $node->expected_response_minutes;
                })
                ->max();

            $out[] = [
                'tier' => (int) $tier,
                'nodes' => $group->count(),
                'reached' => $reached->count(),
                'completion_rate' => $this->rate($reached->count(), $group->count()),
                'elapsed_minutes' => $elapsed,
                // Null rather than zero where no node in the tier declared one.
                'target_minutes' => $target > 0 ? $target : null,
                'on_target' => $elapsed === null || $target === 0 ? null : $elapsed <= $target,
                'blocked' => $group->filter(fn (CallTreeTestNode $r) => $r->outcome === CascadeOutcome::Blocked)->count(),
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, CallTreeTestNode>  $rows
     * @return array<string, int>
     */
    private function byOutcome(Collection $rows): array
    {
        $out = [];

        foreach (CascadeOutcome::cases() as $case) {
            $count = $rows->filter(fn (CallTreeTestNode $r) => $r->outcome === $case)->count();

            if ($count > 0) {
                $out[$case->value] = $count;
            }
        }

        return $out;
    }

    /**
     * Criterion 4: hybrid has to report its two halves separately.
     *
     * A completion rate that mixed "the system sent 180 messages" with "two
     * managers rang each other" flatters the automated half and hides the
     * manual one — and it is the manual half an examiner asks about.
     *
     * @param  Collection<int, CallTreeTestNode>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byConfirmation(Collection $rows, CallTreeTest $test): array
    {
        $mode = $test->mode;

        $split = $rows->groupBy(fn (CallTreeTestNode $r) => $mode->requiresHumanConfirmation((int) ($r->tier_snapshot ?? 0))
            ? 'human_confirmed'
            : 'system_dispatched');

        $out = [];

        foreach (['human_confirmed', 'system_dispatched'] as $key) {
            $group = $split->get($key);

            if ($group === null || $group->isEmpty()) {
                continue;
            }

            $reached = $group->filter(fn (CallTreeTestNode $r) => $r->outcome?->isReached() === true);

            $out[$key] = [
                'nodes' => $group->count(),
                'reached' => $reached->count(),
                'completion_rate' => $this->rate($reached->count(), $group->count()),
                'tiers' => $group->map(fn (CallTreeTestNode $r) => (int) ($r->tier_snapshot ?? 0))
                    ->unique()->sort()->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * A percentage, or null where there is nothing to divide by.
     *
     * Zero over zero is not 0% and must not print as one. A tier with no nodes
     * has no completion rate, and a dashboard that shows it in red is asking
     * somebody to fix nothing.
     */
    private function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole * 100, 2);
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (int) $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    /** Modes, for a screen that has to offer them. */
    public function modes(): array
    {
        return array_map(fn (CascadeMode $m) => ['value' => $m->value, 'label' => $m->label()], CascadeMode::cases());
    }
}
