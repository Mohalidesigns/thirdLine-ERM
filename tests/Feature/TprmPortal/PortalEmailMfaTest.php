<?php

namespace Tests\Feature\TprmPortal;

use App\Mail\Tprm\PortalSignInCode;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Portal\PortalAuthService;
use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The emailed second factor — the portal's default MFA method.
 *
 * EMAIL OTP IS THE WEAKER FACTOR, and these tests are where that is paid for.
 * A code delivered to the same mailbox that receives password recovery is one
 * compromise away from being no second factor at all, so the properties that
 * remain — short life, single use, hashed at rest, bounded guesses, throttled
 * resends — are the whole of what it is worth. Each has a test.
 */
class PortalEmailMfaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private PortalUser $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Lagos Union Bank', 'short_name' => 'LUB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        $this->vendor = $this->portalUser();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function signing_in_emails_a_six_digit_code(): void
    {
        Mail::fake();

        $this->login();

        Mail::assertSent(PortalSignInCode::class, function (PortalSignInCode $mail): bool {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $mail->code);

            // The client's name, not ours — a vendor of four banks needs to
            // know which one is asking.
            $this->assertSame('Lagos Union Bank', $mail->clientName);

            return true;
        });
    }

    #[Test]
    public function the_code_is_hashed_at_rest_and_never_stored_in_the_clear(): void
    {
        Mail::fake();

        $this->login();

        $code = $this->emailedCode();
        $stored = (string) $this->vendor->refresh()->mfa_code_hash;

        $this->assertNotSame($code, $stored);
        $this->assertTrue(Hash::check($code, $stored));

        // And the column is hidden, so a serialised model cannot carry it into
        // a log line or an API response.
        $this->assertArrayNotHasKey('mfa_code_hash', $this->vendor->toArray());
    }

    #[Test]
    public function a_code_works_once(): void
    {
        Mail::fake();

        $this->login();
        $code = $this->emailedCode();

        $this->post(route('tprm-portal.mfa.verify'), ['code' => $code])
            ->assertRedirect(route('tprm-portal.dashboard'));

        $this->post(route('tprm-portal.logout'));

        // The same code, replayed into a fresh sign-in, is dead.
        $this->login();

        $this->post(route('tprm-portal.mfa.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertFalse(auth()->guard('tprm-portal')->check());
    }

    #[Test]
    public function an_expired_code_is_refused(): void
    {
        Mail::fake();

        $this->login();
        $code = $this->emailedCode();

        $this->travel(PortalUser::CODE_TTL_MINUTES + 1)->minutes();

        $this->post(route('tprm-portal.mfa.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertFalse(auth()->guard('tprm-portal')->check());
    }

    #[Test]
    public function guesses_against_one_code_are_bounded(): void
    {
        Mail::fake();

        $this->login();
        $code = $this->emailedCode();

        // Six digits is a million guesses. Without a per-code ceiling an
        // attacker who can request fresh codes gets unlimited attempts at a
        // fresh target each time.
        for ($i = 0; $i < PortalUser::CODE_MAX_ATTEMPTS; $i++) {
            app(PortalAuthService::class)->verifyEmailCode($this->vendor->refresh(), '000000');
        }

        $this->assertFalse(
            app(PortalAuthService::class)->verifyEmailCode($this->vendor->refresh(), $code),
            'The real code must stop working once the guess budget for it is spent.',
        );
    }

    #[Test]
    public function resending_is_throttled(): void
    {
        Mail::fake();

        $service = app(PortalAuthService::class);

        $first = $service->sendEmailCode($this->vendor);
        $this->assertTrue($first['sent']);

        $second = $service->sendEmailCode($this->vendor->refresh());

        $this->assertFalse($second['sent']);
        $this->assertGreaterThan(0, $second['retry_after']);

        Mail::assertSentCount(1);
    }

    #[Test]
    public function a_resend_invalidates_the_previous_code(): void
    {
        Mail::fake();

        $service = app(PortalAuthService::class);

        $service->sendEmailCode($this->vendor);
        $first = $this->emailedCode();

        $this->travel(PortalUser::CODE_RESEND_SECONDS + 1)->seconds();

        $service->sendEmailCode($this->vendor->refresh());

        // Two live codes is how a vendor ends up typing the older one and
        // being told it is wrong.
        $this->assertFalse(
            app(PortalAuthService::class)->verifyEmailCode($this->vendor->refresh(), $first),
        );
    }

    #[Test]
    public function the_audit_log_records_the_send_and_not_the_code(): void
    {
        Mail::fake();

        $this->login();
        $code = $this->emailedCode();

        $entry = \App\Models\Tprm\AuditLog::query()
            ->where('event', 'portal_mfa_code_sent')
            ->latest('id')
            ->firstOrFail();

        // An audit trail the client's own staff can read must not hand them a
        // live second factor for one of their vendors.
        $this->assertStringNotContainsString($code, json_encode($entry->after) ?: '');
    }

    #[Test]
    public function a_totp_vendor_still_signs_in_with_an_authenticator(): void
    {
        Mail::fake();

        $secret = Totp::generateSecret();

        $this->vendor->forceFill([
            'mfa_method' => PortalUser::METHOD_TOTP,
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ])->save();

        $this->login();

        // No email for a TOTP account: the code is on their device.
        Mail::assertNothingSent();

        $this->post(route('tprm-portal.mfa.verify'), ['code' => Totp::at($secret, time())])
            ->assertRedirect(route('tprm-portal.dashboard'));

        $this->assertTrue(auth()->guard('tprm-portal')->check());
    }

    #[Test]
    public function an_email_account_cannot_be_signed_into_with_a_leftover_totp_secret(): void
    {
        Mail::fake();

        // An abandoned enrolment leaves a secret behind. The account is on the
        // email method, so that secret must be inert — otherwise choosing the
        // weaker method quietly leaves the stronger one as a second door.
        $secret = Totp::generateSecret();
        $this->vendor->forceFill(['mfa_secret' => $secret, 'mfa_enabled' => true])->save();

        $this->login();

        $this->post(route('tprm-portal.mfa.verify'), ['code' => Totp::at($secret, time())])
            ->assertSessionHasErrors('code');

        $this->assertFalse(auth()->guard('tprm-portal')->check());
    }

    /* ------------------------------------------------------------------ */

    private function login(): void
    {
        $this->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
            'email' => $this->vendor->email,
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ])->assertRedirect(route('tprm-portal.mfa.challenge'));
    }

    private function emailedCode(): string
    {
        $code = null;

        Mail::assertSent(PortalSignInCode::class, function (PortalSignInCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    private function portalUser(): PortalUser
    {
        return TenantContext::actingAs($this->bank->id, function (): PortalUser {
            $vendor = ThirdParty::create([
                'organization_id' => $this->bank->id,
                'legal_name' => 'Cloudspan Nigeria Limited',
                'slug' => Str::random(12),
                'entity_type' => 'company',
                'status' => 'active',
            ]);

            $user = new PortalUser([
                'organization_id' => $this->bank->id,
                'third_party_id' => $vendor->id,
                'email' => 'ops@cloudspan.test',
                'name' => 'Vendor Operator',
                'password' => 'Sup3r-Str0ng-P@ssphrase!',
            ]);

            $user->forceFill([
                'status' => PortalUser::STATUS_ACTIVE,
                'accepted_at' => now(),
                'mfa_method' => PortalUser::METHOD_EMAIL,
            ])->save();

            return $user;
        });
    }
}
