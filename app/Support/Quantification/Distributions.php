<?php

namespace App\Support\Quantification;

/**
 * Distribution parameter conversions (migration Phase 5.2).
 *
 * Lifted out of QuantificationController so that the one place that knows how
 * a mean and a standard deviation become lognormal parameters is not a private
 * method on a controller. MonteCarloService draws on these parameters and the
 * ICAAP add-on is computed from the runs, so the conversion is load-bearing.
 */
final class Distributions
{
    /**
     * Sigma for a scenario whose moments cannot yield one.
     *
     * Used only when the mean or the standard deviation is missing or
     * non-positive, where sqrt(ln(1 + (s/m)^2)) is undefined. It is a stated
     * placeholder for an unparameterised draft, not an estimate of anything.
     */
    public const DEFAULT_SIGMA = 1.0;

    /**
     * Method-of-moments lognormal parameters from a mean and standard
     * deviation quoted in Naira.
     *
     * THE DEFECT (WP-08). Three copies of this arithmetic — storeScenario,
     * updateScenario and importLibrary — computed
     *
     *     $mu = log($meanKobo);
     *
     * For a lognormal, E[X] = exp(mu + sigma^2/2). Setting mu = ln(mean)
     * therefore does not produce a distribution whose mean is `mean`; it
     * produces one whose mean is mean * exp(sigma^2/2). At sigma = 1.0 — the
     * fallback used whenever no standard deviation is supplied — every
     * simulated severity was 1.65x the mean the user typed in, and at the
     * engine's own sigma = 1.5 fallback it was 3.08x. That error flows
     * straight through expected loss, VaR and the ICAAP capital number.
     *
     * The correct inversion, given a target mean m and standard deviation s:
     *
     *     sigma = sqrt( ln( 1 + (s/m)^2 ) )
     *     mu    = ln(m) - sigma^2 / 2
     *
     * sigma was already right; only mu was wrong, and it can only be computed
     * once sigma is known, which is why sigma is derived first here. The same
     * file already used the correct relationship in
     * buildDistributionVisualization() (exp($mu + $sigma^2/2)) — the two sides
     * of the screen disagreed with each other.
     *
     * The ratio s/m is dimensionless, so it is taken in Naira while mu is
     * taken in kobo; the parameters are stored against a kobo scale because
     * that is the scale MonteCarloService draws on.
     *
     * Existing rows written under the old formula are corrected by migration
     * 2026_08_19_120003_correct_lognormal_mu_on_scenarios.
     *
     * @return array{0: float, 1: float, 2: float} [mu, sigma, mean in kobo]
     */
    public static function lognormalFromMoments(float $meanNaira, float $stdDevNaira): array
    {
        $meanKobo = round($meanNaira * 100);

        $sigma = ($stdDevNaira > 0 && $meanNaira > 0)
            ? sqrt(log(1 + ($stdDevNaira / $meanNaira) ** 2))
            : self::DEFAULT_SIGMA;

        $mu = $meanKobo > 0
            ? log($meanKobo) - ($sigma ** 2) / 2
            : 0.0;

        return [$mu, $sigma, $meanKobo];
    }
}
