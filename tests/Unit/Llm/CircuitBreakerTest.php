<?php

namespace Tests\Unit\Llm;

use App\Services\Llm\CircuitBreaker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0015 §6 — per-profile breaker semantics, and contract §8.6-§8.7.
 */
class CircuitBreakerTest extends TestCase
{
    #[Test]
    public function it_is_closed_by_default(): void
    {
        $breaker = new CircuitBreaker;

        $this->assertSame('closed', $breaker->state('local-ollama'));
        $this->assertTrue($breaker->allows('local-ollama'));
    }

    #[Test]
    public function three_consecutive_failures_open_it_and_a_fourth_call_is_refused(): void
    {
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');
        $this->assertSame('closed', $breaker->state('local-ollama'), 'Opened before the third failure.');

        $breaker->recordFailure('local-ollama');

        $this->assertSame('open', $breaker->state('local-ollama'));
        $this->assertFalse($breaker->allows('local-ollama'), 'A fourth call must be refused without a network request.');
    }

    #[Test]
    public function after_open_seconds_exactly_one_probe_is_admitted(): void
    {
        config()->set('llm.breaker.open_seconds', 60);
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');
        $this->assertFalse($breaker->allows('local-ollama'));

        $this->travel(61)->seconds();

        $this->assertTrue($breaker->allows('local-ollama'), 'No probe was admitted after the open window elapsed.');
        // The probe is admitted exactly once: a second check before the
        // first is resolved must not admit a concurrent probe.
        $this->assertFalse($breaker->allows('local-ollama'));
    }

    #[Test]
    public function a_successful_probe_closes_the_breaker(): void
    {
        config()->set('llm.breaker.open_seconds', 60);
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');

        $this->travel(61)->seconds();
        $this->assertTrue($breaker->allows('local-ollama'));

        $breaker->recordSuccess('local-ollama');

        $this->assertSame('closed', $breaker->state('local-ollama'));
        $this->assertSame(0, $breaker->consecutiveFailures('local-ollama'));
        $this->assertTrue($breaker->allows('local-ollama'));
    }

    #[Test]
    public function a_failed_probe_reopens_for_another_window(): void
    {
        config()->set('llm.breaker.open_seconds', 60);
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');

        $this->travel(61)->seconds();
        $this->assertTrue($breaker->allows('local-ollama'));

        $breaker->recordFailure('local-ollama');

        $this->assertSame('open', $breaker->state('local-ollama'));
        $this->assertFalse($breaker->allows('local-ollama'));
    }

    #[Test]
    public function the_breaker_is_keyed_per_profile_not_globally(): void
    {
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('profile-a');
        $breaker->recordFailure('profile-a');
        $breaker->recordFailure('profile-a');

        $this->assertSame('open', $breaker->state('profile-a'));
        $this->assertSame('closed', $breaker->state('profile-b'), 'One profile tripping must not affect another.');
    }

    #[Test]
    public function reading_state_or_opens_at_never_mutates_the_breaker(): void
    {
        config()->set('llm.breaker.open_seconds', 60);
        $breaker = new CircuitBreaker;

        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');
        $breaker->recordFailure('local-ollama');

        $this->travel(61)->seconds();

        // Reading state()/opensAt() many times before ever calling allows()
        // must not itself consume the one admitted probe.
        for ($i = 0; $i < 5; $i++) {
            $breaker->state('local-ollama');
            $breaker->opensAt('local-ollama');
        }

        $this->assertSame('open', $breaker->state('local-ollama'));
        $this->assertTrue($breaker->allows('local-ollama'), 'A pure read consumed the probe before allows() was ever called.');
    }

    #[Test]
    public function opens_at_is_null_while_closed(): void
    {
        $breaker = new CircuitBreaker;

        $this->assertNull($breaker->opensAt('local-ollama'));
    }
}
