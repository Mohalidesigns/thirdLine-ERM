<?php

namespace App\Services\Tprm\Scoring;

/**
 * TRD §7.6 — how much the residual score should be believed.
 *
 *   DC = Σ wᵢ × f(ageᵢ)
 *   f(age) = 1 up to the policy interval, linear decay to `floor` at
 *            `decay_multiple` × interval, `floor` beyond
 *
 * THIS IS THE HONEST NUMBER BESIDE THE CONFIDENT ONE. A residual score of 32
 * computed from a two-year-old assessment, expired evidence and screening
 * nobody has run is arithmetically correct and worth nothing. Every scoring
 * model in this market produces the 32; almost none of them say how much of it
 * is a guess.
 *
 * A MISSING INPUT SCORES THE FLOOR, NOT ZERO AND NOT ONE. Never assessed,
 * never screened, no evidence at all — each lands on `floor` rather than 0,
 * because the four components are weights on a whole and a zero would make one
 * missing input drag the composite below what any single gap justifies. It
 * emphatically does not score 1: "we have never looked" is the least confident
 * state there is, and a calculator that treated absence as freshness would
 * give its highest confidence to the vendors nobody has touched.
 *
 * PURE, and for the same reason as the residual calculator: a confidence
 * figure stored against last year's score has to be reproducible from the
 * inputs stored with it.
 */
class DataConfidenceCalculator
{
    /**
     * @param  array<string, int|null>  $ages  component => age in days, null where the thing has never happened
     * @param  array<string, int>  $intervals  component => policy interval in days
     * @param  array<string, float>  $weights
     */
    public function calculate(
        array $ages,
        array $intervals,
        array $weights,
        float $floor = 0.2,
        int $decayMultiple = 2,
    ): DataConfidenceResult {
        $components = [];
        $total = 0.0;
        $weightSum = 0.0;

        foreach ($weights as $component => $weight) {
            $age = $ages[$component] ?? null;
            $interval = $intervals[$component] ?? null;

            if ($interval === null || $interval <= 0) {
                // No policy interval means no opinion about staleness for this
                // component. It is dropped from the composite entirely rather
                // than scored — including it at the floor would penalise a
                // tenant for a policy they were never asked to set.
                continue;
            }

            $score = $this->decay($age, $interval, $floor, $decayMultiple);

            $components[$component] = [
                'weight' => $weight,
                'age_days' => $age,
                'interval_days' => $interval,
                'score' => round($score, 3),
                'state' => $this->componentState($age, $interval, $decayMultiple),
            ];

            $total += $weight * $score;
            $weightSum += $weight;
        }

        // Renormalised over the weights actually used, so dropping a component
        // does not silently lower the composite.
        $dc = $weightSum > 0 ? $total / $weightSum : $floor;

        return new DataConfidenceResult(
            dc: $dc,
            components: $components,
            floor: $floor,
        );
    }

    /**
     * f(age): 1 while current, straight-line decay through the grace period,
     * floor thereafter.
     */
    private function decay(?int $age, int $interval, float $floor, int $decayMultiple): float
    {
        // Never happened. The floor, for the reason in the class docblock.
        if ($age === null) {
            return $floor;
        }

        if ($age <= $interval) {
            return 1.0;
        }

        $stale = $interval * $decayMultiple;

        if ($age >= $stale) {
            return $floor;
        }

        // Linear from 1.0 at `interval` to `floor` at `stale`.
        $progress = ($age - $interval) / ($stale - $interval);

        return 1.0 - $progress * (1.0 - $floor);
    }

    private function componentState(?int $age, int $interval, int $decayMultiple): string
    {
        return match (true) {
            $age === null => 'never',
            $age <= $interval => 'current',
            $age < $interval * $decayMultiple => 'ageing',
            default => 'stale',
        };
    }
}
