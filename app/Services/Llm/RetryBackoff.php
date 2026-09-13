<?php

namespace App\Services\Llm;

/**
 * The jittered sleep between two transport-retry attempts — ADR 0015 §6c —
 * and NOTHING ELSE. This file exists only so the RNG allowlist entry it
 * needs can be as narrow as the thing that needs it: two lines of sleep
 * arithmetic, never the file that computes `total_tokens`, `duration_ms` or
 * `unit_cost_minor` for the usage report. See
 * `tests/Feature/NoFabricatedNumbersTest.php::RNG_ALLOWLIST` and
 * `scripts/check-no-rng.sh` for the other half of that argument — a
 * file-level RNG exemption on `LlmGateway` would have covered every figure
 * that file produces, not just this one.
 *
 * JITTER IS DELIBERATE, NOT DECORATIVE (ADR 0015 §6c). A seeded generator
 * would make every queue worker jitter in lockstep — the exact stampede this
 * exists to break, since workers seeded identically compute identical
 * "random" delays. The number produced here is never shown, stored or
 * reasoned about by anyone; it only changes how long a background job
 * sleeps before its next attempt at the same call, which is why it is
 * admissible under the same rule as `PortalAuthService`'s credential (must
 * never be reproducible) rather than `MonteCarloService`'s variates (must
 * always be).
 */
class RetryBackoff
{
    /**
     * Sleep for the jittered delay of one retry attempt.
     *
     * @param  int  $baseMs  The configured delay for this attempt (`llm.retry.backoff_ms`).
     */
    public function sleep(int $baseMs): void
    {
        usleep($this->jitteredMs($baseMs) * 1000);
    }

    /**
     * The delay itself, jittered +/- 20 % — exposed separately from `sleep()`
     * so a test can assert the arithmetic without waiting on it.
     */
    public function jitteredMs(int $baseMs): int
    {
        $jitter = (int) round($baseMs * (mt_rand(-20, 20) / 100));

        return max(0, $baseMs + $jitter);
    }
}
