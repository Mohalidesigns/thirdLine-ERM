<?php

namespace App\Exceptions\Bcms;

use RuntimeException;

/**
 * Raised when `AudienceResolver::bySavedGroup()` cannot safely traverse a
 * saved group's stored rule.
 *
 * REFUSES THE WHOLE RESOLUTION, NOT JUST THE RE-ENTRANT BRANCH. `AudienceResolver`
 * has several callers — an EMNS dispatch composing the live recipient count, a
 * reminder ladder, a plan-acknowledgement sweep, an exercise conflict check —
 * and a resolver that silently dropped the offending branch and returned
 * whatever the rest of the rule matched would hand every one of them a
 * shorter-than-true audience with nothing to say anything was wrong. A wrong
 * count that LOOKS right is worse than an error during a crisis: the
 * coordinator sends to the number on screen and never learns it silently
 * excluded a group. An exception a caller cannot ignore is the same argument
 * `App\Exceptions\Tprm\BlockingClauseException` settles for TPRM, for the
 * same reason — several callers, one of which will forget to check a boolean.
 *
 * TWO DISTINCT CAUSES, ONE OUTCOME. A true cycle (group A includes group B
 * includes group A) is a mistake in how the groups were edited. A chain of
 * distinct groups deeper than `AudienceResolver::MAX_GROUP_HOPS` has no
 * mistake to point at — it is either a very unusual roster or a rule built to
 * exhaust the resolver — but the caller still needs to stop rather than spin.
 * Both refuse the whole resolution; the message says which happened and names
 * the groups involved rather than saying only that "something" is wrong.
 */
class CircularAudienceRuleException extends RuntimeException
{
    /** @param list<int> $path the saved-group ids already on the stack, in resolution order */
    private function __construct(public readonly array $path, string $message)
    {
        parent::__construct($message);
    }

    /** @param list<int> $path */
    public static function cycle(int $reenteredGroupId, array $path): self
    {
        return new self($path, sprintf(
            'Audience rule refers to saved group #%d, which re-enters itself through saved group(s) %s. '
            .'Edit one of these groups to break the cycle before this audience can be resolved.',
            $reenteredGroupId,
            implode(' -> ', [...$path, $reenteredGroupId]),
        ));
    }

    /** @param list<int> $path */
    public static function hopLimitExceeded(int $limit, array $path): self
    {
        return new self($path, sprintf(
            'Audience rule nests saved groups more than %d levels deep (%s). This is refused rather than '
            .'resolved, to avoid an unbounded traversal during a live dispatch. Flatten the saved groups '
            .'this rule depends on.',
            $limit,
            implode(' -> ', $path),
        ));
    }
}
