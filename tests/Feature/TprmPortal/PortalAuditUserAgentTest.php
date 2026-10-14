<?php

namespace Tests\Feature\TprmPortal;

use App\Mail\Tprm\PortalSignInCode;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * On the vendor portal the User-Agent is written by the person being audited.
 *
 * A header that the old byte-wise `substr()` cut mid-character made the
 * `tp_audit_logs` insert fail; the portal's catch-all then swallowed it, so a
 * vendor could sign in with no record of having done so. These go through the
 * real login route and assert on the stored row.
 */
class PortalAuditUserAgentTest extends TestCase
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

        $this->vendor = TenantContext::actingAs($this->bank->id, function (): PortalUser {
            $thirdParty = ThirdParty::create([
                'organization_id' => $this->bank->id,
                'legal_name' => 'Cloudspan Nigeria Limited',
                'slug' => Str::random(12),
                'entity_type' => 'company',
                'status' => 'active',
            ]);

            $user = new PortalUser([
                'organization_id' => $this->bank->id,
                'third_party_id' => $thirdParty->id,
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

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function userAgents(): array
    {
        return [
            'odd-offset two-byte characters' => ['a'.str_repeat('é', 400)],
            'emoji straddling the byte limit' => [str_repeat('a', 499).str_repeat('😀', 50)],
            'invalid bytes' => ["Mozilla \xC3\x28"],
        ];
    }

    #[Test]
    #[DataProvider('userAgents')]
    public function a_hostile_user_agent_does_not_suppress_the_portal_audit_row(string $userAgent): void
    {
        Mail::fake();

        $this->withServerVariables(['HTTP_USER_AGENT' => $userAgent])
            ->post(route('tprm-portal.login.attempt', ['client' => $this->bank->uuid]), [
                'email' => $this->vendor->email,
                'password' => 'Sup3r-Str0ng-P@ssphrase!',
            ])->assertRedirect(route('tprm-portal.mfa.challenge'));

        Mail::assertSent(PortalSignInCode::class);

        $row = AuditLog::query()->withoutGlobalScopes()
            ->where('event', 'portal_mfa_code_sent')
            ->where('auditable_id', $this->vendor->id)
            ->first();

        $this->assertNotNull($row, 'the sign-in must leave an audit row');
        $this->assertSame('portal', $row->actor_type);
        // Nobody has proven they are that vendor yet (ADR 0028 section 2).
        $this->assertNull($row->actor_id);
        // The POST carried the vendor's email; none of it may become the label.
        $this->assertNull($row->actor_label);
        $this->assertNotNull($row->user_agent);
        $this->assertTrue(mb_check_encoding($row->user_agent, 'UTF-8'));
        $this->assertLessThanOrEqual(500, mb_strlen($row->user_agent, 'UTF-8'));
        $this->assertTrue($row->isIntact());
    }
}
