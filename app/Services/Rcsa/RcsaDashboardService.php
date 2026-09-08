<?php

namespace App\Services\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaCycle;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\User;
use App\Support\Rcsa\RcsaScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The v1 reporting surface of §10.3 — seven views over one cycle.
 *
 * EVERY FIGURE IS COUNTED IN SQL, and the reason is the same one that made
 * `completion_pct` a stored column in P0: these numbers are shown for every
 * business unit in the bank at once, and a dashboard that instantiates a model
 * per line issues thousands of them on each render. What is loaded here is
 * always an aggregate — a grouped count, a top ten — never a register.
 *
 * IT IS ORDINARY SQL, PORTABLE BY CONSTRUCTION. No `DATE_FORMAT`, no
 * `GROUP_CONCAT`, no window functions: every query here is a `selectRaw` of
 * `count(*)` or `avg(...)` over a `groupBy`, which MySQL and SQLite agree on.
 * A dashboard that only renders on the production driver is a dashboard no test
 * can hold, and the suite runs on SQLite.
 *
 * ABOVE APPETITE IS A COMPARISON, NOT A COLUMN. It is resolved once per cycle
 * into the list of band names above the methodology's ceiling, and every query
 * that needs it uses `whereIn`. Same technique as the review queue and the
 * export filter; the alternative is a PHP filter over every line in the bank.
 */
class RcsaDashboardService
{
    /** How many rows the "top residual risks" panel shows (§10.3). */
    public const TOP_RISKS = 10;

    /**
     * Methodologies already resolved, keyed by cycle id.
     *
     * An instance property for the reason given at length on
     * RcsaAssessmentService::$methodologyCache — a `static` local outlives the
     * request and turns a cache into a stale read nothing invalidates.
     *
     * @var array<int, RcsaMethodology|null>
     */
    private array $methodologyCache = [];

    /**
     * Whose dashboard this is.
     *
     * P6 built the panels unscoped; P7 makes every one of them answer for a
     * particular person. It is a PROPERTY rather than an argument on each
     * method because there are nine of them and a scoping parameter somebody
     * can forget to pass is a scoping parameter somebody will forget to pass —
     * on a screen whose entire job is to summarise the bank.
     */
    private ?User $viewer = null;

    public function for(?User $user): static
    {
        $this->viewer = $user;

        return $this;
    }

    /**
     * The cycle a dashboard defaults to: the newest that is open or in review,
     * falling back to the newest of any status.
     *
     * A dashboard that opened on a cycle closed two years ago because it
     * happened to be created first is one nobody trusts the second time.
     */
    public function defaultCycle(): ?RcsaCycle
    {
        return RcsaCycle::query()
            ->whereIn('status', [RcsaCycle::OPEN, RcsaCycle::IN_REVIEW])
            ->orderByDesc('period_start')
            ->first()
            ?? RcsaCycle::query()->orderByDesc('period_start')->first();
    }

    /**
     * The 5×5 heat map (§10.3), for one basis.
     *
     * INHERENT USES THE ASSESSOR'S OWN PAIR; RESIDUAL DOES NOT HAVE ONE. In
     * calculated mode a residual is a single fractional score with no
     * likelihood/impact pair behind it, so plotting it on a 5×5 grid means
     * deciding where it goes — and the only defensible answer is the cell whose
     * inherent likelihood is unchanged (a control mitigates impact and
     * detection, not the frequency of the underlying event) with the impact
     * axis moved to the band the residual score actually lands in. That is
     * stated here rather than left implicit, because a heat map is the single
     * most-quoted artefact in a Board pack and its construction should not be
     * a mystery.
     *
     * @return array{basis: string, cells: list<array{likelihood: int, impact: int, count: int, level: string|null}>, total: int}
     */
    public function heatMap(?int $cycleId, string $basis = 'inherent'): array
    {
        $rows = $this->lines($cycleId)
            ->whereNotNull('inherent_likelihood')
            ->whereNotNull('inherent_impact')
            ->when($basis === 'residual', fn ($q) => $q->whereNotNull('residual_score'))
            ->get(['id', 'inherent_likelihood', 'inherent_impact', 'inherent_level', 'residual_score', 'residual_level']);

        $methodology = $this->methodologyFor($cycleId);
        $maxImpact = 5;

        $cells = [];

        foreach ($rows as $line) {
            $likelihood = (int) $line->inherent_likelihood;

            if ($basis === 'residual') {
                // The residual score divided by the unchanged likelihood gives
                // the effective impact the score implies. Clamped into the
                // grid, and floored at 1 so a fully-mitigated risk still
                // appears somewhere rather than vanishing off the map.
                $impact = $likelihood > 0
                    ? (int) max(1, min($maxImpact, (int) round((float) $line->residual_score / $likelihood)))
                    : 1;
                $level = $line->residual_level;
            } else {
                $impact = (int) $line->inherent_impact;
                $level = $line->inherent_level;
            }

            $key = $likelihood.':'.$impact;

            $cells[$key] ??= ['likelihood' => $likelihood, 'impact' => $impact, 'count' => 0, 'level' => $level];
            $cells[$key]['count']++;
        }

        // Every cell of the grid, not only the occupied ones: a heat map with
        // holes in it reads as missing data rather than as an empty cell.
        $full = [];

        for ($likelihood = 5; $likelihood >= 1; $likelihood--) {
            for ($impact = 1; $impact <= $maxImpact; $impact++) {
                $key = $likelihood.':'.$impact;

                $full[] = $cells[$key] ?? [
                    'likelihood' => $likelihood,
                    'impact' => $impact,
                    'count' => 0,
                    'level' => $methodology?->bandFor((float) ($likelihood * $impact))?->level,
                ];
            }
        }

        return ['basis' => $basis, 'cells' => $full, 'total' => $rows->count()];
    }

    /**
     * The risks behind one cell of the heat map (§10.3's drill-through).
     *
     * THE CELL IS RE-DERIVED, not stored. `heatMap()` decides which cell a line
     * falls in — including the residual basis's implied impact — and this asks
     * the same question of the same rows rather than keeping a second copy of
     * the rule. Two implementations of "which cell is this risk in" would
     * disagree the first time either changed.
     *
     * @return list<array<string, mixed>>
     */
    public function drillThrough(?int $cycleId, string $basis, int $likelihood, int $impact): array
    {
        $rows = $this->lines($cycleId)
            ->where('inherent_likelihood', $likelihood)
            ->when($basis === 'residual', fn ($q) => $q->whereNotNull('residual_score'))
            ->when($basis !== 'residual', fn ($q) => $q->where('inherent_impact', $impact))
            ->orderByDesc('residual_score')
            ->get(['id', 'assessment_id', 'risk_no', 'potential_risk', 'business_unit_name',
                'inherent_likelihood', 'inherent_impact', 'inherent_score', 'inherent_level',
                'residual_score', 'residual_level', 'control_effectiveness']);

        if ($basis === 'residual') {
            $rows = $rows->filter(function (RcsaAssessmentLine $line) use ($likelihood, $impact) {
                $implied = $likelihood > 0
                    ? (int) max(1, min(5, (int) round((float) $line->residual_score / $likelihood)))
                    : 1;

                return $implied === $impact;
            });
        }

        return $rows
            ->map(fn (RcsaAssessmentLine $line) => [
                'id' => $line->id,
                'assessment_id' => $line->assessment_id,
                'risk_no' => $line->risk_no,
                'potential_risk' => $line->potential_risk,
                'business_unit' => $line->business_unit_name,
                'control_effectiveness' => $line->control_effectiveness,
                'inherent_score' => $line->inherent_score,
                'inherent_level' => $line->inherent_level,
                'residual_score' => $line->residual_score,
                'residual_level' => $line->residual_level,
            ])
            ->values()
            ->all();
    }

    /**
     * The ten highest residual risks in the cycle (§10.3).
     *
     * @return list<array<string, mixed>>
     */
    public function topResidualRisks(?int $cycleId): array
    {
        return $this->lines($cycleId)
            ->whereNotNull('residual_score')
            ->orderByDesc('residual_score')
            ->orderBy('risk_no')
            ->limit(self::TOP_RISKS)
            ->get(['id', 'assessment_id', 'risk_no', 'potential_risk', 'business_unit_name',
                'residual_score', 'residual_level', 'inherent_score', 'risk_treatment'])
            ->map(fn (RcsaAssessmentLine $line) => [
                'id' => $line->id,
                'assessment_id' => $line->assessment_id,
                'risk_no' => $line->risk_no,
                'potential_risk' => $line->potential_risk,
                'business_unit' => $line->business_unit_name,
                'inherent_score' => $line->inherent_score,
                'residual_score' => $line->residual_score,
                'residual_level' => $line->residual_level,
                'treatment' => $line->risk_treatment,
            ])
            ->all();
    }

    /**
     * Risks above appetite, by business unit (§10.3).
     *
     * @return list<array{label: string, count: int, total: int}>
     */
    public function aboveAppetiteByUnit(?int $cycleId): array
    {
        $totals = $this->lines($cycleId)
            ->selectRaw('business_unit_name, count(*) as aggregate')
            ->groupBy('business_unit_name')
            ->pluck('aggregate', 'business_unit_name');

        $above = $this->lines($cycleId)
            ->where('above_appetite', true)
            ->selectRaw('business_unit_name, count(*) as aggregate')
            ->groupBy('business_unit_name')
            ->pluck('aggregate', 'business_unit_name');

        return $totals
            ->map(fn ($total, $unit) => [
                'label' => (string) $unit,
                'count' => (int) ($above[$unit] ?? 0),
                'total' => (int) $total,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * How the bank rates its own controls (§10.3).
     *
     * @return list<array{label: string, count: int}>
     */
    public function controlEffectiveness(?int $cycleId): array
    {
        $counts = $this->lines($cycleId)
            ->whereNotNull('control_effectiveness')
            ->selectRaw('control_effectiveness, count(*) as aggregate')
            ->groupBy('control_effectiveness')
            ->pluck('aggregate', 'control_effectiveness');

        $methodology = $this->methodologyFor($cycleId);

        // In the methodology's own order — best to worst — not alphabetically
        // and not by size. A distribution whose bars reorder as the data moves
        // cannot be read at a glance, and "Fully Achieved" belongs beside
        // "Mostly Achieved" wherever the counts fall.
        $ordered = $methodology === null
            ? $counts->keys()->all()
            : array_values(array_map(
                fn ($item) => $item->label,
                $methodology->scale(\App\Models\Rcsa\RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS)
            ));

        $rows = [];

        foreach ($ordered as $label) {
            $rows[] = ['label' => $label, 'count' => (int) ($counts[$label] ?? 0)];
        }

        return $rows;
    }

    /**
     * Completion by business unit, with days to the due date (§10.3).
     *
     * `completion_pct` is READ, not recomputed — it is a stored column written
     * on every line save for exactly this screen.
     *
     * @return list<array<string, mixed>>
     */
    public function completionTracker(?int $cycleId): array
    {
        $cycle = $cycleId === null ? null : RcsaCycle::query()->find($cycleId);

        return $this->assessments($cycleId)
            ->with(['businessUnit:id,name', 'assignee:id,name'])
            ->withCount('lines')
            ->orderBy('completion_pct')
            ->get()
            ->map(fn (RcsaAssessment $assessment) => [
                'id' => $assessment->id,
                'business_unit' => $assessment->getRelationValue('businessUnit')?->name,
                'status' => $assessment->status,
                'completion_pct' => $assessment->completion_pct,
                'lines_count' => $assessment->lines_count,
                'assignee' => $assessment->getRelationValue('assignee')?->name,
                // Negative once the due date has passed — the sign is the
                // whole message, and the screen colours on it.
                'days_to_due' => $cycle?->due_date === null
                    ? null
                    : (int) round(now()->startOfDay()->diffInDays($cycle->due_date->startOfDay(), false)),
            ])
            ->all();
    }

    /**
     * Action plans by status, and how long the open ones have been open
     * (§10.3).
     *
     * THE REGISTER IS NOT CYCLE-SCOPED and this panel is not either, because
     * §9.3 made the register outlive the cycle. Filtering it to the current
     * cycle would empty the ageing panel every quarter, which is precisely how
     * remediation stops being tracked.
     *
     * @return array{by_status: list<array{label: string, count: int}>, ageing: list<array{label: string, count: int}>, overdue: int, total: int}
     */
    public function actionPlans(): array
    {
        // Scoped through the LINE, because a plan carries no unit of its own.
        $plans = fn () => app(RcsaScope::class)->applyThrough(RcsaActionPlan::query(), $this->viewer, 'line');

        $byStatus = $plans()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $open = $plans()
            ->whereNotIn('status', RcsaActionPlan::SETTLED)
            ->whereNotNull('target_date')
            ->get(['id', 'target_date', 'status']);

        // Buckets by how far past due, computed in PHP over the OPEN plans
        // only — a few hundred rows at most, and expressing "31 to 60 days
        // late" as portable SQL means date arithmetic that MySQL and SQLite
        // spell differently.
        $buckets = ['Not yet due' => 0, '1–30 days late' => 0, '31–60 days late' => 0, '61–90 days late' => 0, 'Over 90 days late' => 0];

        foreach ($open as $plan) {
            $days = -1 * (int) $plan->daysUntilDue();

            $key = match (true) {
                $days <= 0 => 'Not yet due',
                $days <= 30 => '1–30 days late',
                $days <= 60 => '31–60 days late',
                $days <= 90 => '61–90 days late',
                default => 'Over 90 days late',
            };

            $buckets[$key]++;
        }

        return [
            'by_status' => array_map(
                fn (string $status) => ['label' => $status, 'count' => (int) ($byStatus[$status] ?? 0)],
                RcsaActionPlan::STATUSES,
            ),
            'ageing' => array_map(
                fn (string $label) => ['label' => $label, 'count' => $buckets[$label]],
                array_keys($buckets),
            ),
            'overdue' => $open->filter(fn (RcsaActionPlan $p) => $p->isOverdue())->count(),
            'total' => (int) $byStatus->sum(),
        ];
    }

    /**
     * Cycle-over-cycle movement (§10.3).
     *
     * Joined through `prior_cycle_line_id`, which P3 wrote when the cycle was
     * opened — the same link the workspace uses to show last quarter's answer.
     * A risk with no prior line is NEW, and counted as such rather than being
     * dropped: a quarter that added forty risks and moved none is a real
     * finding, and a movement chart that showed nothing would hide it.
     *
     * @return array{improved: int, worsened: int, unchanged: int, new: int, cycle: string|null, prior: string|null}
     */
    public function movement(?int $cycleId): array
    {
        $lines = $this->lines($cycleId)
            ->with('priorLine:id,residual_score,residual_level')
            ->whereNotNull('residual_score')
            ->get(['id', 'residual_score', 'residual_level', 'prior_cycle_line_id']);

        $improved = $worsened = $unchanged = $new = 0;
        $priorCycle = null;

        foreach ($lines as $line) {
            $prior = $line->getRelationValue('priorLine');

            if ($prior === null || $prior->residual_score === null) {
                $new++;

                continue;
            }

            $delta = (float) $line->residual_score - (float) $prior->residual_score;

            match (true) {
                $delta < 0 => $improved++,
                $delta > 0 => $worsened++,
                default => $unchanged++,
            };
        }

        $cycle = $cycleId === null ? null : RcsaCycle::query()->find($cycleId);

        if ($cycle !== null) {
            $priorCycle = RcsaCycle::query()
                ->where('period_start', '<', $cycle->period_start)
                ->orderByDesc('period_start')
                ->first();
        }

        return [
            'improved' => $improved,
            'worsened' => $worsened,
            'unchanged' => $unchanged,
            'new' => $new,
            'cycle' => $cycle?->name,
            'prior' => $priorCycle?->name,
        ];
    }

    /**
     * The tiles across the top.
     *
     * @return array<string, float|int|string|null>
     */
    public function headline(?int $cycleId): array
    {
        $total = $this->lines($cycleId)->count();

        return [
            'risks' => $total,
            'assessed' => $this->lines($cycleId)
                ->whereNotNull('inherent_likelihood')
                ->whereNotNull('inherent_impact')
                ->whereNotNull('control_effectiveness')
                ->count(),
            'above_appetite' => $this->lines($cycleId)->where('above_appetite', true)->count(),
            'units' => $this->assessments($cycleId)->count(),
            'units_complete' => $this->assessments($cycleId)->where('completion_pct', '>=', 100)->count(),
            'average_residual' => $total === 0 ? null : round(
                (float) $this->lines($cycleId)->whereNotNull('residual_score')->avg('residual_score'),
                2,
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Every line of one cycle, as a fresh query.
     *
     * A METHOD RATHER THAN A SHARED BUILDER, because Eloquent builders are
     * mutable: handing the same instance to eight panels would have each one
     * inherit the previous panel's `where`.
     *
     * @return Builder<RcsaAssessmentLine>
     */
    private function lines(?int $cycleId): Builder
    {
        return app(RcsaScope::class)->apply(
            RcsaAssessmentLine::query()
                ->when($cycleId !== null, fn ($q) => $q->whereHas(
                    'assessment',
                    fn ($a) => $a->where('cycle_id', $cycleId),
                )),
            $this->viewer,
        );
    }

    /**
     * Assessments in scope — the completion tracker and the unit counts.
     *
     * @return Builder<RcsaAssessment>
     */
    private function assessments(?int $cycleId): Builder
    {
        return app(RcsaScope::class)->apply(
            RcsaAssessment::query()->when($cycleId !== null, fn ($q) => $q->where('cycle_id', $cycleId)),
            $this->viewer,
        );
    }

    private function methodologyFor(?int $cycleId): ?RcsaMethodology
    {
        $key = $cycleId ?? 0;

        if (array_key_exists($key, $this->methodologyCache)) {
            return $this->methodologyCache[$key];
        }

        $methodologyId = $cycleId === null
            ? null
            : RcsaCycle::query()->find($cycleId)?->methodology_id;

        return $this->methodologyCache[$key] = $methodologyId === null
            ? RcsaMethodology::active()?->loadMissing(['scaleItems', 'bands', 'categoryAppetites'])
            : RcsaMethodology::withoutGlobalScopes()
                ->with(['scaleItems', 'bands', 'categoryAppetites'])
                ->find($methodologyId);
    }
}
