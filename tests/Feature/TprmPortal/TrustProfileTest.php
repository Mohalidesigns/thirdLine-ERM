<?php

namespace Tests\Feature\TprmPortal;

use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\TrustProfile;
use App\Models\Tprm\TrustProfileShare;
use App\Models\Tprm\VendorIdentity;
use App\Services\Tprm\Portal\TrustProfileService;
use App\Services\Tprm\Portal\VendorIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\OrganizationScope;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Phase 8's trust-profile acceptance criterion:
 *
 * "A vendor invited to two tenants completes its trust profile once and both
 * tenants' assessments pre-fill from it, with the vendor approving each
 * disclosure."
 *
 * THE HARD PART IS THE FIRST CLAUSE, not the pre-fill. Phase 0 keyed the
 * profile on `third_party_id`, which is tenant-scoped — Lagos Union Bank's
 * "Cloudspan" and Abuja Trust Bank's are two different rows, so one profile
 * could never have served both. These tests are written against the identity
 * that fixes it, and the first one would have been impossible to write before.
 */
class TrustProfileTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bankA;

    private Organization $bankB;

    private PortalUser $atBankA;

    private PortalUser $atBankB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bankA = $this->organization('Lagos Union Bank', 'LUB');
        $this->bankB = $this->organization('Abuja Trust Bank', 'ATB');

        // The SAME PERSON at the same vendor, invited by two banks. Two portal
        // accounts, because the unique index is (organization_id, email) —
        // deliberately, so the two clients cannot see each other's account.
        $this->atBankA = $this->portalUser($this->bankA, 'ops@cloudspan.test');
        $this->atBankB = $this->portalUser($this->bankB, 'ops@cloudspan.test');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Completed once */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_vendor_at_two_tenants_completes_one_profile(): void
    {
        $identities = app(VendorIdentityService::class);

        // Claimed at the first bank.
        $profileA = $identities->profileFor($this->atBankA);

        // At the second bank the vendor is offered the identity it already
        // has — matched on an address this person has authenticated against in
        // both places, never on a name.
        $claimable = $identities->claimableIdentity($this->atBankB);

        $this->assertNotNull($claimable, 'The vendor should be offered the identity it already owns.');

        $identities->claim($this->atBankB, $claimable);
        $profileB = $identities->profileFor($this->atBankB);

        $this->assertSame(
            $profileA->getKey(),
            $profileB->getKey(),
            'One company, one profile — that is the whole point of the identity.',
        );

        $this->assertSame(1, TrustProfile::query()->count());
        $this->assertSame(1, VendorIdentity::query()->count());
    }

    #[Test]
    public function nothing_links_two_vendors_automatically(): void
    {
        $identities = app(VendorIdentityService::class);

        $identities->claim($this->atBankA);

        // A DIFFERENT person at a DIFFERENT vendor that happens to share a
        // name. A wrong link here shows one bank another bank's vendor's
        // posture, so nothing may guess.
        $impostor = $this->portalUser($this->bankB, 'admin@other-cloudspan.test', 'Cloudspan Nigeria Limited');

        $this->assertNull(
            $identities->claimableIdentity($impostor),
            'An identical legal name must never be enough to claim another vendor\'s profile.',
        );
    }

    #[Test]
    public function claiming_twice_does_not_fork_a_second_identity(): void
    {
        $identities = app(VendorIdentityService::class);

        $first = $identities->claim($this->atBankA);
        $second = $identities->claim($this->atBankA);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, VendorIdentity::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Nothing is readable without the vendor's approval */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_client_reads_nothing_until_the_vendor_approves(): void
    {
        $profile = $this->publishedProfile();
        $service = app(TrustProfileService::class);

        $share = $service->requestShare($profile, $this->bankA->id, $this->thirdPartyOf($this->atBankA)->id);

        $this->assertSame(TrustProfileShare::STATUS_REQUESTED, $share->status);

        // Requested is not approved. A null approval must read as no access
        // everywhere, never as "not yet checked".
        $this->assertNull(
            $service->documentFor($this->thirdPartyOf($this->atBankA), $this->bankA->id),
        );

        $service->approveShare($share, $this->atBankA);

        $document = $service->documentFor($this->thirdPartyOf($this->atBankA), $this->bankA->id);

        $this->assertNotNull($document);
        $this->assertSame('Yes, enforced through SSO with MFA.', $document['sections']['security']['access_control']);
    }

    #[Test]
    public function each_client_is_approved_separately(): void
    {
        $profile = $this->publishedProfile();
        $service = app(TrustProfileService::class);

        $shareA = $service->requestShare($profile, $this->bankA->id, $this->thirdPartyOf($this->atBankA)->id);
        $service->requestShare($profile, $this->bankB->id, $this->thirdPartyOf($this->atBankB)->id);

        $service->approveShare($shareA, $this->atBankA);

        // Approving one client must not approve the other. A vendor's posture
        // is commercially sensitive, and a profile readable by every bank the
        // moment it is written is a profile no vendor completes.
        $this->assertNotNull($service->documentFor($this->thirdPartyOf($this->atBankA), $this->bankA->id));
        $this->assertNull($service->documentFor($this->thirdPartyOf($this->atBankB), $this->bankB->id));
    }

    #[Test]
    public function a_revoked_share_stops_reading_and_cannot_be_re_requested_back(): void
    {
        $profile = $this->publishedProfile();
        $service = app(TrustProfileService::class);

        $share = $service->requestShare($profile, $this->bankA->id, $this->thirdPartyOf($this->atBankA)->id);
        $service->approveShare($share, $this->atBankA);
        $service->revokeShare($share, $this->atBankA, 'Contract ended.');

        $this->assertNull($service->documentFor($this->thirdPartyOf($this->atBankA), $this->bankA->id));

        // A client that could re-request its way back would make revocation
        // decorative.
        $service->requestShare($profile, $this->bankA->id, $this->thirdPartyOf($this->atBankA)->id);

        $this->assertNull($service->documentFor($this->thirdPartyOf($this->atBankA), $this->bankA->id));
    }

    #[Test]
    public function a_share_exposes_only_the_sections_the_vendor_approved(): void
    {
        $profile = $this->publishedProfile();
        $service = app(TrustProfileService::class);

        $share = $service->requestShare($profile, $this->bankA->id, $this->thirdPartyOf($this->atBankA)->id);
        $service->approveShare($share, $this->atBankA, ['company']);

        $document = $service->documentFor($this->thirdPartyOf($this->atBankA), $this->bankA->id);

        $this->assertArrayHasKey('company', $document['sections']);
        $this->assertArrayNotHasKey('security', $document['sections']);
    }

    #[Test]
    public function an_unpublished_draft_is_readable_by_nobody(): void
    {
        $identities = app(VendorIdentityService::class);
        $service = app(TrustProfileService::class);

        $profile = $identities->profileFor($this->atBankA);
        $service->saveDraft($profile, ['security' => ['access_control' => 'Draft answer, not published.']]);

        $share = $service->requestShare($profile, $this->bankA->id, $this->thirdPartyOf($this->atBankA)->id);
        $service->approveShare($share, $this->atBankA);

        // Approved, and still nothing: a vendor revising next quarter's
        // answers must not change what a client reads today.
        $this->assertNull($service->documentFor($this->thirdPartyOf($this->atBankA), $this->bankA->id));
    }

    /* ------------------------------------------------------------------ */
    /*  Completeness — the behavioural lever */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function completeness_is_measured_on_what_is_published_not_on_the_draft(): void
    {
        $identities = app(VendorIdentityService::class);
        $service = app(TrustProfileService::class);

        $profile = $identities->profileFor($this->atBankA);
        $service->saveDraft($profile, $this->fullDocument());

        // A perfect draft that has been shared with nobody is worth nothing to
        // anybody, and the number must say so.
        $this->assertSame(0.0, $profile->completeness()['pct']);

        $service->publish($profile, $this->atBankA);

        $this->assertGreaterThan(0.0, $profile->refresh()->completeness()['pct']);
    }

    #[Test]
    public function the_next_best_actions_are_ordered_by_what_they_are_worth(): void
    {
        $identities = app(VendorIdentityService::class);
        $service = app(TrustProfileService::class);

        $profile = $identities->profileFor($this->atBankA);

        // Only the lightest section done. The advice should point at the
        // heaviest outstanding one, not at whichever is emptiest.
        $service->saveDraft($profile, ['policies' => [
            'information_security' => 'Yes', 'business_continuity' => 'Yes',
            'privacy' => 'Yes', 'code_of_conduct' => 'Yes',
        ]]);
        $service->publish($profile, $this->atBankA);

        $actions = $service->nextBestActions($profile->refresh());

        $this->assertSame('security', $actions[0]['key'], 'The heaviest outstanding section should lead.');
        $this->assertNotEmpty($actions[0]['unlocks']);

        // A finished section is not a to-do.
        $this->assertNotContains('policies', array_column($actions, 'key'));
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function publishedProfile(): TrustProfile
    {
        $identities = app(VendorIdentityService::class);
        $service = app(TrustProfileService::class);

        $profile = $identities->profileFor($this->atBankA);

        // The same vendor, at the second bank, on the same identity.
        $identities->claim($this->atBankB, $identities->claimableIdentity($this->atBankB));

        $service->saveDraft($profile, $this->fullDocument());
        $service->publish($profile, $this->atBankA);

        return $profile->refresh();
    }

    /** @return array<string, array<string, mixed>> */
    private function fullDocument(): array
    {
        return [
            'company' => [
                'legal_name' => 'Cloudspan Nigeria Limited',
                'registration_number' => 'RC-1234567',
                'country_of_incorporation' => 'NG',
                'year_established' => '2014',
                'employee_band' => '51-200',
                'website' => 'https://cloudspan.test',
                'primary_contact_email' => 'ops@cloudspan.test',
            ],
            'security' => [
                'access_control' => 'Yes, enforced through SSO with MFA.',
                'encryption_at_rest' => 'AES-256 on all volumes.',
                'encryption_in_transit' => 'TLS 1.2 minimum.',
            ],
        ];
    }

    private function thirdPartyOf(PortalUser $user): ThirdParty
    {
        return ThirdParty::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->findOrFail($user->third_party_id);
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

    private function portalUser(
        Organization $organization,
        string $email,
        string $vendorName = 'Cloudspan Nigeria Limited',
    ): PortalUser {
        return TenantContext::actingAs($organization->id, function () use ($organization, $email, $vendorName): PortalUser {
            $vendor = ThirdParty::create([
                'organization_id' => $organization->id,
                'legal_name' => $vendorName,
                'slug' => Str::random(12),
                'entity_type' => 'company',
                'status' => 'active',
                'registration_number' => 'RC-1234567',
                'country_of_incorporation' => 'NG',
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
                'mfa_method' => PortalUser::METHOD_EMAIL,
            ])->save();

            return $user;
        });
    }
}
