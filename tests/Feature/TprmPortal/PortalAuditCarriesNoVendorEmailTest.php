<?php

namespace Tests\Feature\TprmPortal;

use App\Mail\Tprm\PortalSignInCode;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Portal\PortalAuthService;
use App\Services\Tprm\Portal\PortalIncidentService;
use App\Services\Tprm\Portal\TrustProfileService;
use App\Services\Tprm\Portal\VendorIdentityService;
use App\Support\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * No vendor email in any sealed TPRM audit row (ADR 0028 section 6.7).
 *
 * `actor_label`, `before` and `after` are hashed fields in a chain that is
 * never re-sealed (ADR 0025), so an address written there can never be erased
 * under an NDPA erasure request. A vendor is identified by `actor_id` and the
 * `tp_portal_users` row, which is not sealed. This drives every portal flow the
 * suite can reach with a distinctive address and sweeps the RAW stored column
 * text, so a nested or re-keyed copy is caught on both engines.
 */
class PortalAuditCarriesNoVendorEmailTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'erasable.vendor+0028@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        Organization::create([
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
    public function no_audit_row_a_portal_flow_writes_contains_the_vendors_address(): void
    {
        Mail::fake();

        $bank = Organization::query()->firstOrFail();

        RiskCategory::create([
            'organization_id' => $bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($bank->id);

        $thirdParty = ThirdParty::create([
            'organization_id' => $bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12),
            'entity_type' => 'company',
            'status' => 'active',
        ]);

        $auth = app(PortalAuthService::class);
        $password = 'Sup3r-Str0ng-P@ssphrase!';

        // Invitation (written by an internal user) and account creation.
        $issued = $auth->invite($thirdParty, strtoupper(self::ADDRESS), userId: null);
        $vendor = $auth->accept($issued['invitation'], 'Vendor Operator', $password);
        $vendor->forceFill(['mfa_method' => PortalUser::METHOD_EMAIL])->save();
        $this->assertSame(strtolower(self::ADDRESS), $vendor->email);

        // MFA code sent, then sign-in.
        $this->assertTrue($auth->sendEmailCode($vendor)['sent']);
        $code = null;
        Mail::assertSent(PortalSignInCode::class, function (PortalSignInCode $mail) use (&$code): bool {
            $code = $mail->code;

            return true;
        });
        $this->assertTrue($auth->completeMfa($vendor->refresh(), (string) $code));

        // The vendor's own writes while signed in.
        app(PortalIncidentService::class)->report($vendor, ['type' => 'other', 'title' => 'Unauthorised access to a host']);
        $identity = app(VendorIdentityService::class);
        $identity->claim($vendor);
        $profile = $identity->profileFor($vendor);
        $trust = app(TrustProfileService::class);
        $trust->saveDraft($profile, [], $vendor);
        $trust->publish($profile->refresh(), $vendor);

        $auth->logout();

        // TOTP enrolment.
        $auth->beginMfaEnrolment($vendor->refresh(), 'Atheris');
        $this->assertTrue($auth->confirmMfaEnrolment($vendor->refresh(), Totp::at((string) $vendor->mfa_secret, time())));

        // MFA code send failure, with the transport throwing.
        $vendor->forceFill(['mfa_code_sent_at' => null])->save();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));
        $this->assertFalse($auth->sendEmailCode($vendor->refresh())['sent']);

        // Failed sign-ins to lockout.
        for ($i = 0; $i < PortalUser::MAX_ATTEMPTS; $i++) {
            $auth->attempt($bank->id, self::ADDRESS, 'wrong-password-'.$i);
        }

        $events = AuditLog::query()->withoutGlobalScopes()->pluck('event')->unique()->all();
        foreach (['portal_invited', 'portal_account_created', 'portal_mfa_code_sent', 'portal_signed_in', 'incident_reported_by_vendor',
            'portal_identity_claimed', 'portal_signed_out', 'portal_mfa_enrolled', 'portal_mfa_code_send_failed', 'portal_account_locked'] as $event) {
            $this->assertContains($event, $events, "the flow must have written {$event}, or this sweep proves nothing about it");
        }

        $leaks = [];
        foreach (DB::table('tp_audit_logs')->get(['id', 'event', 'actor_label', 'before', 'after']) as $row) {
            foreach (['actor_label', 'before', 'after'] as $column) {
                if (is_string($row->{$column}) && stripos($row->{$column}, self::ADDRESS) !== false) {
                    $leaks[] = "row {$row->id} {$row->event}.{$column}";
                }
            }
        }

        $this->assertSame([], $leaks, "ADR 0028: actor_label, before and after are hashed fields in a sealed chain, so a vendor's\n"
            ."email written there can never be erased under an NDPA erasure request. Identify the vendor by portal_user_id:\n".implode("\n", $leaks));

        $this->assertFalse(
            AuditLog::query()->withoutGlobalScopes()->where('actor_type', AuditLog::ACTOR_PORTAL)->whereNotNull('actor_label')->exists(),
            'ADR 0028 6.5: a portal row never carries an actor_label',
        );
    }
}
