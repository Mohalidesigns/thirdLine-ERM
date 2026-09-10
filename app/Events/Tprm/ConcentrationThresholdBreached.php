<?php

namespace App\Events\Tprm;

use App\Models\Tprm\ConcentrationAnalysis;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A concentration run crossed a configured threshold — FR-NTH-05.
 *
 * DISPATCHED ONLY WHEN THE BREACH IS NEW. Concentration does not change
 * daily; a portfolio that breaches on Monday breaches every day until somebody
 * moves a service, and an alert every morning is an alert nobody reads by
 * Thursday. `ConcentrationService` compares against the previous run's
 * breaches and dispatches on the difference.
 *
 * `resolved` carries the other direction. A breach that clears is news too,
 * and news the board specifically asked for at the last meeting, but no
 * alerting engine will tell you about the absence of a thing.
 */
class ConcentrationThresholdBreached
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $newBreaches
     * @param  list<array<string, mixed>>  $resolved
     */
    public function __construct(
        public readonly ConcentrationAnalysis $analysis,
        public readonly array $newBreaches,
        public readonly array $resolved = [],
    ) {}
}
