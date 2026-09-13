<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * MFA is enforced by the `auth` middleware group rather than route by route,
 * so a screen added tomorrow is covered without anyone remembering to annotate
 * it. These assert that, and that the enrolment path stays reachable — a
 * requirement nobody can satisfy is a lockout, not a control.
 */
class MfaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every assertion below describes behaviour that only exists WITH THE
     * FEATURE FLAG ON.
     *
     * The whole MFA flow now sits behind features.mfa_totp, which defaults to
     * FALSE because the implementation is broken in three ways — sign-in cannot
     * complete (verifyMfa() never calls Auth::login()), the TOTP counter is
     * packed into four bytes instead of eight, and the enrolment QR code
     * disclosed the shared secret to api.qrserver.com. See config/features.php.
     *
     * This class is the reason the gate cannot rot: it keeps asserting that the
     * enforcement logic is intact and reachable once the flag is flipped, so the
     * rebuild has a specification to satisfy rather than a blank page.
     * MfaFeatureGateTest is its counterpart and asserts the flag-off behaviour.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['features.mfa_totp' => true]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function a_user_whose_role_requires_mfa_is_sent_to_enrol(): void
    {
        $user = $this->user(['chief-risk-officer'], 'chief-risk-officer');

        $this->actingAs($user)
            ->get('/risk/register')
            ->assertRedirect(route('mfa.setup'));
    }

    #[Test]
    public function a_user_whose_role_does_not_require_mfa_passes_through(): void
    {
        $user = $this->user(['chief-risk-officer'], 'risk-analyst');

        $this->actingAs($user)->get('/risk/register')->assertOk();
    }

    #[Test]
    public function an_organization_with_no_requirement_is_unaffected(): void
    {
        $user = $this->user([], 'chief-risk-officer');

        $this->actingAs($user)->get('/risk/register')->assertOk();
    }

    #[Test]
    public function an_enrolled_user_must_verify_before_reaching_anything(): void
    {
        $user = $this->user([], 'risk-manager');
        $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->saveQuietly();

        $this->actingAs($user)
            ->get('/risk/register')
            ->assertRedirect(route('mfa.verify'));
    }

    #[Test]
    public function a_verified_session_proceeds(): void
    {
        $user = $this->user([], 'risk-manager');
        $user->forceFill(['mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'])->saveQuietly();

        $this->actingAs($user)
            ->withSession(['mfa_verified' => true])
            ->get('/risk/register')
            ->assertOk();
    }

    #[Test]
    public function the_enrolment_screen_stays_reachable_while_the_requirement_is_unmet(): void
    {
        // Otherwise a required user is redirected to a page that redirects
        // them back, and can never satisfy the requirement.
        $user = $this->user(['chief-risk-officer'], 'chief-risk-officer');

        $this->actingAs($user)->get(route('mfa.setup'))->assertOk();
    }

    #[Test]
    public function logging_out_stays_reachable_while_the_requirement_is_unmet(): void
    {
        $user = $this->user(['chief-risk-officer'], 'chief-risk-officer');

        $this->actingAs($user)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    #[Test]
    public function the_requirement_is_enforced_across_every_module_not_just_the_dashboard(): void
    {
        $user = $this->user(['risk-manager'], 'risk-manager');

        foreach ([
            '/risk/register',
            '/risk/controls',
            '/risk/issues',
            '/risk/loss-events',
            '/risk/kri',
            '/risk/treatments',
            '/admin/users',
        ] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertRedirect(route('mfa.setup'));
        }
    }

    #[Test]
    public function an_api_style_request_is_refused_rather_than_redirected(): void
    {
        $user = $this->user(['risk-manager'], 'risk-manager');

        $this->actingAs($user)
            ->getJson('/risk/register')
            ->assertStatus(403);
    }

    #[Test]
    public function login_sends_a_required_user_to_enrolment(): void
    {
        $user = $this->user(['risk-manager'], 'risk-manager');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('mfa.setup'));
    }

    /**
     * @param  list<string>  $requiredRoles  organizations.settings->mfa_required_roles
     */
    private function user(array $requiredRoles, string $role): User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $org = Organization::create([
            'name' => 'MFA Bank PLC',
            'short_name' => 'MFAB',
            'institution_type' => 'commercial_bank',
            'sector' => 'banking',
            'is_active' => true,
            'settings' => ['mfa_required_roles' => $requiredRoles],
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'name' => 'MFA User',
            'email' => 'mfa@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
