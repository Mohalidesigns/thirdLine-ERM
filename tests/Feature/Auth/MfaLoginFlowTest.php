<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\Totp;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Migration Phase 1 — sign-in with a second factor completes.
 *
 * The retired AuthController logged the user out after a correct password
 * and sent them to a verification screen that never logged them back in.
 * These cases are the specification the rebuild satisfies.
 */
class MfaLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config(['features.mfa_totp' => true]);

        // The limiters live in the process-wide array cache.
        cache()->flush();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function a_correct_password_holds_the_user_pending_and_never_logs_anyone_out(): void
    {
        Event::fake([Logout::class]);
        $user = $this->enrolledUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('mfa.verify'))
            ->assertSessionHas('mfa_pending_user_id', $user->id)
            ->assertSessionMissing('mfa_verified');

        $this->assertGuest();
        Event::assertNotDispatched(Logout::class);
    }

    #[Test]
    public function the_verification_screen_renders_for_the_pending_user(): void
    {
        $user = $this->enrolledUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->get('/mfa/verify')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/MfaVerify')
                ->where('account', $user->email));
    }

    #[Test]
    public function the_right_code_signs_the_user_in_and_regenerates_the_session(): void
    {
        $user = $this->enrolledUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $pendingSessionId = session()->getId();

        $this->post('/mfa/verify', ['code' => Totp::at(self::SECRET, time())])
            ->assertRedirect('/risk/dashboard')
            ->assertSessionHas('mfa_verified', true)
            ->assertSessionMissing('mfa_pending_user_id');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($pendingSessionId, session()->getId(), 'The session id must be regenerated on sign-in.');
        $this->assertNotNull($user->fresh()->last_login_at);

        // And the session now passes the door.
        $this->get('/risk/register')->assertOk();
    }

    #[Test]
    public function a_wrong_code_is_refused_and_five_of_them_hit_the_limiter(): void
    {
        $user = $this->enrolledUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $attempt) {
            $this->from('/mfa/verify')
                ->post('/mfa/verify', ['code' => '000000'])
                ->assertRedirect('/mfa/verify')
                ->assertSessionHasErrors('code');

            $this->assertGuest();
        }

        // throttle:mfa-verify — five per fifteen minutes per (account, IP).
        $this->post('/mfa/verify', ['code' => '000000'])->assertStatus(429);
    }

    #[Test]
    public function verification_without_a_pending_sign_in_goes_back_to_login(): void
    {
        $this->get('/mfa/verify')->assertRedirect('/login');
        $this->post('/mfa/verify', ['code' => '123456'])->assertRedirect('/login');
    }

    #[Test]
    public function an_authenticated_but_unverified_session_can_verify_in_place(): void
    {
        // A federated user reaches the door already signed in (SsoController
        // → EnsureMfaVerified → mfa.verify) with no pending id in the session.
        $user = $this->enrolledUser();

        $this->actingAs($user)->get('/risk/register')->assertRedirect(route('mfa.verify'));

        $this->actingAs($user)->get('/mfa/verify')->assertOk();

        $this->actingAs($user)
            ->post('/mfa/verify', ['code' => Totp::at(self::SECRET, time())])
            ->assertRedirect('/risk/dashboard')
            ->assertSessionHas('mfa_verified', true);

        $this->get('/risk/register')->assertOk();
    }

    #[Test]
    public function a_user_without_mfa_still_signs_in_directly_with_the_flag_on(): void
    {
        $user = $this->enrolledUser(enrolled: false);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/risk/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    private function enrolledUser(bool $enrolled = true): User
    {
        $org = Organization::create([
            'name' => 'MFA Flow Bank PLC',
            'short_name' => 'MFLW',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
        ]);

        TenantContext::set($org->id);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => 'Flow User',
            'email' => 'flow@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->assignRole('risk-manager');

        if ($enrolled) {
            $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => self::SECRET])->saveQuietly();
        }

        TenantContext::clear();

        return $user;
    }
}
