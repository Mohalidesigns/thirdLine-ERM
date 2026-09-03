<?php

namespace App\Services\Appetite;

use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use Illuminate\Support\Collection;

/**
 * The appetite framework's figures (migration Phase 3.6 — extracted from
 * RiskAppetiteController::index so the rules are testable on their own).
 *
 * The classification is the one the Blade screen always applied:
 *   breach      current > max tolerance
 *   near_limit  current > target max, or within 15% of the max tolerance
 *   within      everything else (and any statement with no tolerance set)
 * Characterised in tests/Feature/Characterisation/AppetitePositionTest.
 */
class AppetiteFrameworkService
{
    /** Share of the hard limit above which a position counts as "near". */
    public const NEAR_LIMIT_SHARE = 0.85;

    public const LEVELS = ['averse', 'minimal', 'low', 'cautious', 'moderate', 'open', 'high', 'hungry'];

    /**
     * @return Collection<int, RiskAppetite>
     */
    public function statements(int $organizationId): Collection
    {
        return RiskAppetite::query()
            ->where('organization_id', $organizationId)
            ->with('riskCategory')
            ->orderBy('risk_category_id')
            ->get();
    }

    /**
     * Categories that do not yet have a statement — the only ones the
     * "add" form may offer, because a category holds one statement.
     *
     * @param  Collection<int, RiskAppetite>  $statements
     * @return Collection<int, RiskCategory>
     */
    public function categoriesWithoutStatement(int $organizationId, Collection $statements): Collection
    {
        return RiskCategory::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get()
            ->whereNotIn('id', $statements->pluck('risk_category_id')->all())
            ->values();
    }

    /**
     * The recorded metric position, or the average residual score of the
     * category's active risks when none was recorded. Null when neither
     * exists — the page says so rather than showing 0.0.
     */
    public function currentPosition(RiskAppetite $appetite): ?float
    {
        if ($appetite->current_position !== null) {
            return (float) $appetite->current_position;
        }

        $average = Risk::query()
            ->where('organization_id', $appetite->organization_id)
            ->where('category_id', $appetite->risk_category_id)
            ->where('status', 'active')
            ->avg('residual_score');

        return $average === null ? null : (float) $average;
    }

    public function statusFor(?float $current, float $targetMax, float $maxTolerance): string
    {
        if ($maxTolerance <= 0 || $current === null) {
            return 'within';
        }

        if ($current > $maxTolerance) {
            return 'breach';
        }

        if ($targetMax > 0 && $current > $targetMax) {
            return 'near_limit';
        }

        if ($current > $maxTolerance * self::NEAR_LIMIT_SHARE) {
            return 'near_limit';
        }

        return 'within';
    }

    /**
     * One row per statement, as the page shows it.
     *
     * @param  Collection<int, RiskAppetite>  $statements
     * @return list<array<string, mixed>>
     */
    public function metrics(Collection $statements): array
    {
        return $statements->map(function (RiskAppetite $a) {
            $current = $this->currentPosition($a);
            $targetMax = (float) ($a->target_max ?? 0);
            $maxTolerance = (float) ($a->max_tolerance ?? 0);
            $status = $this->statusFor($current, $targetMax, $maxTolerance);

            return [
                'id' => $a->id,
                'risk_category' => $a->riskCategory->name ?? 'Uncategorised',
                'appetite_level' => $a->appetite_level,
                'appetite_statement' => $a->appetite_statement,
                'metric_name' => $a->tolerance_metric ?? 'Avg. residual score',
                'unit_of_measure' => $a->unit_of_measure,
                'lower_limit' => (float) ($a->target_min ?? 0),
                'target_max' => $targetMax,
                'upper_limit' => $maxTolerance,
                'current_value' => $current,
                'position_source' => $a->current_position !== null ? 'recorded' : ($current === null ? 'none' : 'residual_average'),
                'status' => $status,
                'trend' => $status === 'breach' ? 'up' : ($status === 'within' ? 'down' : 'flat'),
                'form' => [
                    'appetite_level' => $a->appetite_level,
                    'appetite_statement' => $a->appetite_statement,
                    'tolerance_metric' => $a->tolerance_metric,
                    'unit_of_measure' => $a->unit_of_measure,
                    'target_min' => $a->target_min === null ? '' : (string) (float) $a->target_min,
                    'target_max' => $a->target_max === null ? '' : (string) (float) $a->target_max,
                    'max_tolerance' => $a->max_tolerance === null ? '' : (string) (float) $a->max_tolerance,
                    'capacity' => $a->capacity === null ? '' : (string) (float) $a->capacity,
                    'current_position' => $a->current_position === null ? '' : (string) (float) $a->current_position,
                    'effective_date' => self::date($a->effective_date, 'Y-m-d') ?? '',
                    'expiry_date' => self::date($a->expiry_date, 'Y-m-d') ?? '',
                ],
                'history' => [
                    'created_at' => self::date($a->created_at, 'd M Y H:i'),
                    'updated_at' => self::date($a->updated_at, 'd M Y H:i'),
                    'effective_date' => self::date($a->effective_date, 'd M Y'),
                    'expiry_date' => self::date($a->expiry_date, 'd M Y'),
                    'approved_date' => self::date($a->approved_date, 'd M Y'),
                ],
            ];
        })->values()->all();
    }

    /**
     * The banner and the KPI row.
     *
     * Dates are null when nothing is recorded: the old screen printed
     * "today minus three months" as the approval date, which was a number
     * nobody had approved.
     *
     * @param  list<array<string, mixed>>  $metrics
     * @param  Collection<int, RiskAppetite>  $statements
     * @return array<string, mixed>
     */
    public function summary(array $metrics, Collection $statements): array
    {
        $count = fn (string $status) => count(array_filter($metrics, fn ($m) => $m['status'] === $status));
        $breaches = $count('breach');
        $near = $count('near_limit');

        $approved = $statements->min('approved_date') ?? $statements->min('effective_date');
        $review = $statements->min('expiry_date');

        return [
            'total' => count($metrics),
            'within' => $count('within'),
            'near_limit' => $near,
            'breaches' => $breaches,
            'overall' => $breaches > 0 ? 'Breach' : ($near > 0 ? 'Near Limit' : 'Within Appetite'),
            'approval_date' => self::date($approved, 'd M Y'),
            'next_review_date' => self::date($review, 'd M Y'),
        ];
    }

    /**
     * The "appetite vs current position" chart as a widget envelope for
     * Components/Widget.jsx (type `appetite_position`, chartConfigs.js).
     * Band colours are semantic and travel with the payload.
     *
     * @param  list<array<string, mixed>>  $metrics
     * @return array<string, mixed>
     */
    public function chart(array $metrics): array
    {
        return [
            'state' => 'ok',
            'widget_id' => 0,
            'code' => 'appetite-position',
            'type' => 'appetite_position',
            'title' => 'Appetite vs current position',
            'data' => [
                'bars' => array_map(fn ($m) => [
                    'label' => $m['risk_category'],
                    'current' => $m['current_value'],
                    'target_max' => $m['target_max'],
                    'limit' => $m['upper_limit'],
                    'status' => $m['status'],
                ], $metrics),
                'colors' => ['within' => '#2D7D46', 'near_limit' => '#D4AF37', 'breach' => '#C53030'],
            ],
            'visualisation' => [],
            'drilldown' => null,
            'meta' => [],
        ];
    }

    private static function date(mixed $value, string $format): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format($format) : null;
    }
}
