<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\AccessLevel;
use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Exceptions\Tprm\OpenAccessException;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AccessGrant;
use App\Models\Tprm\Document;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\OffboardingChecklist;
use App\Models\Tprm\OffboardingItem;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\Waiver;
use App\Models\User;
use App\Services\Tprm\Access\AccessService;
use App\Services\Tprm\Exit\OffboardingService;
use App\Services\Tprm\IntakeService;
use App\Support\Tprm\OffboardingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The offboarding checklist — FR-EXT-03, and the second gate on `terminated`.
 *
 * THE INTERESTING PROPERTY IS THAT IT RECONCILES RATHER THAN DUPLICATES. Two
 * of the nine items are already answered by the Phase 7 access register; a
 * checklist that let somebody tick them by hand would give a bank two answers
 * to one question and let it complete an offboarding while the termination
 * guard still refused.
 */
class OffboardingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $manager;

    private Engagement $engagement;

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

        $this->manager = User::create([
            'name' => 'Manager', 'email' => 'manager@lub.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_checklist_is_generated_on_entering_transition_not_on_termination(): void
    {
        $this->assertSame(0, OffboardingChecklist::query()->count());

        $this->walkToTransitioning();

        // By the time a relationship is being terminated the data return
        // should already have happened; a checklist that appeared at the end
        // would be a list of things it is too late to do.
        $checklist = OffboardingChecklist::query()->where('engagement_id', $this->engagement->id)->firstOrFail();

        $this->assertCount(count(OffboardingTemplate::ITEMS), $checklist->items);
    }

    #[Test]
    public function generating_twice_does_not_produce_two_checklists(): void
    {
        $this->walkToTransitioning();

        app(OffboardingService::class)->generate($this->engagement->fresh(), $this->manager->id);

        $this->assertSame(1, OffboardingChecklist::query()->count());
        $this->assertSame(count(OffboardingTemplate::ITEMS), OffboardingItem::query()->count());
    }

    #[Test]
    public function the_access_items_read_the_register_rather_than_accepting_a_tick(): void
    {
        $grant = $this->liveGrant();
        $this->walkToTransitioning();

        $item = $this->item('OFF-ACCESS');

        $this->assertSame(OffboardingItem::STATUS_OPEN, $item->status);

        // A checklist that let this be ticked by hand would let a bank
        // complete its offboarding while a live production login stands.
        try {
            app(OffboardingService::class)->complete($item, null, $this->manager->id);
            $this->fail('A reconciled item must not be completable by hand.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('answered by the access register', $exception->getMessage());
        }

        app(AccessService::class)->revokeGrant($grant, $this->evidenceDocument()->id, $this->manager->id);

        $checklist = app(OffboardingService::class)->refresh($this->checklist());

        $this->assertSame(
            OffboardingItem::STATUS_COMPLETE,
            $checklist->items->firstWhere('code', 'OFF-ACCESS')->status,
        );
    }

    #[Test]
    public function termination_is_blocked_while_a_mandatory_item_is_outstanding(): void
    {
        $this->walkToTransitioning();

        try {
            app(IntakeService::class)->transition($this->engagement, EngagementStatus::Terminated, $this->manager->id);
            $this->fail('Termination should have been blocked by the offboarding checklist.');
        } catch (OpenAccessException $exception) {
            $labels = array_column($exception->details(), 'label');

            $this->assertContains('Data destruction certificate received', $labels);
            $this->assertContains('Our data returned in the agreed format', $labels);
        }

        $this->assertSame(EngagementStatus::Transitioning, $this->engagement->fresh()->status);
    }

    #[Test]
    public function an_optional_item_does_not_block(): void
    {
        $this->walkToTransitioning();

        // Customer communication does not apply to a vendor no customer ever
        // heard of. Treating it as a blocker teaches people to tick things
        // that did not happen.
        $this->completeAllMandatory();

        $optional = $this->item('OFF-CUSTOMER');
        $this->assertFalse($optional->is_mandatory);
        $this->assertSame(OffboardingItem::STATUS_OPEN, $optional->status);

        $this->assertTrue(
            app(IntakeService::class)->transition($this->engagement, EngagementStatus::Terminated, $this->manager->id),
        );
    }

    #[Test]
    public function an_exception_needs_an_approver_and_lands_in_the_waiver_register(): void
    {
        $this->walkToTransitioning();

        $item = $this->item('OFF-DATA-DESTROY');

        app(OffboardingService::class)->except(
            $item,
            'The provider never held our data — the service was compute only.',
            $this->manager->id,
            $this->manager->id,
        );

        $this->assertSame(OffboardingItem::STATUS_EXCEPTED, $item->refresh()->status);

        // One override register for the whole module, so a risk committee
        // reads one report rather than four.
        $waiver = Waiver::query()
            ->where('waivable_type', Waiver::TYPE_OFFBOARDING_ITEM)
            ->where('waivable_id', $item->getKey())
            ->firstOrFail();

        $this->assertSame(Waiver::STATUS_APPROVED, $waiver->status);
        $this->assertStringContainsString('compute only', $waiver->rationale);
    }

    #[Test]
    public function both_gates_are_reported_together(): void
    {
        $this->liveGrant();
        $this->walkToTransitioning();

        $readiness = app(OffboardingService::class)->terminationReadiness($this->engagement->fresh());

        $kinds = array_unique(array_column($readiness['blockers'], 'kind'));

        // Reporting them separately would send somebody round the loop twice:
        // clear the access, press terminate, discover the data destruction
        // certificate is missing.
        $this->assertContains('access_grant', $kinds);
        $this->assertContains('offboarding_item', $kinds);

        // And a reconciled item is not listed twice in two voices.
        $labels = array_column($readiness['blockers'], 'label');
        $this->assertNotContains('Every access grant revoked, with evidence', $labels);
    }

    /* ------------------------------------------------------------------ */

    private function completeAllMandatory(): void
    {
        $service = app(OffboardingService::class);

        foreach ($this->checklist()->items as $item) {
            if ($item->isReconciled() || ! $item->is_mandatory) {
                continue;
            }

            $service->complete($item, null, $this->manager->id);
        }
    }

    private function checklist(): OffboardingChecklist
    {
        return OffboardingChecklist::query()
            ->where('engagement_id', $this->engagement->id)
            ->with('items')
            ->firstOrFail();
    }

    private function item(string $code): OffboardingItem
    {
        return OffboardingItem::query()
            ->whereHas('checklist', fn ($q) => $q->where('engagement_id', $this->engagement->id))
            ->where('code', $code)
            ->firstOrFail();
    }

    private function liveGrant(): AccessGrant
    {
        $grant = app(AccessService::class)->grantAccess($this->engagement, [
            'grantee_name' => 'Yusuf Bello',
            'system_name' => 'Core Banking',
            'access_level' => AccessLevel::Admin->value,
            'justification' => 'Support of the hosted platform.',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addMonths(3)->toDateString(),
            'monitoring_method' => 'Session recording.',
        ], $this->manager->id);

        return app(AccessService::class)->approveGrant($grant, $this->manager->id);
    }

    private function evidenceDocument(): Document
    {
        return Document::create([
            'organization_id' => $this->bank->id,
            'owner_type' => Document::OWNER_ENGAGEMENT,
            'owner_id' => $this->engagement->id,
            'title' => 'Access review extract',
            'file_path' => 'tprm/evidence/access-review.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'hash' => hash('sha256', Str::random(32)),
            'uploaded_by' => $this->manager->id,
        ]);
    }

    private function walkToTransitioning(): void
    {
        $intake = app(IntakeService::class);

        $intake->transition($this->engagement, EngagementStatus::ExitPlanning, $this->manager->id);
        $intake->transition($this->engagement->refresh(), EngagementStatus::Transitioning, $this->manager->id);

        $this->engagement->refresh();
    }

    private function makeEngagement(): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => 'ENG-'.Str::upper(Str::random(6)),
            'name' => 'Core banking hosting',
            'service_description' => 'Fixture.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->manager->id,
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
            'inherent_score' => 70,
            'inherent_tier' => RiskTier::High->value,
            'effective_tier' => RiskTier::High->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 70, 'resulting_tier' => RiskTier::High->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        return $engagement->refresh();
    }
}
