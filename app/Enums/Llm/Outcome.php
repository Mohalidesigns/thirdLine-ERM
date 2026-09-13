<?php

namespace App\Enums\Llm;

/**
 * What a single gateway call ended in — phase-11a-ai-contract.md §2.1.
 *
 * PLATFORM-LEVEL AND MODULE-BLIND ON PURPOSE. Every module's call, successful
 * or refused, lands on exactly one of these eight values, which is what makes
 * `llm_usage_events` a single queryable ledger rather than one dialect per
 * module. `Refused` alone covers five different layers (deployment LLM off,
 * deployment module off, tenant master off, deployment service off, tenant
 * service off) — the LAYER that refused is not part of the outcome, it is
 * carried separately by `Availability::$layer` for the screen that needs to
 * say which switch to go change. Folding the layer into the enum would give
 * every module its own private vocabulary of "why", which is the exact
 * fragmentation ADR 0015 exists to end.
 */
enum Outcome: string
{
    case Succeeded = 'succeeded';
    case Refused = 'refused';
    case CircuitOpen = 'circuit_open';
    case CapExceeded = 'cap_exceeded';
    case Unreachable = 'unreachable';
    case Timeout = 'timeout';
    case HttpError = 'http_error';
    case Unparsable = 'unparsable';

    public function isSuccess(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Whether this outcome counts as a transport FAILURE for the circuit
     * breaker (ADR 0015 §6: timeouts and connection failures count; an
     * unparseable 200 does not — the box answered, the model just gave an
     * answer nobody could use).
     */
    public function countsAsBreakerFailure(): bool
    {
        return in_array($this, [self::Unreachable, self::Timeout, self::HttpError], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Succeeded',
            self::Refused => 'Refused',
            self::CircuitOpen => 'Circuit open',
            self::CapExceeded => 'Monthly limit reached',
            self::Unreachable => 'Endpoint unreachable',
            self::Timeout => 'Timed out',
            self::HttpError => 'Server error',
            self::Unparsable => 'Response unreadable',
        };
    }
}
