<?php

namespace Tests\Feature\Rcsa;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * RCSA-14: `/risk/rcsa` was a 404. It now lands on the module's front door —
 * the v2 cycle dashboard when `rcsa_v2` is on, the legacy dashboard when it is
 * off, so the module being replaced stays reachable until cutover.
 */
class RcsaModuleRootTest extends UniverseTestCase
{
    #[Test]
    public function it_lands_on_the_cycle_dashboard_when_v2_is_on(): void
    {
        config()->set('features.rcsa_v2', true);
        $this->grant(['rcsa_assessment.view']);

        $this->actingAs($this->actor)
            ->get('/risk/rcsa')
            ->assertRedirect(route('rcsa.dashboard.index'));
    }

    #[Test]
    public function it_lands_on_the_legacy_dashboard_when_v2_is_off(): void
    {
        config()->set('features.rcsa_v2', false);
        $this->grant(['rcsa.view']);

        $this->actingAs($this->actor)
            ->get('/risk/rcsa')
            ->assertRedirect(route('risk.rcsa.dashboard'));
    }

    #[Test]
    public function a_legacy_only_user_is_sent_to_the_legacy_dashboard_even_when_v2_is_on(): void
    {
        config()->set('features.rcsa_v2', true);

        // Holding only `rcsa.view`: the v2 dashboard would be a 403.
        $legacyOnly = $this->userWith(['rcsa.view']);

        $this->actingAs($legacyOnly)
            ->get('/risk/rcsa')
            ->assertRedirect(route('risk.rcsa.dashboard'));
    }

    #[Test]
    public function a_v2_only_user_is_refused_plainly_when_v2_is_off_and_never_sent_to_v2(): void
    {
        config()->set('features.rcsa_v2', false);

        // The flag off never targets v2, and the legacy dashboard would be a 403 for them.
        $v2Only = $this->userWith(['rcsa_assessment.view']);

        $this->actingAs($v2Only)->get('/risk/rcsa')->assertForbidden();
    }

    #[Test]
    public function a_user_holding_both_goes_to_v2_when_it_is_on_and_to_legacy_when_it_is_off(): void
    {
        $both = $this->userWith(['rcsa_assessment.view', 'rcsa.view']);

        config()->set('features.rcsa_v2', true);
        $this->actingAs($both)->get('/risk/rcsa')->assertRedirect(route('rcsa.dashboard.index'));

        config()->set('features.rcsa_v2', false);
        $this->actingAs($both)->get('/risk/rcsa')->assertRedirect(route('risk.rcsa.dashboard'));
    }

    #[Test]
    public function every_redirect_target_is_one_the_user_can_actually_open(): void
    {
        config()->set('features.rcsa_v2', true);

        $legacyOnly = $this->userWith(['rcsa.view']);

        $this->actingAs($legacyOnly)->followingRedirects()->get('/risk/rcsa')->assertOk();
    }

    #[Test]
    public function it_is_not_a_404_in_either_state_and_carries_a_permission(): void
    {
        $route = Route::getRoutes()->getByName('rcsa.landing');

        $this->assertNotNull($route);
        $this->assertSame('risk/rcsa', $route->uri());
        $this->assertContains('permission:rcsa_assessment.view|rcsa.view', $route->gatherMiddleware());
    }

    #[Test]
    public function somebody_with_neither_rcsa_permission_is_refused_and_a_guest_is_sent_to_login(): void
    {
        config()->set('features.rcsa_v2', true);

        $nobody = $this->userWith([]);

        $this->actingAs($nobody)->get('/risk/rcsa')->assertForbidden();

        auth()->logout();
        $this->get('/risk/rcsa')->assertRedirect();
    }
}
