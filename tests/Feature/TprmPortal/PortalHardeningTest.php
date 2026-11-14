<?php

namespace Tests\Feature\TprmPortal;

use App\Http\Middleware\Tprm\EnsurePendingPortalUser;
use App\Models\Organization;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Portal\PortalAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The vendor portal's sign-in hardening — FR-PRT-01.
 *
 * The per-account limit used to key on the form's `email`, which the MFA
 * requests do not carry, so every pending vendor of one client shared a
 * single bucket; a tripped limit answered with a bare 429 page that Inertia
 * showed as a modal; the challenge payload carried the address that is
 * masked one line below it; and the invitation expiry had no time zone.
 */
class PortalHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        RateLimiter::clear('tprm-portal-login');

        $this->bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function pending_vendors_of_one_client_each_get_their_own_mfa_account_bucket(): void
    {
        $first = $this->pendingUser('first@cloudspan.test');
        $second = $this->pendingUser('second@cloudspan.test');

        // Five wrong codes is the whole per-account allowance; the sixth is refused.
        for ($i = 0; $i < 5; $i++) {
            $this->pendingSession($first)->post(route('tprm-portal.mfa.verify'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }
        $this->pendingSession($first)->post(route('tprm-portal.mfa.verify'), ['code' => '000000'], ['Accept' => 'application/json'])
            ->assertStatus(429);

        // The other vendor, same client, is untouched by it.
        $this->pendingSession($second)->post(route('tprm-portal.mfa.verify'), ['code' => '000000'], ['Accept' => 'application/json'])
            ->assertStatus(302);
    }

    #[Test]
    public function an_inertia_request_over_the_mfa_limit_is_redirected_back_with_a_code_error(): void
    {
        $user = $this->pendingUser('first@cloudspan.test');

        for ($i = 0; $i < 5; $i++) {
            $this->pendingSession($user)->post(route('tprm-portal.mfa.verify'), ['code' => '000000']);
        }

        foreach (['tprm-portal.mfa.verify', 'tprm-portal.mfa.resend'] as $route) {
            $this->pendingSession($user)
                ->from(route('tprm-portal.mfa.challenge'))
                ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
                ->post(route($route), ['code' => '000000'])
                ->assertRedirect(route('tprm-portal.mfa.challenge'))
                ->assertSessionHasErrors('code');

            $this->assertStringContainsString('wait', session('errors')->first('code'));
        }
    }

    #[Test]
    public function an_inertia_login_over_the_limit_is_redirected_back_with_an_email_error(): void
    {
        $url = route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]);
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)->post($url, ['email' => 'nobody@cloudspan.test', 'password' => 'wrong']);
        }

        $this->from(route('tprm-portal.login', ['client' => $this->bank->uuid]))
            ->withHeaders($headers)
            ->post($url, ['email' => 'nobody@cloudspan.test', 'password' => 'wrong'])
            ->assertRedirect(route('tprm-portal.login', ['client' => $this->bank->uuid]))
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('wait', session('errors')->first('email'));
    }

    #[Test]
    public function another_vendors_pending_key_in_the_email_field_cannot_fill_their_bucket(): void
    {
        $attacker = $this->pendingUser('attacker@cloudspan.test');
        $victim = $this->pendingUser('victim@cloudspan.test');

        for ($i = 0; $i < 5; $i++) {
            $this->pendingSession($attacker)->post(route('tprm-portal.mfa.verify'), [
                'code' => '000000', 'email' => 'pending:'.$victim->id,
            ]);
        }

        $this->pendingSession($victim)->post(route('tprm-portal.mfa.verify'), ['code' => '000000'], ['Accept' => 'application/json'])
            ->assertStatus(302);
    }

    #[Test]
    public function a_random_email_on_each_mfa_request_does_not_escape_the_pending_users_bucket(): void
    {
        $user = $this->pendingUser('first@cloudspan.test');

        for ($i = 0; $i < 5; $i++) {
            $this->pendingSession($user)->post(route('tprm-portal.mfa.verify'), [
                'code' => '000000', 'email' => Str::random(10).'@evil.test',
            ]);
        }

        $this->pendingSession($user)->post(route('tprm-portal.mfa.verify'), [
            'code' => '000000', 'email' => Str::random(10).'@evil.test',
        ], ['Accept' => 'application/json'])->assertStatus(429);
    }

    #[Test]
    public function different_invitations_do_not_share_a_bucket_and_one_invitation_trips_after_five(): void
    {
        $body = ['name' => 'X', 'password' => 'weak', 'password_confirmation' => 'weak'];

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('tprm-portal.invitation.accept', ['token' => 'token-a']), $body);
        }

        $this->post(route('tprm-portal.invitation.accept', ['token' => 'token-a']), $body, ['Accept' => 'application/json'])
            ->assertStatus(429);

        // Another invitation is answered by the controller, not by the limiter.
        $this->assertNotSame(429, $this->post(route('tprm-portal.invitation.accept', ['token' => 'token-b']), $body, ['Accept' => 'application/json'])->getStatusCode());
    }

    #[Test]
    public function an_inertia_invitation_accept_over_the_limit_is_redirected_back_with_an_email_error(): void
    {
        $url = route('tprm-portal.invitation.accept', ['token' => 'token-a']);
        $show = route('tprm-portal.invitation.show', ['token' => 'token-a']);
        $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)->post($url, ['password' => 'weak']);
        }

        $this->from($show)->withHeaders($headers)->post($url, ['password' => 'weak'])
            ->assertRedirect($show)
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('wait', session('errors')->first('email'));
    }

    #[Test]
    public function the_challenge_props_carry_the_masked_address_and_never_the_raw_one(): void
    {
        Mail::fake();
        $user = $this->pendingUser('first@cloudspan.test');

        $this->pendingSession($user)->get(route('tprm-portal.mfa.challenge'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('TprmPortal/Auth/MfaChallenge')
                ->has('sent_to')
                ->missing('email'));
    }

    #[Test]
    public function the_invitation_expiry_names_its_time_zone(): void
    {
        Mail::fake();
        Http::fake();

        $thirdParty = TenantContext::actingAs($this->bank->id, fn (): ThirdParty => ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12),
            'entity_type' => 'company',
            'status' => 'active',
        ]));
        $token = app(PortalAuthService::class)->invite($thirdParty, 'new.person@cloudspan.test')['token'];

        $this->get(route('tprm-portal.invitation.show', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('expires_at', fn (string $at): bool => preg_match('/ (UTC|[A-Z]{3,4}|[+-]\d{2}:\d{2})$/', $at) === 1));
    }

    /** A half-authenticated sign-in: the id the password step parks in the session. */
    private function pendingSession(PortalUser $user): self
    {
        return $this->withSession([
            EnsurePendingPortalUser::SESSION_KEY => $user->id,
            EnsurePendingPortalUser::STARTED_KEY => time(),
            'tprm_portal_pending_organization' => $this->bank->uuid,
        ]);
    }

    private function pendingUser(string $email): PortalUser
    {
        $thirdParty = TenantContext::actingAs($this->bank->id, fn (): ThirdParty => ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan '.Str::random(5),
            'slug' => Str::random(12),
            'entity_type' => 'company',
            'status' => 'active',
        ]));

        return TenantContext::actingAs($this->bank->id, function () use ($thirdParty, $email): PortalUser {
            $user = new PortalUser([
                'organization_id' => $this->bank->id,
                'third_party_id' => $thirdParty->id,
                'email' => $email,
                'name' => 'Vendor Operator',
                'password' => 'Sup3r-Str0ng-P@ssphrase!',
            ]);

            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE,
                'accepted_at' => now(),
                'mfa_method' => PortalUser::METHOD_EMAIL,
                'mfa_enabled' => false,
            ])->save();

            return $user;
        });
    }
}
