<?php

namespace Tests\Feature\Rcsa;

use App\Presenters\NavPresenter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `rcsa_v2` flag, and what a default install sees.
 *
 * The rest of the universe suite turns the flag on in its setUp, because
 * otherwise every test would assert 404. This one asserts the default — that
 * an install which has not opted in has no RCSA v2 at all — which is the claim
 * the whole "build alongside, not on top" strategy rests on.
 */
class UniverseFeatureFlagTest extends UniverseTestCase
{
    #[Test]
    public function the_flag_ships_off(): void
    {
        // Asserted against the SOURCE, not against `config('features.rcsa_v2')`
        // and not against `require config_path(...)`. Both of those resolve
        // `env()`, so on any machine whose .env turns the flag on — which is
        // every machine where somebody is working on this module — they assert
        // the developer's environment rather than what ships. The claim worth
        // holding is that the DEFAULT is false, so a deployment that sets
        // nothing gets nothing.
        $this->assertStringContainsString(
            "'rcsa_v2' => env('FEATURE_RCSA_V2', false)",
            (string) file_get_contents(config_path('features.php')),
            'features.rcsa_v2 must default to false: turning it on is a deliberate act per environment.'
        );
    }

    #[Test]
    public function the_routes_do_not_exist_when_the_flag_is_off(): void
    {
        config()->set('features.rcsa_v2', false);

        $risk = $this->makeRisk(['risk_no' => 'RETAIL-R1']);

        // 404, not 403: a disabled surface should be indistinguishable from one
        // that was never built.
        $this->actingAs($this->actor)->get(route('rcsa.universe.index'))->assertNotFound();
        $this->actingAs($this->actor)->post(route('rcsa.universe.store'), $this->riskPayload())->assertNotFound();
        $this->actingAs($this->actor)->post(route('rcsa.universe.publish'), ['ids' => [$risk->id]])->assertNotFound();
    }

    #[Test]
    public function the_navigation_hides_the_universe_until_the_flag_is_on(): void
    {
        config()->set('features.rcsa_v2', false);

        $this->assertFalse(
            $this->navigationHasUniverse(),
            'A link to a 404 is worse than no link.'
        );

        config()->set('features.rcsa_v2', true);

        $this->assertTrue($this->navigationHasUniverse());
    }

    #[Test]
    public function the_legacy_rcsa_module_is_untouched_by_the_flag(): void
    {
        config()->set('features.rcsa_v2', false);

        $this->grant(['rcsa.view']);

        // §13: build alongside, not on top. The module being replaced stays
        // live and reachable throughout, flag or no flag.
        $this->actingAs($this->actor)->get(route('risk.rcsa.dashboard'))->assertOk();

        config()->set('features.rcsa_v2', true);

        $this->actingAs($this->actor)->get(route('risk.rcsa.dashboard'))->assertOk();
    }

    /**
     * No v2 route may sit on a legacy URI.
     *
     * P6 registered its dashboard on `rcsa/dashboard`, which is the LEGACY
     * module's URI. Laravel keys its lookup table by method and URI, so the
     * later registration silently replaced the earlier one:
     * `route('risk.rcsa.dashboard')` began throwing and four legacy tests went
     * red — with nothing in the v2 code looking wrong. §13's rule is that the
     * module being replaced stays live and untouched until cutover, and a URI
     * collision is one of the quieter ways to break it.
     *
     * This asserts the rule directly rather than leaving it to whichever legacy
     * test happens to call the shadowed route next.
     */
    #[Test]
    public function no_v2_route_shadows_a_legacy_rcsa_route(): void
    {
        $legacy = [];
        $v2 = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            $key = implode('|', $route->methods()).' '.$route->uri();

            if (str_starts_with($name, 'risk.rcsa.')) {
                $legacy[$key] = $name;
            }

            if (str_starts_with($name, 'rcsa.')) {
                $v2[$key] = $name;
            }
        }

        $this->assertNotEmpty($legacy, 'The legacy RCSA routes have gone — that is a cutover, not a refactor.');

        $collisions = array_intersect_key($legacy, $v2);

        $this->assertSame(
            [],
            $collisions,
            'A v2 route is registered on a legacy RCSA URI, which removes the legacy one from the route table: '
            .json_encode($collisions),
        );
    }

    private function navigationHasUniverse(): bool
    {
        $tree = app(NavPresenter::class)->for($this->actor);

        foreach ($tree['sections'] as $section) {
            foreach ($section['items'] ?? [] as $item) {
                if (($item['route'] ?? null) === 'rcsa.universe.index') {
                    return true;
                }
            }
        }

        return false;
    }
}
