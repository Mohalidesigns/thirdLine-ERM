<?php

namespace App\Exceptions\Tprm;

use App\Services\Tprm\Contracts\ActivationVerdict;
use RuntimeException;

/**
 * Raised when an engagement is activated while its contract is missing a
 * clause that is a condition of activation — FR-CTR-05, AC-06.
 *
 * Thrown on the transition path rather than returned as a boolean, for the
 * same reason `ProhibitedOutsourcingException` is: the transition has several
 * callers — a controller, the importer, a future API — and a return value can
 * be ignored by any of them. An exception cannot.
 *
 * Carries the verdict so the error panel can render a row per clause with its
 * citation, its guidance and a waiver action. A block with no route forward is
 * a block a user routes around.
 */
class BlockingClauseException extends RuntimeException
{
    public function __construct(public readonly ActivationVerdict $verdict)
    {
        parent::__construct((string) $verdict->reason);
    }

    /** @return list<array<string, mixed>> */
    public function details(): array
    {
        return $this->verdict->blockingClauses;
    }
}
