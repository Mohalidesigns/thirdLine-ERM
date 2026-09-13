<?php

namespace Tests\Feature\Migration;

use App\Support\Periods\PeriodContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Phase 7.4 — every parameterless GET screen answers.
 *
 * WHY THIS EXISTS. `scripts/parity-check.php` asks whether any test names each
 * route, and the answer for 33 of them was no. Twenty-four take no parameters,
 * and fourteen of those are EXPORTS — including `cbn-orms`, `basel` and `nfiu`,
 * which are the loss-event returns a Nigerian bank files with its regulator.
 * They had never been requested by anything but a human.
 *
 * WHAT IT PROVES, AND WHAT IT DOES NOT. This is a smoke test: it asserts the
 * route resolves, authorises, queries and renders without a server error, for
 * an entitled user, on a tenant with no data. It says nothing about whether the
 * figures are right — that is what the module tests are for.
 *
 * The empty tenant is the point rather than a shortcut. Every recurring defect
 * this migration has found lives at the boundary where a register is empty: a
 * rate over no rows returning 0, a division by a count of nothing, a `first()`
 * that is null and then dereferenced. A screen that only works once somebody
 * has entered data is a screen that breaks on a customer's first day.
 */
class RouteParitySmokeTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    /**
     * Routes a smoke test cannot meaningfully request, and why.
     *
     * Each one ENDS the session or leaves the application somewhere the next
     * assertion cannot follow. They are covered by their own tests.
     */
    private const EXCLUDED = [
        'logout',
        'login',
        'password.request',
        'mfa.verify',
        'mfa.setup',
        'auth.sso.discover',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
    }

    protected function tearDown(): void
    {
        PeriodContext::use(null);
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function every_parameterless_get_screen_answers_without_a_server_error(): void
    {
        // One test rather than a data provider: a provider runs BEFORE the
        // application is bootstrapped, so enumerating routes there means
        // booting a second instance — which starts a second transaction inside
        // RefreshDatabase's and fails every case with "cannot start a
        // transaction within a transaction".
        $failures = [];
        $checked = 0;

        foreach ($this->parameterlessGetRoutes() as $name) {
            $checked++;
            $status = $this->actingAs($this->actor)->get(route($name))->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$name} → {$status} at ".route($name);
            }
        }

        $this->assertGreaterThan(100, $checked, 'The route enumeration found almost nothing; the filter is wrong.');

        $this->assertSame(
            [],
            $failures,
            count($failures)." screen(s) returned a server error on an EMPTY tenant.\n"
            ."A screen that only works once somebody has entered data breaks on a customer's\n"
            ."first day:\n  ".implode("\n  ", $failures)
        );
    }

    /**
     * Every named GET route in the web group that takes no parameters.
     *
     * Derived from the router rather than listed, so a route added without a
     * test is caught the moment it exists.
     *
     * @return list<string>
     */
    private function parameterlessGetRoutes(): array
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null
                || ! in_array('GET', $route->methods(), true)
                || ! in_array('web', $route->gatherMiddleware(), true)
                || str_contains($route->uri(), '{')
                || in_array($name, self::EXCLUDED, true)) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }
}
