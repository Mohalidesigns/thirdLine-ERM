<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The MFA flow must be UNREACHABLE while features.mfa_totp is off, and must
 * still exist when it is on.
 *
 * WHY THIS TEST EXISTS. The implementation behind these routes is broken in
 * three specific ways, and the product owner has deferred the rebuild:
 *
 *   1. Sign-in cannot complete. AuthController::login() calls Auth::logout()
 *      before redirecting to mfa.verify, and verifyMfa() sets
 *      session('mfa_verified') without ever calling Auth::login() again.
 *   2. The codes are not RFC 6238 TOTP — verifyTotpCode() packs the time step
 *      into four bytes where the specification requires eight.
 *   3. The enrolment screen fetched its QR code from api.qrserver.com, handing
 *      the TOTP shared secret and the user's email to a third party.
 *
 * So the live risk was not theoretical: a user who self-enrolled locked
 * themselves out permanently, and the setup screen leaked the seed the moment
 * it rendered. The flag closes both. This class asserts the flag actually
 * closes them — on all four ways in, not just the routes — and its final case
 * asserts the gate itself has not rotted, so that turning the flag on after the
 * rebuild does not find a 404 that nobody noticed.
 *
 * MfaEnforcementTest is the counterpart: it runs with the flag ON and asserts
 * the enforcement behaviour that must survive the rebuild.
 */
class MfaFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // The default. Asserted rather than assumed: if config/features.php
        // ever ships with this on, every other assertion in this class would
        // pass for the wrong reason.
        $this->assertFalse(
            (bool) config('features.mfa_totp'),
            'features.mfa_totp must default to false — the MFA implementation is broken (see config/features.php).'
        );

        // routes/web.php throttles the auth surface. The limiter is backed by
        // the array cache store, which persists for the whole process, so a
        // class that posts to /login repeatedly would otherwise start seeing
        // 429s that have nothing to do with what it is testing.
        cache()->flush();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Flag OFF — every way in is closed */
    /* ================================================================== */

    public static function mfaRoutes(): array
    {
        return [
            'enrolment screen' => ['get', 'mfa/setup'],
            'enrolment confirmation' => ['post', 'mfa/enable'],
            'verification screen' => ['get', 'mfa/verify'],
            'verification submission' => ['post', 'mfa/verify'],
        ];
    }

    #[Test]
    #[DataProvider('mfaRoutes')]
    public function the_mfa_routes_are_not_reachable_with_the_flag_off(string $method, string $uri): void
    {
        // 404 rather than 403 — the house convention for a disabled feature
        // (see EnsureFeatureEnabled). A disabled surface should be
        // indistinguishable from one that does not exist.
        $this->actingAs($this->user())
            ->call($method, '/'.$uri)
            ->assertNotFound();
    }

    #[Test]
    public function an_unauthenticated_caller_also_gets_a_404_rather_than_a_login_redirect(): void
    {
        // mfa/verify is a PUBLIC route — it is reached mid-sign-in, before the
        // session is authenticated — so it needs checking without an actor too.
        $this->get('/mfa/verify')->assertNotFound();
        $this->post('/mfa/verify', ['code' => '123456'])->assertNotFound();
    }

    #[Test]
    public function an_enrolled_user_is_not_redirected_into_verification(): void
    {
        // The lockout that mattered. Before the gate, this user was redirected
        // to mfa.verify on every request and could never complete it.
        $user = $this->user();
        $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->saveQuietly();

        $this->actingAs($user)->get('/risk/register')->assertOk();
    }

    #[Test]
    public function a_user_whose_role_requires_mfa_is_not_redirected_into_enrolment(): void
    {
        // The second way in: organizations.settings->mfa_required_roles, set by
        // no seeder or migration today but settable by a tenant.
        $user = $this->user(requiredRoles: ['risk-manager']);

        $this->actingAs($user)->get('/risk/register')->assertOk();
    }

    #[Test]
    public function an_enrolled_user_who_is_also_required_to_enrol_can_still_sign_in(): void
    {
        // Both conditions at once, which is the state a tenant that turned the
        // requirement on would actually be in.
        $user = $this->user(requiredRoles: ['risk-manager']);
        $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->saveQuietly();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/risk/dashboard');

        // The whole point. Previously login() put the user id in the session,
        // called Auth::logout() and sent them to a screen that could never log
        // them back in.
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_json_request_from_a_required_user_is_not_refused_either(): void
    {
        // EnsureMfaVerified answers a JSON request with 403 rather than a
        // redirect, so the gate has to cover that branch as well.
        $user = $this->user(requiredRoles: ['risk-manager']);

        $this->actingAs($user)->getJson('/risk/register')->assertOk();
    }

    #[Test]
    public function the_enrolment_entry_point_does_not_render_in_the_user_menu(): void
    {
        // The link in the topbar user menu is how a user self-enrolled and
        // locked themselves out. A 404 behind a visible link is still a
        // support ticket, so the link goes too.
        $this->actingAs($this->user())
            ->get('/risk/register')
            ->assertOk()
            ->assertDontSee('2FA Setup')
            ->assertDontSee(route('mfa.setup'));
    }

    #[Test]
    public function the_enrolment_screen_never_sends_the_shared_secret_to_a_third_party(): void
    {
        // Defence in depth, and the reason the QR <img> was removed from
        // resources/views/auth/mfa-setup.blade.php rather than left to the
        // route gate alone: whoever turns this flag on after the rebuild must
        // not silently reinstate the disclosure. Asserted against the rendered
        // template so it fails if the tag comes back.
        config(['features.mfa_totp' => true]);

        $response = $this->actingAs($this->user())->get('/mfa/setup')->assertOk();

        $this->assertStringNotContainsString('api.qrserver.com', $response->getContent());
        $this->assertStringNotContainsString('create-qr-code', $response->getContent());

        // The manual-entry key is what enrolment falls back to, so the screen
        // still has to carry the secret it was given. The screen is an Inertia
        // page as of migration Phase 1, so the secret is a prop rather than
        // Blade text; MfaSetupTest covers the rest of the rebuilt flow.
        $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Auth/MfaSetup')
            ->has('secret'));
    }

    /* ================================================================== */
    /*  Flag ON — the gate has not rotted */
    /* ================================================================== */

    #[Test]
    public function the_routes_exist_when_the_flag_is_on(): void
    {
        // Without this, the gate could be quietly replaced by a deletion and
        // nothing would fail until somebody tried to turn MFA on.
        config(['features.mfa_totp' => true]);

        $this->actingAs($this->user())->get('/mfa/setup')->assertOk();

        // mfa/verify redirects to /login without a pending sign-in, which is
        // its own behaviour — what matters here is that it is not a 404.
        $this->get('/mfa/verify')->assertRedirect('/login');

        // POST mfa/verify carries `throttle:mfa-verify`, whose key reads
        // mfa_pending_user_id out of the session. Exercised here because a
        // limiter that touches the session only works if StartSession runs
        // first, and that ordering comes from the framework's middleware
        // priority list rather than from anything in this repository.
        $pending = $this->user();
        $pending->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->saveQuietly();

        $this->withSession(['mfa_pending_user_id' => $pending->id])
            ->from('/mfa/verify')
            ->post('/mfa/verify', ['code' => '000000'])
            ->assertRedirect('/mfa/verify');
    }

    #[Test]
    public function the_enrolment_entry_point_returns_when_the_flag_is_on(): void
    {
        config(['features.mfa_totp' => true]);

        // The React shell shows "2FA Setup" in the user menu when the shared
        // `features.mfa_totp` prop is on (migration Phase 2: /risk/register is
        // an Inertia page, so the menu is no longer server-rendered text).
        $this->actingAs($this->user())
            ->get('/risk/register')
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('features.mfa_totp', true));
    }

    /* ================================================================== */

    /**
     * @param  list<string>  $requiredRoles  organizations.settings->mfa_required_roles
     */
    private function user(array $requiredRoles = [], string $role = 'risk-manager'): User
    {
        $this->org ??= Organization::create([
            'name' => 'Gate Bank PLC',
            'short_name' => 'GATE',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
            'settings' => ['mfa_required_roles' => $requiredRoles],
        ]);

        $this->org->forceFill(['settings' => ['mfa_required_roles' => $requiredRoles]])->save();

        TenantContext::set($this->org->id);

        $user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Gate User',
            'email' => 'gate+'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
