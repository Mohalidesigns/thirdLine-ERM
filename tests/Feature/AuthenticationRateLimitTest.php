<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The authentication surface must be rate limited, and the account lockout must
 * not be a denial-of-service primitive.
 *
 * PREVIOUS BEHAVIOUR, BOTH HALVES:
 *
 *   `grep -n throttle routes/web.php` returned nothing. POST login, POST
 *   mfa/verify, POST forgot-password and POST auth/sso/discover accepted
 *   unlimited attempts, and so did every scim/v2 route. A six-digit TOTP code
 *   with a plus/minus-one-step window and no attempt counter anywhere is
 *   walkable in minutes.
 *
 *   The lockout in AuthController::login() counted five failures per EMAIL and
 *   locked the account for thirty minutes. Anyone who knew a staff email — and
 *   on this platform email addresses appear on assessments, approvals and audit
 *   records — could keep a named Chief Risk Officer signed out indefinitely with
 *   five requests every half hour, holding no credential at all.
 *
 * The structural cases below are the ones that keep working as routes are
 * added; the behavioural ones cover what the limits actually do.
 */
class AuthenticationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        // The limiter is backed by the array cache store, which lives for the
        // whole PHP process rather than the test. Without this, one test's
        // failed logins are counted against the next one's.
        cache()->flush();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ================================================================== */
    /*  Structure — the limits stay applied as routes change */
    /* ================================================================== */

    public static function throttledRoutes(): array
    {
        return [
            'sign-in' => ['POST', 'login'],
            'password reset request' => ['POST', 'forgot-password'],
            'password reset submission' => ['POST', 'reset-password'],
            'home-realm discovery' => ['POST', 'auth/sso/discover'],
            'mfa verification' => ['POST', 'mfa/verify'],
            'mfa enrolment confirmation' => ['POST', 'mfa/enable'],
        ];
    }

    #[Test]
    #[DataProvider('throttledRoutes')]
    public function the_authentication_surface_is_throttled(string $method, string $uri): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true)
        );

        $this->assertNotNull($route, "There is no {$method} {$uri} route to check.");

        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(
                fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
            ),
            "{$method} {$uri} carries no throttle middleware. Every unauthenticated write on the "
            .'authentication surface has to have one — see the limiter definitions at the top of routes/web.php.'
        );
    }

    #[Test]
    public function every_scim_route_is_throttled_before_it_is_authenticated(): void
    {
        // Order matters and is not cosmetic. `throttle:scim` has to run BEFORE
        // `scim.auth`, or an invalid token is rejected without being counted
        // and token guessing is unlimited.
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'scim/v2')) {
                continue;
            }

            $middleware = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && ($m === 'scim.auth' || str_starts_with($m, 'throttle:'))
            ));

            $this->assertContains('throttle:scim', $middleware, $route->uri().' is not rate limited.');

            $this->assertLessThan(
                array_search('scim.auth', $middleware, true),
                array_search('throttle:scim', $middleware, true),
                $route->uri().': throttle:scim must be listed before scim.auth, or token guessing is not counted.'
            );

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'No SCIM routes were found to check.');
    }

    /* ================================================================== */
    /*  Behaviour — the login limit */
    /* ================================================================== */

    #[Test]
    public function repeated_sign_in_attempts_from_one_place_are_eventually_refused(): void
    {
        $user = $this->user();

        // The limiter allows five requests a minute per (email, IP). The sixth
        // never reaches the controller.
        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'not-the-password',
            ])->assertRedirect('/login');
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(429);
    }

    #[Test]
    public function five_failures_shut_that_source_out_of_that_account(): void
    {
        $user = $this->user();

        $this->failLogin($user->email, times: 5, from: '203.0.113.10');

        // Correct password, same source: refused, because the pair is locked.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    /* ================================================================== */
    /*  Behaviour — the lockout is no longer a weapon */
    /* ================================================================== */

    #[Test]
    public function an_attacker_cannot_lock_a_named_user_out_of_their_own_account(): void
    {
        // THE FINDING. Five failed attempts against a known email address used
        // to lock that account for thirty minutes no matter where the failures
        // came from, so the login form was a reliable way to disable any named
        // member of staff.
        $victim = $this->user();

        $this->failLogin($victim->email, times: 5, from: '198.51.100.66');

        // The victim, at their own desk, signs in normally.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->post('/login', ['email' => $victim->email, 'password' => 'password'])
            ->assertRedirect('/risk/dashboard');

        $this->assertAuthenticatedAs($victim);
    }

    #[Test]
    public function failed_attempts_no_longer_write_a_persistent_account_lock(): void
    {
        // users.locked_until is what made the old lockout survive across
        // sources and outlive the attack. Nothing in the sign-in path writes it
        // any more; the column stays for administrative locks.
        $user = $this->user();

        $this->failLogin($user->email, times: 5, from: '198.51.100.66');

        $user->refresh();

        $this->assertNull($user->locked_until);

        // The counter is still kept, because an administrator looking at the
        // user screen needs to see that somebody has been trying.
        $this->assertSame(5, $user->login_attempts);
    }

    #[Test]
    public function an_administrative_lock_still_refuses_sign_in_from_anywhere(): void
    {
        // Removing the automatic write must not remove the manual control.
        $user = $this->user();
        $user->forceFill(['locked_until' => now()->addHour()])->saveQuietly();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.201'])
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    #[Test]
    public function a_successful_sign_in_clears_the_counter_for_that_pair(): void
    {
        $user = $this->user();

        $this->failLogin($user->email, times: 4, from: '203.0.113.77');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/risk/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    /* ================================================================== */

    private function failLogin(string $email, int $times, string $from): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $from])
                ->from('/login')
                ->post('/login', ['email' => $email, 'password' => 'wrong-'.$i]);
        }
    }

    private function user(): User
    {
        $this->org ??= Organization::create([
            'name' => 'Throttle Bank PLC',
            'short_name' => 'THRB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($this->org->id);

        $user = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Throttle User',
            'email' => 'throttle+'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->assignRole('risk-manager');

        return $user;
    }
}
