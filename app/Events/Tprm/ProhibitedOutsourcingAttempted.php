<?php

namespace App\Events\Tprm;

use App\Models\Tprm\ThirdParty;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody tried to raise an intake for a function that may not be outsourced.
 *
 * AC-01 requires the attempt to be recorded, not merely refused. A refusal
 * that leaves no trace means the risk function never learns that a business
 * unit tried — and repeated attempts against the same function are a finding
 * about the institution's own understanding of its obligations, which is
 * exactly the sort of thing a supervisor asks about.
 *
 * Carries no engagement, because AC-01 requires that no engagement row exist.
 */
class ProhibitedOutsourcingAttempted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<array{function: string, code: string, citation: string}>  $functions
     * @param  array<string, mixed>  $intake
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly ?ThirdParty $thirdParty,
        public readonly array $functions,
        public readonly array $intake,
        public readonly ?User $attemptedBy = null,
    ) {}
}
