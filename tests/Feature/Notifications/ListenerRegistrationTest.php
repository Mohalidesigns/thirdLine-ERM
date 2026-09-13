<?php

namespace Tests\Feature\Notifications;

use App\Events\ControlUpdated;
use App\Providers\EventServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * WP-13 — every listener is registered exactly once.
 *
 * Laravel registers its own base EventServiceProvider, which scans
 * app/Listeners and binds every listener whose handle() is typed against a
 * concrete event. This application's EventServiceProvider binds the same
 * listeners through $listen. Both were active, so seven listeners ran twice on
 * every dispatch: residual risk recomputed twice, assessment measures written
 * twice, and — the expensive one — every loss event evaluated against the
 * regulatory thresholds twice, which on a breach is a duplicated filing.
 *
 * Nothing failed visibly because most of those listeners are close enough to
 * idempotent to hide it. This test does not care about idempotency; it asserts
 * the registration itself, because the next listener added may not be.
 */
class ListenerRegistrationTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /**
     * The general guard: no listener class appears twice for one event.
     *
     * Discovery registers `App\Listeners\X@handle` where $listen registers
     * `App\Listeners\X`, so the duplicate is only visible once the suffix is
     * normalised away — which is exactly why it survived a reading of
     * `event:list`.
     */
    #[Test]
    public function no_listener_is_registered_twice_for_the_same_event(): void
    {
        $duplicates = [];

        foreach (array_keys((new EventServiceProvider($this->app))->listens()) as $event) {
            $seen = [];

            foreach (Event::getListeners($event) as $listener) {
                $name = $this->nameOf($listener);

                if ($name === null) {
                    continue;
                }

                $seen[$name] = ($seen[$name] ?? 0) + 1;
            }

            foreach ($seen as $name => $count) {
                if ($count > 1) {
                    $duplicates[] = class_basename($event).' => '.class_basename($name).' ×'.$count;
                }
            }
        }

        $this->assertSame([], $duplicates, 'Listeners registered more than once: '.implode(', ', $duplicates));
    }

    /**
     * The specific mechanism, pinned separately so a future
     * `->withEvents(discover: true)` in bootstrap/app.php fails here with a
     * message that names the cause rather than just a doubled count.
     */
    #[Test]
    public function automatic_listener_discovery_is_disabled(): void
    {
        $this->assertFalse(
            (new \Illuminate\Foundation\Support\Providers\EventServiceProvider($this->app))->shouldDiscoverEvents(),
            'Event discovery is back on. It duplicates every listener already declared in EventServiceProvider::$listen.'
        );
    }

    /** And the behaviour it protects: one dispatch, one run. */
    #[Test]
    public function a_dispatched_event_runs_its_listener_once(): void
    {
        $this->bootDomainFixtures();

        $runs = 0;
        Event::listen(ControlUpdated::class, function () use (&$runs) {
            $runs++;
        });

        ControlUpdated::dispatch($this->makeControl(), []);

        $this->assertSame(1, $runs);
    }

    private function nameOf(mixed $listener): ?string
    {
        if (! $listener instanceof \Closure) {
            return null;
        }

        // Laravel wraps every class listener in a closure. The class name is
        // recoverable from the bound variables of that wrapper.
        $bound = (new \ReflectionFunction($listener))->getStaticVariables();

        $candidate = $bound['listener'] ?? null;

        if (! is_string($candidate)) {
            return null;
        }

        return str_contains($candidate, '@') ? explode('@', $candidate)[0] : $candidate;
    }
}
