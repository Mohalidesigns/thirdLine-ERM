<?php

namespace App\Services\Bcms\Strategy;

use App\Enums\Bcms\IsoClauseRef;
use App\Enums\Bcms\StrategyType;
use App\Models\Bcms\BiaAssessment;
use App\Models\Bcms\Process;
use App\Models\Bcms\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The continuity strategy register — ISO 22331, ISO 22301 clause 8.3.
 *
 * A PROCESS HAS SEVERAL CANDIDATE STRATEGIES AND ONE SELECTED ONE. That is the
 * whole point of the clause: relocating, working remotely and a manual
 * workaround cost different money and buy different recovery times, and the
 * decision is only defensible if the options that were rejected are still on
 * file. A register that held one strategy per process would record the answer
 * and lose the reasoning, which is exactly what an examiner asks for.
 *
 * THE GAP IS COMPUTED ONCE AND STORED. `gap_vs_required_hours` is
 * `rto_achievable - rto_required` at the moment the strategy was assessed, and
 * the assessment it was judged against is recorded beside it. The required RTO
 * moves with each BIA cycle; a gap derived on read would silently rewrite last
 * year's approved strategy paper, and the paper is the thing the investment was
 * approved on. Re-assessing is an explicit act — `reassess()` — because
 * somebody should have to look at the new number.
 */
class StrategyService
{
    /** @param array<string, mixed> $attributes */
    public function propose(Process $process, StrategyType $type, array $attributes = [], ?int $userId = null): Strategy
    {
        if ($type->requiresRationale() && blank($attributes['selection_rationale'] ?? null)) {
            throw new InvalidArgumentException(
                'A "'.$type->label().'" strategy has to say why. Accepting an outage and depending on somebody '
                .'else\'s goodwill are the two decisions a regulator asks about, and "it was in the register" is '
                .'not an answer to "why".'
            );
        }

        $strategy = new Strategy(array_merge([
            'process_id' => $process->getKey(),
            'strategy_type' => $type->value,
            'approval_status' => 'draft',
            'iso_clause_ref' => IsoClauseRef::Iso22331_strategy->value,
            'created_by' => $userId ?? auth()->id(),
        ], $attributes));

        $strategy->save();

        return $this->reassess($strategy->refresh(), $userId);
    }

    /**
     * Recompute the gap against the process's current approved BIA.
     *
     * A STRATEGY WITH NO ACHIEVABLE RTO HAS NO GAP, not a gap of zero. The
     * difference matters: zero means "this strategy meets the requirement" and
     * null means "nobody has said what this strategy can achieve", and a gap
     * table that showed the second as the first would report an unassessed
     * option as a solved problem (development standard §5).
     */
    public function reassess(Strategy $strategy, ?int $userId = null): Strategy
    {
        $assessment = $this->requiredRtoAssessment((int) $strategy->process_id);
        $required = $assessment?->rto_hours;
        $achievable = $strategy->rto_achievable_hours;

        $strategy->update([
            'assessed_against_assessment_id' => $assessment?->getKey(),
            'gap_vs_required_hours' => ($required === null || $achievable === null)
                ? null
                : round((float) $achievable - (float) $required, 2),
            'updated_by' => $userId ?? auth()->id(),
        ]);

        return $strategy->refresh();
    }

    /**
     * Select this strategy as the one the process will rely on.
     *
     * ONE SELECTION PER PROCESS, enforced here rather than by a unique index,
     * because the losing options stay in the register as rows and a partial
     * unique index is not portable across the two databases this product runs
     * on. Deselecting the previous one is part of the same transaction: a
     * process with two selected strategies is a process whose recovery plan
     * nobody can read.
     */
    public function select(Strategy $strategy, ?string $rationale = null, ?int $userId = null): Strategy
    {
        if ($strategy->strategy_type->requiresRationale() && blank($rationale) && blank($strategy->selection_rationale)) {
            throw new InvalidArgumentException(
                'Selecting a "'.$strategy->strategy_type->label().'" strategy requires a rationale.'
            );
        }

        return DB::transaction(function () use ($strategy, $rationale, $userId) {
            Strategy::query()
                ->where('process_id', $strategy->process_id)
                ->whereKeyNot($strategy->getKey())
                ->where('is_selected', true)
                ->update(['is_selected' => false]);

            $strategy->update(array_filter([
                'is_selected' => true,
                'selection_rationale' => $rationale ?? $strategy->selection_rationale,
                'updated_by' => $userId ?? auth()->id(),
            ], fn ($v) => $v !== null));

            return $strategy->refresh();
        });
    }

    /**
     * Approve a strategy.
     *
     * ONLY A SELECTED STRATEGY IS APPROVED. Approving an option nobody chose
     * puts a signature against a plan the organisation is not following, and
     * the register would then show two approved strategies for one process with
     * nothing to say which is real.
     */
    public function approve(Strategy $strategy, User $approver): Strategy
    {
        if (! $strategy->is_selected) {
            throw new InvalidArgumentException(
                'Select this strategy before approving it. Approving an option the organisation did not choose '
                .'puts a signature against a plan it is not following.'
            );
        }

        if ($strategy->approval_status === 'approved') {
            throw new InvalidArgumentException('This strategy is already approved.');
        }

        $strategy->update([
            'approval_status' => 'approved',
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
            'updated_by' => $approver->getKey(),
        ]);

        return $strategy->refresh();
    }

    public function reject(Strategy $strategy, string $reason, ?int $userId = null): Strategy
    {
        $strategy->update([
            'approval_status' => 'rejected',
            'selection_rationale' => $reason,
            'is_selected' => false,
            'updated_by' => $userId ?? auth()->id(),
        ]);

        return $strategy->refresh();
    }

    /**
     * The options for one process, side by side.
     *
     * THE COMPARISON IS THE DELIVERABLE, not the list. "Relocate: ₦42m, 4 hours"
     * beside "Remote working: ₦6m, 12 hours" is the sentence an executive
     * committee decides on; two separate cards on two separate screens is not.
     *
     * @return list<array<string, mixed>>
     */
    public function compare(Process $process): array
    {
        $assessment = $this->requiredRtoAssessment((int) $process->getKey());
        $required = $assessment?->rto_hours === null ? null : (float) $assessment->rto_hours;

        $rows = [];

        foreach (Strategy::query()->where('process_id', $process->getKey())->orderBy('id')->get() as $strategy) {
            $achievable = $strategy->rto_achievable_hours === null ? null : (float) $strategy->rto_achievable_hours;
            $cost = $strategy->cost_estimate_minor === null ? null : (int) $strategy->cost_estimate_minor;

            $rows[] = [
                'id' => $strategy->getKey(),
                'uuid' => $strategy->uuid,
                'strategy_type' => $strategy->strategy_type->value,
                'strategy_label' => $strategy->strategy_type->label(),
                'title' => $strategy->title,
                'description' => $strategy->description,
                'cost_estimate_minor' => $cost,
                'currency' => $strategy->currency,
                'rto_achievable_hours' => $achievable,
                'rto_required_hours' => $required,
                'gap_vs_required_hours' => $strategy->gap_vs_required_hours === null
                    ? null : (float) $strategy->gap_vs_required_hours,
                'meets_requirement' => ($achievable === null || $required === null) ? null : $achievable <= $required,
                'is_selected' => (bool) $strategy->is_selected,
                'approval_status' => $strategy->approval_status,
                'selection_rationale' => $strategy->selection_rationale,
                'resource_requirements' => $strategy->resource_requirements,
            ];
        }

        return $rows;
    }

    /**
     * The latest approved assessment for a process — the required RTO.
     *
     * Approved, not latest. A strategy assessed against a draft is assessed
     * against a number nobody has agreed to.
     */
    public function requiredRtoAssessment(int $processId): ?BiaAssessment
    {
        return BiaAssessment::query()
            ->where('process_id', $processId)
            ->where('status', 'approved')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();
    }
}
