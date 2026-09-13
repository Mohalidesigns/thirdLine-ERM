<?php

namespace App\Services\Tprm;

use App\Models\Tprm\Engagement;
use App\Services\Tprm\Scoring\TieringOutcome;
use Illuminate\Support\Collection;

/**
 * What a submitted intake produced: the engagement, its tier derivation, and
 * any existing engagement that looks like the same service.
 */
class IntakeResult
{
    /**
     * @param  Collection<int, Engagement>  $similarEngagements
     */
    public function __construct(
        public readonly Engagement $engagement,
        public readonly TieringOutcome $outcome,
        public readonly Collection $similarEngagements,
    ) {}
}
