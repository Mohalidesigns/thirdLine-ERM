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
     * The Livewire upload endpoint is on preflight's allowlist because
     * something else authorizes it. Pin what that something is, or the
     * allowlist entry becomes a permanent excuse.
     */
    #[Test]
    public function the_livewire_upload_endpoint_requires_a_session(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === 'livewire/upload-file');

        $this->assertNotNull($route, 'Livewire is no longer registering its upload route.');

        $this->assertContains(
            'auth',
            $route->gatherMiddleware(),
            "POST livewire/upload-file has lost its auth middleware. Livewire's default for "
            .'temporary_file_upload.middleware is throttle only, which leaves an anonymous '
            .'write into livewire-tmp behind nothing but a URL signature.'
        );

        $this->post('/livewire/upload-file')->assertRedirect('/login');
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
