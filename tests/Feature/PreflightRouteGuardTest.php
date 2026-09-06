<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WP-12 — preflight's route-authorization check must be both correct and
 * still capable of failing.
 *
 * It matched two literal alias prefixes (`permission:`, `can:`) and so
 * reported every WP-07 api/v1 route as unguarded, even though each carries
 * `scope:` or `scope.resource`. The check sat red through three waves of
 * work. A permanently-failing gate teaches people to skip the gate, so the
 * fix is only worth anything if the check can still go red for a real reason
 * — which is what the second test here is for.
 */
class PreflightRouteGuardTest extends TestCase
{
    #[Test]
    public function preflight_reports_no_unguarded_routes(): void
    {
        $this->artisan('app:preflight')
            ->expectsOutputToContain('every web/api route carries a permission guard');
    }

    /**
     * The check must still catch a route that really is unguarded. Without
     * this, "PASS" could mean the matcher has been loosened until it accepts
     * anything, which is the failure mode of the original bug in reverse.
     */
    #[Test]
    public function preflight_still_catches_a_genuinely_unguarded_route(): void
    {
        Route::middleware(['web'])->get('wp12-unguarded-probe', fn () => 'ok');

        $this->artisan('app:preflight')
            ->expectsOutputToContain('wp12-unguarded-probe');
    }

    /**
     * This pinned what authorized Livewire's two framework endpoints, which sat
     * on preflight's allowlist without permissions of their own — `upload-file`
     * being an anonymous write into livewire-tmp behind nothing but a URL
     * signature until WP-12 put `auth` on top of it.
     *
     * Migration Phase 6.8 uninstalled livewire/livewire. The endpoints are not
     * merely guarded now, they are not registered, so the assertion moves from
     * "this route is authorized" to "there is no such route, and nothing is
     * being excused on its behalf" — the allowlist entries went with them.
     * Deleting this test instead would have left the entries unexamined.
     */
    #[Test]
    public function no_livewire_endpoint_survives_and_nothing_is_allowlisted_for_one(): void
    {
        $livewireRoutes = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'livewire/'))
            ->map(fn ($r) => $r->uri())
            ->values()
            ->all();

        $this->assertSame([], $livewireRoutes, 'A livewire/* route is registered after the package was removed.');

        $this->post('/livewire/upload-file')->assertNotFound();

        // And preflight no longer carries an excuse for a route that cannot
        // exist — a stale allowlist name would silently start excusing whatever
        // later claimed that URI.
        $source = file_get_contents(base_path('app/Console/Commands/Preflight.php'));

        $this->assertStringNotContainsString(
            "'livewire/upload-file'",
            $source,
            "preflight still allowlists a route that no longer exists. An allowlist entry's "
            .'whole value is that it names something real.'
        );
    }

    /**
     * The api/v1 surface is the specific thing that was misreported. Pin the
     * shape of its guards so a future refactor that drops them is a test
     * failure and not a silently-still-green preflight.
     */
    #[Test]
    public function every_api_v1_route_carries_a_scope_guard(): void
    {
        $unscoped = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            $hasScope = collect($route->gatherMiddleware())->contains(
                fn ($m) => is_string($m) && (str_starts_with($m, 'scope:') || $m === 'scope.resource')
            );

            if (! $hasScope) {
                $unscoped[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $unscoped, 'api/v1 routes with no scope guard: '.implode(', ', $unscoped));
    }
}
