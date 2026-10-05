<?php

namespace Tests\Feature\TprmPortal;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AuditLog;
use App\Models\Tprm\Incident;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Services\Tprm\Portal\PortalIncidentService;
use App\Services\Tprm\Portal\TrustProfileService;
use App\Services\Tprm\Portal\VendorIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The portal's User-Agent is written by the person being audited, and four
 * portal services write `tp_audit_logs` directly (not through
 * `TprmAuditable`): the incident receipt, the identity claim, and the trust
 * profile's own events. `PortalAuditUserAgentTest` drives the sign-in writer
 * through HTTP; these drive the others through their service entry points with
 * the hostile request bound, and assert on the stored, hash-verified row.
 *
 * The incident receipt is the one that matters most: it is the vendor's own
 * evidence of WHEN they told the bank, which starts a regulatory clock.
 */
class PortalDirectAuditWritersUserAgentTest extends TestCase
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

        TenantContext::set($this->bank->id);

        $thirdParty = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12),
            'entity_type' => 'company',
            'status' => 'active',
        ]);

        $this->vendor = new PortalUser([
            'organization_id' => $this->bank->id,
            'third_party_id' => $thirdParty->id,
            'email' => 'ops@cloudspan.test',
            'name' => 'Vendor Operator',
            'password' => 'Sup3r-Str0ng-P@ssphrase!',
        ]);
        $this->vendor->forceFill([
            'status' => PortalUser::STATUS_ACTIVE,
            'accepted_at' => now(),
        ])->save();
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
    public function the_incident_receipt_survives_a_hostile_user_agent(string $userAgent): void
    {
        $this->hostileRequest($userAgent);

        $incident = app(PortalIncidentService::class)->report($this->vendor, [
            'type' => 'other',
            'title' => 'Unauthorised access to a staging host',
        ]);

        $row = $this->row('incident_reported_by_vendor', Incident::class, $incident->getKey());

        $this->assertNotNull($row, 'the vendor-reported incident receipt must be written, not silently lost');
        $this->assertStoredUserAgentIsSafe($row);
    }

    #[Test]
    #[DataProvider('userAgents')]
    public function the_identity_claim_survives_a_hostile_user_agent(string $userAgent): void
    {
        $this->hostileRequest($userAgent);

        app(VendorIdentityService::class)->claim($this->vendor);

        $row = $this->row('portal_identity_claimed', PortalUser::class, $this->vendor->getKey());

        $this->assertNotNull($row, 'the identity claim must be audited');
        $this->assertStoredUserAgentIsSafe($row);
    }

    #[Test]
    #[DataProvider('userAgents')]
    public function publishing_a_trust_profile_survives_a_hostile_user_agent(string $userAgent): void
    {
        $profile = app(VendorIdentityService::class)->profileFor($this->vendor);

        $this->hostileRequest($userAgent);

        app(TrustProfileService::class)->publish($profile, $this->vendor);

        $row = $this->row('portal_trust_profile_published', PortalUser::class, $this->vendor->getKey());

        $this->assertNotNull($row, 'publishing a trust profile must be audited');
        $this->assertStoredUserAgentIsSafe($row);
    }

    private function hostileRequest(string $userAgent): void
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $userAgent]));
    }

    private function row(string $event, string $type, int $id): ?AuditLog
    {
        return AuditLog::query()->withoutGlobalScopes()
            ->where('event', $event)
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->first();
    }

    private function assertStoredUserAgentIsSafe(AuditLog $row): void
    {
        $this->assertNotNull($row->user_agent);
        $this->assertTrue(mb_check_encoding($row->user_agent, 'UTF-8'));
        $this->assertLessThanOrEqual(500, mb_strlen($row->user_agent, 'UTF-8'));
        $this->assertTrue($row->isIntact());
    }
}
