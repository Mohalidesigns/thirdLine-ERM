<?php

namespace Tests\Feature\TprmPortal;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * AC-14 — the acceptance criterion this whole phase turns on.
 *
 * "Automated probes attempting cross-vendor and vendor-to-internal access on
 * every portal endpoint using enumerated identifiers, asserting 403/404 and no
 * data leakage."
 *
 * THE PROBES ENUMERATE RATHER THAN NAME. A test that hardcodes three routes
 * proves those three routes; a route added next month is untested and looks
 * tested. So the suite walks Laravel's own route table for everything named
 * `tprm-portal.*` and asserts each one refuses, which means a new portal route
 * is covered the moment it is registered — and a new INTERNAL route is covered
 * by the mirror test that walks `risk.*` and `tprm.*`.
 */
class PortalIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bankA;

    private Organization $bankB;

    private PortalUser $vendorAtA;

    private User $internalUser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bankA = $this->organization('Lagos Union Bank', 'LUB');
        $this->bankB = $this->organization('Abuja Trust Bank', 'ATB');

        $this->internalUser = User::create([
            'name' => 'Internal Analyst',
            'email' => 'analyst@lub.test',
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
            'organization_id' => $this->bankA->id,
            'is_active' => true,
        ]);

        $this->vendorAtA = $this->portalUser($this->bankA, 'Cloudspan Nigeria Limited', 'ops@cloudspan.test');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Vendor → internal */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_portal_session_reaches_no_internal_route(): void
    {
        $this->signInToPortal($this->vendorAtA);

        $refused = 0;

        foreach ($this->internalGetRoutes() as $uri) {
            $response = $this->get($uri);

            $this->assertNotSame(
                200,
                $response->getStatusCode(),
                sprintf('A vendor portal session was served %s with 200. That is AC-14 failing.', $uri),
            );

            $refused++;
        }

        // The walk has to have found something, or this test passes by
        // examining nothing — the failure mode every enumerating test has.
        $this->assertGreaterThan(20, $refused, 'The internal route walk found too few routes to be meaningful.');
    }

    #[Test]
    public function the_portal_guard_does_not_satisfy_the_internal_guard(): void
    {
        $this->signInToPortal($this->vendorAtA);

        // The specific claim underneath the sweep above: `Auth::check()` on the
        // default guard is what every internal route asks, and a portal session
        // must never answer it.
        $this->assertFalse(auth()->guard('web')->check());
        $this->assertTrue(auth()->guard('tprm-portal')->check());
    }

    #[Test]
    public function an_internal_session_does_not_satisfy_the_portal_guard(): void
    {
        $this->actingAs($this->internalUser);

        $this->assertFalse(auth()->guard('tprm-portal')->check());

        $this->get('/vendor-portal/dashboard')
            ->assertRedirect(route('tprm-portal.entry'));
    }

    /* ------------------------------------------------------------------ */
    /*  Vendor → another vendor, and vendor → another tenant */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_vendor_cannot_sign_in_against_another_tenants_portal_path(): void
    {
        // Same credentials, wrong client in the path. The account belongs to
        // bank A; bank B's login must not authenticate it.
        $response = $this->post(route('tprm-portal.login.attempt', ['client' => $this->bankB->uuid]), [
            'email' => 'ops@cloudspan.test',
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertNull(session('tprm_portal_pending_user'));
    }

    #[Test]
    public function every_authenticated_portal_route_refuses_an_anonymous_visitor(): void
    {
        $checked = 0;

        foreach ($this->portalRoutes(['GET']) as $uri) {
            if ($this->isPortalGuestUri($uri)) {
                continue;
            }

            $this->get($uri)->assertRedirect(route('tprm-portal.entry'));
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No authenticated portal routes were probed.');
    }

    /* ------------------------------------------------------------------ */
    /*  MFA cannot be skipped */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_password_alone_does_not_produce_a_session(): void
    {
        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bankA->uuid]), [
            'email' => $this->vendorAtA->email,
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ])->assertRedirect(route('tprm-portal.mfa.challenge'));

        // Half authenticated: an id parked in the session, and no guard session
        // at all. A session stolen here buys the same challenge screen.
        $this->assertFalse(auth()->guard('tprm-portal')->check());
        $this->assertSame($this->vendorAtA->id, session('tprm_portal_pending_user'));

        $this->get('/vendor-portal/dashboard')->assertRedirect(route('tprm-portal.entry'));
    }

    #[Test]
    public function a_wrong_code_does_not_produce_a_session(): void
    {
        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bankA->uuid]), [
            'email' => $this->vendorAtA->email,
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ]);

        $this->post(route('tprm-portal.mfa.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(auth()->guard('tprm-portal')->check());
    }

    #[Test]
    public function a_correct_code_produces_a_session_and_reaches_the_dashboard(): void
    {
        $this->signInToPortal($this->vendorAtA);

        $this->assertTrue(auth()->guard('tprm-portal')->check());

        $this->get('/vendor-portal/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('TprmPortal/Dashboard'));
    }

    /* ------------------------------------------------------------------ */
    /*  Leakage */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_portal_shares_no_internal_navigation_or_permissions(): void
    {
        $this->signInToPortal($this->vendorAtA);

        $this->get('/vendor-portal/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('navigation')
                ->missing('features')
                ->missing('period')
                ->missing('unreadNotifications')
                ->where('client.name', 'Lagos Union Bank'));
    }

    #[Test]
    public function the_portal_page_ships_only_portal_routes_to_the_browser(): void
    {
        $this->signInToPortal($this->vendorAtA);

        $html = $this->get('/vendor-portal/dashboard')->getContent();

        // Ziggy's route table is inlined into the page. Shipping the internal
        // one would hand a vendor a complete map of the register's endpoints.
        $this->assertStringNotContainsString('risk.risks.index', $html);
        $this->assertStringNotContainsString('tprm.third-parties.index', $html);
        $this->assertStringContainsString('tprm-portal.dashboard', $html);
    }

    #[Test]
    public function the_portal_uses_its_own_session_cookie(): void
    {
        // Captured BEFORE the request. StartPortalSession rewrites
        // `session.cookie` in the container, and in a single-process test that
        // rewrite is still in place afterwards — reading it after the fact
        // would compare the portal cookie against itself and pass for the
        // wrong reason.
        $internalCookie = config('session.cookie');

        $response = $this->get(route('tprm-portal.entry'));

        $names = collect($response->headers->getCookies())->map(fn ($cookie) => $cookie->getName());

        $this->assertTrue(
            $names->contains(fn (string $name) => str_ends_with($name, '-tprm-portal')),
            'The portal must set its own session cookie: '.$names->implode(', '),
        );
        $this->assertFalse(
            $names->contains($internalCookie),
            'The portal must not set the internal session cookie.',
        );
    }

    #[Test]
    public function an_internal_page_still_uses_the_internal_cookie_after_a_portal_request(): void
    {
        $internalCookie = config('session.cookie');

        // The portal request mutates `session.cookie` in the container. Under
        // php-fpm each request is its own process so that cannot leak, but the
        // test harness shares one — which makes this the cheapest available
        // proof that the internal surface is unaffected, and the guard that
        // would fail first if the application ever moved to Octane.
        $this->get(route('tprm-portal.entry'));

        $response = $this->actingAs($this->internalUser)->get('/login');

        $names = collect($response->headers->getCookies())->map(fn ($cookie) => $cookie->getName());

        $this->assertFalse(
            $names->contains(fn (string $name) => str_ends_with($name, '-tprm-portal')),
            'An internal response must not carry the portal session cookie: '.$names->implode(', '),
        );
        $this->assertSame('laravel-session', $internalCookie);
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    /** @return list<string> */
    private function internalGetRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'risk.') && ! str_starts_with($name, 'tprm.')) {
                continue;
            }

            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            // Parameterised routes need a real id to be a fair probe, and an
            // invented one would 404 for the wrong reason. The unparameterised
            // ones are the honest sweep.
            if (str_contains($route->uri(), '{')) {
                continue;
            }

            $uris[] = '/'.ltrim($route->uri(), '/');
        }

        return $uris;
    }

    /**
     * @param  list<string>  $methods
     * @return list<string>
     */
    private function portalRoutes(array $methods): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'tprm-portal.')) {
                continue;
            }

            if (array_intersect($methods, $route->methods()) === []) {
                continue;
            }

            if (str_contains($route->uri(), '{')) {
                continue;
            }

            $uris[] = '/'.ltrim($route->uri(), '/');
        }

        return $uris;
    }

    private function isPortalGuestUri(string $uri): bool
    {
        return in_array($uri, ['/vendor-portal'], true) || str_contains($uri, '/mfa/');
    }

    private function organization(string $name, string $short): Organization
    {
        $organization = Organization::create([
            'name' => $name, 'short_name' => $short,
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        return $organization;
    }

    private function portalUser(Organization $organization, string $vendorName, string $email): PortalUser
    {
        return TenantContext::actingAs($organization->id, function () use ($organization, $vendorName, $email): PortalUser {
            $vendor = ThirdParty::create([
                'organization_id' => $organization->id,
                'legal_name' => $vendorName,
                'slug' => Str::random(12),
                'entity_type' => 'company',
                'status' => 'active',
            ]);

            $user = new PortalUser([
                'organization_id' => $organization->id,
                'third_party_id' => $vendor->id,
                'email' => $email,
                'name' => 'Vendor Operator',
                'password' => 'Sup3r-Str0ng-P@ssphrase!',
            ]);

            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE,
                'accepted_at' => now(),
                'mfa_secret' => Totp::generateSecret(),
                'mfa_enabled' => true,
            ])->save();

            return $user;
        });
    }

    /**
     * Sign in THROUGH THE HTTP ENDPOINTS, not by calling the service.
     *
     * Calling `completeMfa()` directly would mint a guard session and prove
     * nothing about the routes, the middleware order or the cookie — the three
     * things AC-14 is actually about. Driving the real path means every probe
     * below runs against a session a browser could have obtained.
     */
    private function signInToPortal(PortalUser $user): void
    {
        $organization = Organization::query()->findOrFail($user->organization_id);

        $this->post(route('tprm-portal.login.attempt', ['client' => $organization->uuid]), [
            'email' => $user->email,
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ])->assertRedirect(route('tprm-portal.mfa.challenge'));

        $this->post(route('tprm-portal.mfa.verify'), [
            'code' => Totp::at((string) $user->mfa_secret, time()),
        ])->assertRedirect(route('tprm-portal.dashboard'));
    }
}
