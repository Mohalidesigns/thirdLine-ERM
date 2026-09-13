<?php

namespace App\Exceptions\Tprm;

use App\Services\Tprm\Access\TerminationVerdict;
use RuntimeException;

/**
 * Raised when an engagement is terminated while a connection is still open or
 * an access grant is still live — FR-ACC-04, AC-10.
 *
 * A throw rather than a `false` for the reason given on
 * `BlockingClauseException`: `transition()` already returns false to mean "the
 * lifecycle forbids that move", and conflating the two would tell somebody
 * their engagement was in the wrong status when the truth is that a vendor
 * engineer still has a production login.
 */
class OpenAccessException extends RuntimeException
{
    public function __construct(public readonly TerminationVerdict $verdict)
    {
        parent::__construct((string) $verdict->reason);
    }

    /** @return list<array<string, mixed>> */
    public function details(): array
    {
        return $this->verdict->blockers;
    }
}
