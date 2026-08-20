<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WP-08 repair — put the -sigma^2/2 term back into the lognormal mu stored on
 * quantification scenarios.
 *
 * THE DEFECT. QuantificationController wrote a scenario's severity parameters
 * in three places (storeScenario, updateScenario, importLibrary) and all three
 * computed
 *
 *     $mu = $meanKobo > 0 ? log($meanKobo) : 0;
 *     $sigma = sqrt(log(1 + (stdDev / mean)^2));
 *
 * sigma is the correct method-of-moments estimator. mu is not. For a lognormal
 * E[X] = exp(mu + sigma^2/2), so setting mu = ln(mean) produces a distribution
 * whose mean is mean * exp(sigma^2/2), not mean. At the controller's own
 * sigma = 1.0 fallback that is a 1.65x overstatement of every simulated
 * severity; at the engine's sigma = 1.5 fallback it is 3.08x. It flows
 * straight through expected loss, VaR, expected shortfall and the ICAAP
 * capital number.
 *
 * The correct inversion is mu = ln(mean) - sigma^2/2, and the source is fixed
 * in QuantificationController::lognormalParametersFromMoments(), which all
 * three call sites now share. This migration repairs the rows already written.
 *
 * WHAT IS AND IS NOT TOUCHED. A row is corrected only where it can be PROVEN
 * to have been written by the old formula, which is provable because the old
 * formula is exactly reproducible from columns that are still on the row:
 *
 *     severity_mu == round(ln(expected_loss_per_event_kobo), 6)
 *
 * Rows that do not satisfy that are left alone. That set includes:
 *
 *   - hand-calibrated scenarios, where a risk quant entered mu and sigma
 *     directly rather than deriving them from a mean. Their mu is already what
 *     the calibrator intended and subtracting sigma^2/2 would corrupt it;
 *   - scenarios whose mean was edited elsewhere after mu was written, so the
 *     relationship no longer holds and we cannot tell which formula produced
 *     the stored mu;
 *   - rows with no expected_loss_per_event_kobo, no sigma, or a non-positive
 *     mean, where there is nothing to test against;
 *   - anything already written by the corrected code.
 *
 * THE LIMITATION, STATED HONESTLY. A hand-calibrated scenario whose mu happens
 * to land within 1e-6 of ln(mean) is indistinguishable from a derived one and
 * will be adjusted. That collision requires a calibrator to have chosen, to
 * six decimal places, the exact value the broken formula would have produced —
 * and if they did, the distribution they described still has a mean of
 * mean * exp(sigma^2/2), which is almost certainly not what they meant either.
 * Conversely, a scenario derived under the old formula and then edited so the
 * relationship no longer holds keeps its wrong mu. It is left wrong rather
 * than guessed at: a mis-corrected capital input is worse than an uncorrected
 * one, because it cannot be found again. Both counts are logged.
 *
 * Sigma is NOT touched. It was already correct.
 *
 * Soft-deleted rows are included — a restored scenario must come back with
 * correct parameters, not with the defect preserved.
 *
 * The query builder is used directly rather than the Eloquent model, because
 * QuantificationScenario carries the tenancy global scope and a migration runs
 * with no tenant context; going through the model would silently repair no
 * rows at all.
 */
return new class extends Migration
{
    /** Tolerance on the six-decimal-place stored value. */
    private const EPSILON = 0.0000015;

    public function up(): void
    {
        $this->shift(-1, 'WP-08 repair: corrected lognormal mu on quantification scenarios.');
    }

    /**
     * Reverse the correction.
     *
     * Detection is the mirror of up(): a row is put back only where its stored
     * mu matches the CORRECTED relationship, mu == round(ln(mean) - sigma^2/2, 6).
     * As in up(), a row hand-calibrated to exactly that value would be caught;
     * that is the same bounded, stated risk, and a rollback is not the moment
     * to be cleverer than the forward migration.
     */
    public function down(): void
    {
        $this->shift(1, 'WP-08 rollback: restored the pre-correction lognormal mu.');
    }

    /**
     * Move mu by $direction * sigma^2/2 on every row that provably sits on the
     * opposite side of the correction.
     *
     * $direction === -1 applies the fix (expects mu == ln(mean));
     * $direction === +1 reverses it (expects mu == ln(mean) - sigma^2/2).
     */
    private function shift(int $direction, string $message): void
    {
        $adjusted = 0;
        $skipped = 0;

        DB::table('quantification_scenarios')
            ->select('id', 'severity_mu', 'severity_sigma', 'expected_loss_per_event_kobo')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$adjusted, &$skipped, $direction) {
                foreach ($rows as $row) {
                    $mu = $row->severity_mu;
                    $sigma = $row->severity_sigma;
                    $meanKobo = $row->expected_loss_per_event_kobo;

                    // Nothing to test the stored mu against.
                    if ($mu === null || $sigma === null || $meanKobo === null) {
                        $skipped++;

                        continue;
                    }

                    $mu = (float) $mu;
                    $sigma = (float) $sigma;
                    $meanKobo = (float) $meanKobo;

                    if ($meanKobo <= 0 || $sigma <= 0) {
                        $skipped++;

                        continue;
                    }

                    $correction = ($sigma ** 2) / 2;

                    // What the row's mu would be if it had been written by the
                    // formula this migration expects to find.
                    $expected = $direction === -1
                        ? round(log($meanKobo), 6)
                        : round(log($meanKobo) - $correction, 6);

                    if (abs($mu - $expected) > self::EPSILON) {
                        // Hand-calibrated, edited since, or already on the
                        // other side of the correction. Left exactly as it is.
                        $skipped++;

                        continue;
                    }

                    DB::table('quantification_scenarios')
                        ->where('id', $row->id)
                        ->update([
                            'severity_mu' => round($mu + ($direction * $correction), 6),
                            'updated_at' => now(),
                        ]);

                    $adjusted++;
                }
            });

        info($message, [
            'scenarios_adjusted' => $adjusted,
            'scenarios_left_untouched' => $skipped,
        ]);
    }
};
