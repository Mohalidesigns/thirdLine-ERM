<?php

namespace Tests\Feature;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A named rate limiter must be registered at BOOT, never from a routes file.
 *
 * `scripts/deploy.sh` runs `php artisan route:cache`, and a cached deployment
 * never re-executes the routes files. A `RateLimiter::for()` call inside
 * `routes/web.php` or `routes/api.php` therefore works under `artisan serve`
 * and under this suite — which loads the routes files on every boot — and is
 * silently never registered in production. Every `throttle:<name>` route then
 * answers 500 "Rate limiter [login] is not defined": login, MFA, password
 * reset, SSO discovery, licence activation, SCIM and the API, all at once.
 * Nothing in a normal test run can see it, because the normal test run is the
 * one environment where the routes files do execute.
 *
 * Three tests, each closing a gap the others leave:
 *
 *   1. no routes file calls `RateLimiter::for(` — the cause, caught directly;
 *   2. every `throttle:<name>` on a registered route resolves to a limiter —
 *      which, given (1), proves the registration does not come from a routes
 *      file;
 *   3. the same assertion, made in a fresh process that boots from a REAL
 *      `route:cache` file, so the claim in (2) is observed rather than inferred.
 *
 * Do not "fix" a red here by adding the missing name to an allow-list. Move the
 * limiter into a service provider's boot, beside the existing ones in
 * AppServiceProvider and TprmServiceProvider.
 */
class RateLimitersSurviveRouteCacheTest extends TestCase
{
    #[Test]
    public function no_routes_file_defines_a_rate_limiter(): void
    {
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('routes'), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'RateLimiter::for(')) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'RateLimiter::for() inside a routes file is never executed on a route-cached deployment, so the '
            .'limiter is undefined in production and every route throttled by it answers 500. Register it in a '
            .'service provider boot() instead (AppServiceProvider::registerAuthRateLimiters() and its siblings). '
            .'Offending file(s): '.implode(', ', $offenders)
        );
    }

    #[Test]
    public function every_named_throttle_on_a_route_resolves_to_a_registered_limiter(): void
    {
        $names = $this->namedThrottles();

        // Guard the guard: a collector that finds nothing would pass vacuously.
        // These are the surfaces whose outage this test exists to prevent.
        foreach (['login', 'mfa-verify', 'password-reset', 'sso-discover', 'license-activate', 'api-token', 'scim'] as $expected) {
            $this->assertContains($expected, $names, "Expected a route throttled by [{$expected}]; the collector is not seeing it.");
        }

        $missing = array_values(array_filter($names, fn (string $name) => RateLimiter::limiter($name) === null));

        $this->assertSame(
            [],
            $missing,
            'A route is throttled by a named limiter that nothing registers: '.implode(', ', $missing)
        );
    }

    #[Test]
    public function the_limiters_exist_when_routes_are_booted_from_a_real_route_cache(): void
    {
        $dir = sys_get_temp_dir().'/ratelim-routecache-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $cache = $dir.'/routes-v7.php';
        $probe = $dir.'/probe.php';

        // Not bootstrap/cache/routes-v7.php: writing the real cache file would
        // change the routes of every other test (and the developer's `serve`).
        // APP_ROUTES_CACHE is the framework's own switch for relocating it.
        file_put_contents($probe, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$names = [];
foreach ($app['router']->getRoutes() as $route) {
    foreach ($route->gatherMiddleware() as $m) {
        if (! is_string($m)) {
            continue;
        }
        foreach (['throttle:', Illuminate\Routing\Middleware\ThrottleRequests::class.':'] as $prefix) {
            if (str_starts_with($m, $prefix)) {
                $first = explode(',', substr($m, strlen($prefix)))[0];
                if (! is_numeric($first)) {
                    $names[$first] = true;
                }
            }
        }
    }
}

$missing = [];
foreach (array_keys($names) as $name) {
    if (Illuminate\Support\Facades\RateLimiter::limiter($name) === null) {
        $missing[] = $name;
    }
}

echo json_encode([
    'cached' => $app->routesAreCached(),
    'checked' => count($names),
    'missing' => $missing,
]);
PHP);

        try {
            $env = ['APP_ROUTES_CACHE' => $cache, 'APP_ENV' => 'testing'];

            $build = new Process([PHP_BINARY, 'artisan', 'route:cache'], base_path(), $env, null, 120);
            $build->run();
            $this->assertSame(0, $build->getExitCode(), 'route:cache failed: '.$build->getErrorOutput().$build->getOutput());
            $this->assertFileExists($cache);

            $run = new Process([PHP_BINARY, $probe], base_path(), $env, null, 120);
            $run->run();
            $this->assertSame(0, $run->getExitCode(), 'probe failed: '.$run->getErrorOutput().$run->getOutput());

            $result = json_decode(trim($run->getOutput()), true);
            $this->assertIsArray($result, 'probe printed no JSON: '.$run->getOutput());
            $this->assertTrue($result['cached'], 'The probe did not boot from the route cache, so it proves nothing.');
            $this->assertGreaterThan(0, $result['checked']);
            $this->assertSame(
                [],
                $result['missing'],
                'With routes loaded from route:cache, these throttle names have no limiter: '.implode(', ', $result['missing'])
            );
        } finally {
            @unlink($cache);
            @unlink($probe);
            @rmdir($dir);
        }
    }

    /** @return array<int, string> distinct limiter names used by `throttle:<name>` middleware */
    private function namedThrottles(): array
    {
        $names = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                foreach (['throttle:', ThrottleRequests::class.':'] as $prefix) {
                    if (! str_starts_with($middleware, $prefix)) {
                        continue;
                    }

                    // `throttle:60,1` is the numeric form: no named limiter.
                    $first = explode(',', substr($middleware, strlen($prefix)))[0];

                    if (! is_numeric($first)) {
                        $names[$first] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }
}
