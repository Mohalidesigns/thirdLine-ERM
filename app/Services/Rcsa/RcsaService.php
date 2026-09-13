<?php

namespace App\Services\Rcsa;

use App\Models\BusinessUnit;
use App\Models\Control;
use App\Models\Risk;
use App\Models\RiskControlMapping;
use App\Support\Authorization\GraphScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The figures behind the four RCSA screens (migration Phase 3.8).
 *
 * Lifted out of RcsaController, which computed all of them inline. Every
 * number here is pinned by tests/Feature/Characterisation/RcsaFiguresTest,
 * which was written against the Blade screens first and re-pointed at the
 * Inertia props afterwards.
 *
 * The ASSESSMENT WINDOWS are the module's one real piece of domain arithmetic
 * and they are carried across exactly: a risk is "completed" when assessed
 * inside 12 months, "in progress" between 12 and 18 months, "not started" when
 * never assessed, and "overdue" when never assessed OR assessed more than 12
 * months ago. Overdue therefore OVERLAPS not-started and in-progress — the six
 * tiles are six independent answers, not a partition, and the characterisation
 * test pins that (3 overdue out of 6 risks, while completed + in progress +
 * not started is 5).
 */
class RcsaService
{
    /** A risk assessed longer ago than this is no longer current. */
    public const CURRENT_MONTHS = 12;

    /** Beyond this, an assessment is not merely stale but abandoned. */
    public const STALE_MONTHS = 18;

    /** Per-unit progress at or above this reads as done. */
    public const COMPLETE_PCT = 80;

    /** Below this, a unit is behind rather than in progress. */
    public const IN_PROGRESS_PCT = 50;

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * The six KPI tiles.
     *
     * @return array<string, int>
     */
    public function dashboardKpis(): array
    {
        $total = $this->activeRisks()->count();

        $completed = $this->activeRisks()
            ->whereNotNull('last_assessment_date')
            ->where('last_assessment_date', '>=', $this->currentSince())
            ->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'inProgress' => $this->activeRisks()
                ->whereNotNull('last_assessment_date')
                ->where('last_assessment_date', '<', $this->currentSince())
                ->where('last_assessment_date', '>=', $this->staleSince())
                ->count(),
            'notStarted' => $this->activeRisks()->whereNull('last_assessment_date')->count(),
            'overdue' => $this->activeRisks()
                ->where(fn (Builder $q) => $q
                    ->whereNull('last_assessment_date')
                    ->orWhere('last_assessment_date', '<', $this->currentSince()))
                ->count(),
            'completionRate' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
        ];
    }

    /**
     * Assessment progress per business unit, ordered by how much each one has
     * to assess.
     *
     * WHAT IS NO LONGER HERE: `control_gaps`, which was the literal constant 0
     * for every unit on every tenant, and `due_date`, which was
     * `now()->addDays(30)` — the same invented date against every unit, moving
     * forward a day with every page load. Neither was computed from anything. See the module notes.
     *
     * @return list<array<string, mixed>>
     */
    public function unitProgress(): array
    {
        return BusinessUnit::where('organization_id', $this->orgId())
            ->withCount([
                'risks as total_risks' => fn (Builder $q) => $q->where('status', 'active'),
                'risks as assessed' => fn (Builder $q) => $q
                    ->where('status', 'active')
                    ->whereNotNull('last_assessment_date')
                    ->where('last_assessment_date', '>=', $this->currentSince()),
                'risks as high_risks' => fn (Builder $q) => $q
                    ->where('status', 'active')
                    ->whereIn('residual_rating', ['High', 'Critical']),
            ])
            ->orderByDesc('total_risks')
            ->get()
            ->map(function (BusinessUnit $unit) {
                // withCount aliases: query-time attributes, not columns on the
                // model, so they are read as attributes rather than declared.
                $total = (int) $unit->getAttribute('total_risks');
                $assessed = (int) $unit->getAttribute('assessed');

                $progress = $total > 0 ? (int) round(($assessed / $total) * 100) : 0;

                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'totalRisks' => $total,
                    'assessed' => $assessed,
                    'progress' => $progress,
                    'highRisks' => (int) $unit->getAttribute('high_risks'),
                    'status' => match (true) {
                        $progress >= self::COMPLETE_PCT => 'Completed',
                        $progress >= self::IN_PROGRESS_PCT => 'In Progress',
                        default => 'Behind',
                    },
                ];
            })
            ->all();
    }

    /**
     * The eight worst active risks, worst rating first and worst score within
     * a rating.
     *
     * `FIELD()` is MySQL-only, so this ordering threw on any other driver and
     * the dashboard could not be tested at all; it is a portable CASE now.
     *
     * WHAT IS NO LONGER HERE: `control_effectiveness`, hardcoded to the string
     * 'partially' for every risk ever listed, and `action_required`, hardcoded
     * to "Review control design and operating effectiveness". Both were
     * presented as assessment findings. Real control effectiveness per risk is
     * available and is now read from the mappings.
     *
     * @return list<array<string, mixed>>
     */
    public function topRisks(int $limit = 8): array
    {
        $risks = Risk::where('organization_id', $this->orgId())
            ->visibleTo()
            ->where('status', 'active')
            ->orderByRaw($this->ratingOrder())
            ->orderByDesc('residual_score')
            ->limit($limit)
            ->with(['businessUnit', 'controlMappings'])
            ->get();

        return $risks->map(fn (Risk $risk) => [
            'id' => $risk->id,
            'title' => $risk->title,
            'businessUnit' => $risk->businessUnit?->name,
            'inherentRating' => $risk->inherent_rating,
            'residualRating' => $risk->residual_rating,
            'controlEffectiveness' => $this->effectivenessAcross($risk),
            'controlCount' => $risk->controlMappings->count(),
        ])->all();
    }

    /**
     * The weakest effectiveness among the controls mapped to a risk, or null
     * when nothing is mapped or nothing is rated.
     *
     * Weakest rather than average: a risk with one ineffective control is not
     * two-thirds covered, it has a hole. Null is rendered as "Not assessed"
     * rather than being coloured as a finding.
     */
    private function effectivenessAcross(Risk $risk): ?string
    {
        // controlMappings is an alias of controls(): the related model IS the
        // Control, not a pivot row wrapping one.
        $ratings = $risk->controlMappings
            ->map(fn (Control $control) => $this->bucket($control->effectiveness_rating))
            ->reject(fn (string $bucket) => $bucket === 'na');

        if ($ratings->isEmpty()) {
            return null;
        }

        foreach (['ineffective', 'partially', 'effective'] as $worst) {
            if ($ratings->contains($worst)) {
                return $worst;
            }
        }

        return null;
    }

    /**
     * Active risks per residual rating band, always all four in a fixed order.
     *
     * @return list<array{rating: string, value: int}>
     */
    public function riskDistribution(): array
    {
        $counts = $this->activeRisks()
            ->selectRaw('residual_rating, COUNT(*) c')
            ->groupBy('residual_rating')
            ->pluck('c', 'residual_rating');

        return array_map(fn (string $rating) => [
            'rating' => $rating,
            'value' => (int) ($counts[$rating] ?? 0),
        ], ['Critical', 'High', 'Medium', 'Low']);
    }

    /**
     * Controls per effectiveness rating, always all four in a fixed order.
     *
     * A control with no rating at all counts nowhere — carried across: the
     * Blade chart's four buckets read the four stored values, and an unrated
     * control is not the same as one rated "not tested".
     *
     * @return list<array{rating: string, label: string, value: int}>
     */
    public function controlEffectiveness(): array
    {
        $counts = $this->controlRatingCounts();

        return array_map(fn (array $band) => [
            'rating' => $band[0],
            'label' => $band[1],
            'value' => (int) ($counts[$band[0]] ?? 0),
        ], [
            ['effective', 'Effective'],
            ['partially_effective', 'Partially Effective'],
            ['ineffective', 'Ineffective'],
            ['not_tested', 'Not Tested'],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Controls screen */
    /* ------------------------------------------------------------------ */

    /**
     * The controls screen's four KPI tiles.
     *
     * `total` counts every control INCLUDING unrated ones, because it is the
     * sum of the grouped counts and the group includes the NULL bucket — which
     * is why total (6) can exceed effective + partial + ineffective (4) in the
     * characterisation test. Carried across unchanged.
     *
     * @return array<string, int>
     */
    public function controlsSummary(): array
    {
        $counts = $this->controlRatingCounts();

        return [
            'total' => (int) $counts->sum(),
            'effective' => (int) ($counts['effective'] ?? 0),
            'partial' => (int) ($counts['partially_effective'] ?? 0),
            'ineffective' => (int) ($counts['ineffective'] ?? 0),
        ];
    }

    /** @return Collection<string, int> */
    private function controlRatingCounts(): Collection
    {
        return Control::where('organization_id', $this->orgId())
            ->selectRaw('effectiveness_rating, COUNT(*) as c')
            ->groupBy('effectiveness_rating')
            ->pluck('c', 'effectiveness_rating');
    }

    /* ------------------------------------------------------------------ */
    /*  Matrix */
    /* ------------------------------------------------------------------ */

    /**
     * The risk-by-control coverage grid.
     *
     * Risks down the side, controls across the top, one bucketed cell per
     * pair, and a coverage percentage per risk — how many of the matrix's
     * controls are mapped to it. Scoped through the risk, the same axis the
     * RCSA matrix export uses: a group-level control appearing against a
     * branch's own risk is what the matrix is for.
     *
     * @return array{risks: list<array<string, mixed>>, controls: list<array<string, mixed>>}
     */
    public function matrix(?int $businessUnitId = null): array
    {
        $query = GraphScope::applyThrough(
            RiskControlMapping::whereHas('risk', fn (Builder $q) => $q->where('organization_id', $this->orgId()))
                ->with(['risk.category', 'control']),
            'risk',
        );

        if ($businessUnitId !== null) {
            $query->whereHas('risk', fn (Builder $q) => $q->where('business_unit_id', $businessUnitId));
        }

        $mappings = $query->get();

        $controls = $mappings->pluck('control')->filter()->unique('id')->sortBy('control_code')->values();
        $risks = $mappings->pluck('risk')->filter()->unique('id')->sortBy('risk_code')->values();

        return [
            'controls' => $controls->map(fn (Control $control) => [
                'id' => $control->id,
                'code' => $control->control_code,
                'name' => $control->name,
            ])->all(),

            'risks' => $risks->map(function (Risk $risk) use ($mappings, $controls) {
                $mapped = $mappings->where('risk_id', $risk->id);

                return [
                    'id' => $risk->id,
                    'code' => $risk->risk_code,
                    'title' => $risk->title,
                    'residualRating' => $risk->residual_rating,
                    'cells' => $controls->map(fn (Control $control) => [
                        'controlId' => $control->id,
                        // The cell's rating is the CONTROL's rating; the
                        // mapping only says whether the pair exists at all.
                        'effectiveness' => $mapped->contains('control_id', $control->id)
                            ? $this->bucket($control->effectiveness_rating)
                            : 'na',
                    ])->all(),
                    'coverage' => $controls->count() > 0
                        ? (int) round($mapped->count() / $controls->count() * 100)
                        : 0,
                ];
            })->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Worksheet */
    /* ------------------------------------------------------------------ */

    /**
     * The register risks an assessor may pull into a worksheet line.
     *
     * `visibleTo()` so a subtree-limited assessor works their own subtree. The
     * Blade controller ran this exact query, paginated and filtered — and the
     * view never used it, so it was 20 rows of eager-loaded work thrown away on
     * every request. See the module notes.
     *
     * @return list<array<string, mixed>>
     */
    public function assessableRisks(?int $businessUnitId = null, ?int $categoryId = null): array
    {
        $query = Risk::where('organization_id', $this->orgId())
            ->visibleTo()
            ->where('status', 'active')
            ->with(['category', 'businessUnit']);

        if ($businessUnitId !== null) {
            $query->where('business_unit_id', $businessUnitId);
        }

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        return $query->orderBy('risk_code')->limit(200)->get()->map(fn (Risk $risk) => [
            'id' => $risk->id,
            'code' => $risk->risk_code,
            'title' => $risk->title,
            'category' => $risk->category?->name,
            'businessUnit' => $risk->businessUnit?->name,
        ])->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Internals */
    /* ------------------------------------------------------------------ */

    private function orgId(): int
    {
        return TenantContext::organizationId();
    }

    /** @return Builder<Risk> */
    private function activeRisks(): Builder
    {
        return Risk::query()
            ->where('organization_id', $this->orgId())
            ->where('status', 'active');
    }

    private function currentSince(): \Illuminate\Support\Carbon
    {
        return now()->subMonths(self::CURRENT_MONTHS);
    }

    private function staleSince(): \Illuminate\Support\Carbon
    {
        return now()->subMonths(self::STALE_MONTHS);
    }

    /**
     * Worst rating first. `FIELD()` is MySQL-only; CASE is portable, which is
     * what lets the dashboard be tested at all.
     */
    private function ratingOrder(): string
    {
        return "CASE residual_rating WHEN 'Critical' THEN 0 WHEN 'High' THEN 1 "
            ."WHEN 'Medium' THEN 2 WHEN 'Low' THEN 3 ELSE 4 END";
    }

    /** The stored effectiveness rating as one of the matrix's four buckets. */
    private function bucket(?string $rating): string
    {
        return match (strtolower((string) $rating)) {
            'effective' => 'effective',
            'partially_effective' => 'partially',
            'ineffective' => 'ineffective',
            default => 'na',
        };
    }
}
