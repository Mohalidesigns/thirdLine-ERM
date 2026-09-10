<?php

namespace App\Support\Scoring;

/**
 * The payload of the 5×5 profile the platform behaved as though it had before
 * WP-05 TASK 3 made it a row.
 *
 * This lives in a class rather than inline in the migration because four
 * callers need the identical definition: the migration that seeds it, the
 * seeder that refreshes it on an existing install, the "create a profile"
 * action in the admin builder, and the parity test that proves scores did not
 * move across the upgrade. Four copies of a rating band is how the three
 * copies this work package is deleting came to exist.
 *
 * THE LABELS ARE NOT INVENTED. They are the strings already rendered by
 * assessments/create, register/create, register/edit, rcsa/worksheet,
 * campaigns/respond, treatments/edit, the dashboard and the heat map — eleven
 * hardcoded copies, of which this work package removes the heat map's and
 * leaves the rest reading the profile in a later pass.
 *
 * THE FINANCIAL BANDS ARE DELIBERATELY NULL. Nothing in the platform has ever
 * held a naira value for "impact = 4", so there is no number to migrate. A
 * plausible-looking ₦50m invented here would be indistinguishable, on screen,
 * from a threshold the organisation had actually agreed — and the one thing
 * worse than an unconfigured band is a fabricated one. Tenants fill these in
 * through the builder; until they do, the scale stays qualitative, exactly as
 * it is today.
 */
class ScoringProfileTemplates
{
    /** The rating bands as RiskScoringService::calculateRating() spelled them. */
    public const DEFAULT_RATING_BANDS = [
        ['code' => 'low', 'label' => 'Low', 'color' => '#22c55e', 'min' => 1, 'max' => 4],
        ['code' => 'medium', 'label' => 'Medium', 'color' => '#eab308', 'min' => 5, 'max' => 11],
        ['code' => 'high', 'label' => 'High', 'color' => '#f97316', 'min' => 12, 'max' => 19],
        ['code' => 'critical', 'label' => 'Critical', 'color' => '#dc2626', 'min' => 20, 'max' => 25],
    ];

    public const DEFAULT_LIKELIHOOD_LABELS = [
        1 => 'Rare',
        2 => 'Unlikely',
        3 => 'Possible',
        4 => 'Likely',
        5 => 'Almost Certain',
    ];

    public const DEFAULT_IMPACT_LABELS = [
        1 => 'Insignificant',
        2 => 'Minor',
        3 => 'Moderate',
        4 => 'Major',
        5 => 'Catastrophic',
    ];

    /** The dimensions RiskScoringService::IMPACT_DIMENSIONS listed, in order. */
    public const DEFAULT_IMPACT_DIMENSIONS = [
        'financial', 'operational', 'reputational', 'regulatory', 'strategic',
    ];

    /**
     * The stored form of the platform default: inherent, discounted by however
     * much of the risk the controls are assessed to remove.
     */
    public const DEFAULT_RESIDUAL_FORMULA = 'inherent * (1 - effectiveness / 100)';

    /**
     * A complete profile payload, ready to insert.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function default(string $currency = 'NGN', array $overrides = []): array
    {
        return array_merge([
            'code' => 'default-5x5',
            'name' => 'Default 5×5',
            'description' => 'The five-by-five matrix the platform used before scoring profiles existed. '
                .'Scores and ratings produced by this profile are identical to the previous hardcoded behaviour.',
            'applies_to' => null,
            'likelihood_scale' => self::likelihoodScale(5),
            'impact_scale' => self::impactScale(5, $currency),
            'impact_dimensions' => self::DEFAULT_IMPACT_DIMENSIONS,
            'impact_aggregation' => 'max',
            'dimension_weights' => array_fill_keys(self::DEFAULT_IMPACT_DIMENSIONS, 1.0),
            'rating_bands' => self::DEFAULT_RATING_BANDS,
            'residual_formula' => self::DEFAULT_RESIDUAL_FORMULA,
            'matrix_rows' => 5,
            'matrix_cols' => 5,
            'is_default' => true,
            'is_system' => true,
        ], $overrides);
    }

    /**
     * A likelihood scale of $points, using the familiar labels where they fit.
     *
     * A 3-point scale takes Rare / Possible / Almost Certain rather than the
     * first three, because the endpoints are what a scale means: "the least
     * likely thing we track" and "expected". Truncating would leave a 3-point
     * scale whose top rung was "Possible".
     *
     * @return list<array<string, mixed>>
     */
    public static function likelihoodScale(int $points): array
    {
        return array_map(
            fn (array $level) => $level + [
                // Fractions of 1: 0.05 is a 5% chance. Null until the
                // organisation agrees a figure — see the class docblock.
                'probability_min' => null,
                'probability_max' => null,
            ],
            self::labelledScale($points, self::DEFAULT_LIKELIHOOD_LABELS, 'Likelihood')
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function impactScale(int $points, string $currency = 'NGN'): array
    {
        return array_map(
            fn (array $level) => $level + [
                // Minor units, per the platform money rule. Null until the
                // organisation agrees a figure — see the class docblock.
                'financial_min' => null,
                'financial_max' => null,
                'currency' => $currency,
            ],
            self::labelledScale($points, self::DEFAULT_IMPACT_LABELS, 'Impact')
        );
    }

    /**
     * Stretch a five-label vocabulary over an arbitrary number of points.
     *
     * Both endpoints always land on an endpoint label, because the endpoints
     * are what a scale means: truncating to the first three would leave a
     * 3-point likelihood scale whose top rung was "Possible".
     *
     * Where a scale is longer than the vocabulary the interpolation would
     * repeat a label, which reads as a bug on screen; repeats are numbered
     * instead, and the tenant renames them in the builder.
     *
     * @param  array<int, string>  $labels
     * @return list<array<string, mixed>>
     */
    private static function labelledScale(int $points, array $labels, string $noun): array
    {
        $points = max(2, min(10, $points));
        $vocabulary = array_values($labels);
        $last = count($vocabulary) - 1;

        $scale = [];
        $used = [];

        for ($value = 1; $value <= $points; $value++) {
            $index = (int) round((($value - 1) / ($points - 1)) * $last);
            $label = $vocabulary[$index] ?? "{$noun} {$value}";

            if (isset($used[$label])) {
                $label = "{$noun} {$value}";
            }

            $used[$label] = true;

            $scale[] = [
                'value' => $value,
                'label' => $label,
                'definition' => null,
            ];
        }

        return $scale;
    }

    /**
     * Rating bands rescaled to a matrix of $rows × $cols.
     *
     * The 5×5 proportions are preserved rather than reinvented: Low occupies
     * the bottom 16% of the score range, Medium to 44%, High to 76%, Critical
     * above. Those are exactly where 4/25, 11/25 and 19/25 fall, so a 5×5
     * profile built this way reproduces the old bands to the point.
     *
     * @return list<array<string, mixed>>
     */
    public static function ratingBandsFor(int $rows, int $cols): array
    {
        if ($rows === 5 && $cols === 5) {
            return self::DEFAULT_RATING_BANDS;
        }

        $maxScore = $rows * $cols;
        $cuts = [];
        $previous = 0;

        foreach ([4 / 25, 11 / 25, 19 / 25, 1.0] as $fraction) {
            $edge = max($previous + 1, (int) round($maxScore * $fraction));
            $cuts[] = $edge;
            $previous = $edge;
        }

        // The top band always closes on the highest attainable score, whatever
        // the rounding did on the way up.
        $cuts[3] = $maxScore;

        $bands = [];
        $min = 1;

        foreach (self::DEFAULT_RATING_BANDS as $index => $band) {
            $bands[] = [
                'code' => $band['code'],
                'label' => $band['label'],
                'color' => $band['color'],
                'min' => $min,
                'max' => max($min, $cuts[$index]),
            ];

            $min = max($min, $cuts[$index]) + 1;
        }

        return $bands;
    }
}
