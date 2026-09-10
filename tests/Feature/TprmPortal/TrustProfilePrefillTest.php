<?php

namespace Tests\Feature\TprmPortal;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Assessment;
use App\Models\Tprm\AssessmentResponse;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\PortalUser;
use App\Models\Tprm\QuestionnaireTemplate;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\TrustProfile;
use App\Models\User;
use App\Services\Tprm\Assessment\AssessmentService;
use App\Services\Tprm\Portal\TrustProfilePrefill;
use App\Services\Tprm\Portal\TrustProfileService;
use App\Services\Tprm\Portal\VendorIdentityService;
use Database\Seeders\Tprm\TprmQuestionnairePackSeeder;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\OrganizationScope;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The second half of Phase 8's trust-profile criterion: "both tenants'
 * assessments pre-fill from it".
 *
 * RUN AGAINST THE SHIPPED QUESTIONNAIRE PACKS, not against invented questions.
 * A pre-fill test with its own fixture questions proves the mapping code
 * works and says nothing about whether the map matches anything a client will
 * ever issue — which is precisely the bug it had: the first map was written
 * from plausible abbreviations and matched one of forty-one shipped questions.
 */
class TrustProfilePrefillTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bankA;

    private Organization $bankB;

    private PortalUser $atBankA;

    private PortalUser $atBankB;

    private TrustProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->bankA = $this->organization('Lagos Union Bank', 'LUB');
        $this->bankB = $this->organization('Abuja Trust Bank', 'ATB');

        $this->atBankA = $this->portalUser($this->bankA, 'ops@cloudspan.test');
        $this->atBankB = $this->portalUser($this->bankB, 'ops@cloudspan.test');

        $identities = app(VendorIdentityService::class);
        $profiles = app(TrustProfileService::class);

        // Completed ONCE, at the first bank.
        $this->profile = $identities->profileFor($this->atBankA);
        $identities->claim($this->atBankB, $identities->claimableIdentity($this->atBankB));

        $profiles->saveDraft($this->profile, [
            'security' => [
                'access_control' => 'Least privilege, approved by the service owner, reviewed quarterly.',
                'encryption_at_rest' => 'AES-256, keys in a separate KMS.',
                'business_continuity' => 'Tested annually; last exercise March 2026.',
                'incident_response' => 'Security incidents are notified within 12 hours.',
            ],
            'certifications' => [
                'soc2' => 'SOC 2 Type II, period ending 30 June 2026, Grant Thornton.',
            ],
        ]);
        $profiles->publish($this->profile, $this->atBankA);
        $this->profile->refresh();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function both_tenants_assessments_pre_fill_from_the_one_profile(): void
    {
        foreach ([[$this->bankA, $this->atBankA], [$this->bankB, $this->atBankB]] as [$bank, $portalUser]) {
            $result = $this->issueWithShare($bank, $portalUser);

            $this->assertGreaterThan(
                0,
                $result['filled'],
                sprintf('%s should have pre-filled from the shared profile.', $bank->name),
            );
            $this->assertSame(1, $result['version']);
        }
    }

    #[Test]
    public function the_pre_filled_answer_is_a_proposal_and_not_a_submitted_answer(): void
    {
        $assessment = $this->issueWithShare($this->bankA, $this->atBankA)['assessment'];

        $response = TenantContext::actingAs($this->bankA->id, fn () => AssessmentResponse::query()
            ->where('assessment_id', $assessment->getKey())
            ->whereHas('question', fn ($q) => $q->where('code', 'CBN-ACC-01'))
            ->firstOrFail());

        $this->assertTrue($response->is_auto_answered);
        $this->assertSame(AssessmentResponse::REVIEW_PENDING, $response->reviewer_status);

        // The citation is what makes a disagreement a year from now
        // explicable: the profile has moved on, the answer has not.
        $this->assertSame('trust_profile', $response->auto_answer_source['source']);
        $this->assertSame(1, $response->auto_answer_source['version']);
        $this->assertStringContainsString('Confirm it still applies', $response->auto_answer_source['note']);
    }

    #[Test]
    public function nothing_pre_fills_without_an_approved_share(): void
    {
        // Issued with no share requested at all.
        $assessment = TenantContext::actingAs($this->bankA->id, fn () => $this->issue($this->bankA));

        $result = app(TrustProfilePrefill::class)->apply($assessment);

        $this->assertSame(0, $result['filled']);

        // "The vendor has not agreed to share with you" and "the profile had
        // nothing relevant" are different facts, and only the first has an
        // action attached.
        $this->assertTrue($result['skipped_no_share']);
    }

    #[Test]
    public function an_answer_the_client_already_has_is_not_overwritten(): void
    {
        $assessment = $this->issueWithShare($this->bankA, $this->atBankA)['assessment'];

        TenantContext::actingAs($this->bankA->id, function () use ($assessment): void {
            $response = AssessmentResponse::query()
                ->where('assessment_id', $assessment->getKey())
                ->whereHas('question', fn ($q) => $q->where('code', 'CBN-ACC-01'))
                ->firstOrFail();

            $original = $response->value;

            $response->forceFill([
                'value' => 'A specific answer this client was given last cycle.',
                'compliance' => \App\Enums\Tprm\ComplianceLevel::Compliant->value,
                'is_auto_answered' => false,
            ])->save();

            // Re-running must not clobber it: an answer given to THIS client
            // with evidence THIS client accepted beats a general statement the
            // vendor published for everybody.
            app(TrustProfilePrefill::class)->apply($assessment);

            $this->assertSame(
                'A specific answer this client was given last cycle.',
                $response->refresh()->value,
            );
            $this->assertNotSame($original, $response->value);
        });
    }

    #[Test]
    public function the_map_matches_questions_the_product_actually_ships(): void
    {
        // The guard against the bug this feature had: a map keyed on codes
        // nobody issues is a feature that runs on every assessment and fills
        // nothing.
        $shipped = TenantContext::actingAs($this->bankA->id, fn () => \App\Models\Tprm\Question::query()
            ->withoutGlobalScopes()
            ->pluck('code')
            ->map(fn ($code) => mb_strtoupper((string) $code))
            ->all());

        $matched = array_intersect(array_keys(TrustProfilePrefill::MAP), $shipped);

        $this->assertGreaterThanOrEqual(
            15,
            count($matched),
            'The pre-fill map has drifted from the shipped question codes: '
            .implode(', ', array_diff(array_keys(TrustProfilePrefill::MAP), $shipped)),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{assessment: Assessment, filled: int, version: int|null}
     */
    private function issueWithShare(Organization $bank, PortalUser $portalUser): array
    {
        $profiles = app(TrustProfileService::class);

        $thirdParty = ThirdParty::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->findOrFail($portalUser->third_party_id);

        $share = $profiles->requestShare($this->profile, $bank->id, $thirdParty->id);
        $profiles->approveShare($share, $portalUser);

        return TenantContext::actingAs($bank->id, function () use ($bank): array {
            $assessment = $this->issue($bank);
            $result = app(TrustProfilePrefill::class)->apply($assessment);

            return ['assessment' => $assessment] + $result;
        });
    }

    private function issue(Organization $bank): Assessment
    {
        $engagement = $this->engagement($bank);

        $template = QuestionnaireTemplate::query()
            ->withoutGlobalScopes()
            ->where('code', 'CBN-CYBER-CORE')
            ->where('status', 'published')
            ->firstOrFail();

        return app(AssessmentService::class)->issue($engagement, $template);
    }

    private function engagement(Organization $bank): Engagement
    {
        $owner = User::query()->where('organization_id', $bank->id)->firstOrFail();

        $thirdParty = ThirdParty::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $bank->id)
            ->firstOrFail();

        $engagement = Engagement::create([
            'organization_id' => $bank->id,
            'third_party_id' => $thirdParty->id,
            'reference' => 'ENG-'.Str::upper(Str::random(6)),
            'name' => 'Core banking hosting',
            'service_description' => 'Hosting of the core banking platform.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $owner->id,
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Assessment->value,
            'inherent_score' => 70,
            'inherent_tier' => RiskTier::High->value,
            'effective_tier' => RiskTier::High->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $bank->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 70, 'resulting_tier' => RiskTier::High->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        return $engagement->refresh();
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

        User::create([
            'name' => $short.' Owner',
            'email' => Str::lower($short).'-owner@test.test',
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);

        TenantContext::actingAs($organization->id, function (): void {
            $this->seed(TprmReferenceSeeder::class);
            $this->seed(TprmQuestionnairePackSeeder::class);
        });

        return $organization;
    }

    private function portalUser(Organization $organization, string $email): PortalUser
    {
        return TenantContext::actingAs($organization->id, function () use ($organization, $email): PortalUser {
            $vendor = ThirdParty::create([
                'organization_id' => $organization->id,
                'legal_name' => 'Cloudspan Nigeria Limited',
                'slug' => Str::random(12),
                'entity_type' => 'company',
                'status' => 'active',
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
