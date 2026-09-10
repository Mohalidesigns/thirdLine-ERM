<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\RiskTier;
use App\Enums\Tprm\SignalType;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\AlertRule;
use App\Models\Tprm\DueDiligenceItem;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\MonitoringSignal;
use App\Models\Tprm\SanctionsList;
use App\Models\Tprm\ScreeningCheck;
use App\Models\Tprm\ScreeningMatch;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\DueDiligence\DueDiligenceService;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 6 screens, at prop level per standard §10.
 *
 * The three claims worth testing here are all about honesty: the monitoring
 * console has to name the signal types nothing is watching, the screening
 * queue has to state what confirming a match will do BEFORE it is confirmed,
 * and the due diligence screen has to name its blockers rather than disabling
 * a button.
 */
class MonitoringScreensTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $officer;

    private ThirdParty $vendor;

    private Engagement $engagement;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);
        config()->set('tprm.ai.enabled', false);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->organization->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->officer = $this->userWith([
            'tprm.view', 'tprm.edit', 'tprm.waiver.approve',
            'tprm.monitoring.view', 'tprm.monitoring.manage',
            'tprm.screening.view', 'tprm.screening.decide',
        ], 'officer@khb.test', 'tprm-officer');

        $this->vendor = ThirdParty::create([
            'legal_name' => 'Cloudspan Nigeria Limited', 'slug' => Str::random(10),
            'entity_type' => 'company', 'status' => 'active',
        ]);

        $this->engagement = Engagement::create([
            'third_party_id' => $this->vendor->id,
            'reference' => 'ENG-2026-0001',
            'name' => 'Core banking hosting',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->officer->id,
        ]);

        $this->engagement->forceFill([
            'effective_tier' => RiskTier::High->value,
            'inherent_tier' => RiskTier::High->value,
        ])->save();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The monitoring console */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_console_names_the_signal_types_nothing_is_watching(): void
    {
        // Three green feeds is reassuring and misleading if the client bought
        // three of a possible seven. The gap is what an examiner asks about.
        $this->actingAs($this->officer)
            ->get(route('tprm.monitoring.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $health = $page->toArray()['props']['sourceHealth'];

                $this->assertNotEmpty($health['uncovered_signal_types']);

                $uncovered = collect($health['uncovered_signal_types'])->pluck('value');
                // Nothing produces these without an external feed.
                $this->assertContains(SignalType::CyberRatingChange->value, $uncovered);
                $this->assertContains(SignalType::AdverseMedia->value, $uncovered);
                // These are derived internally, so they are covered.
                $this->assertNotContains(SignalType::EvidenceExpired->value, $uncovered);
            });
    }

    #[Test]
    public function internal_derivation_appears_as_a_source_even_with_no_feeds_configured(): void
    {
        // A panel that omitted it would tell a client with no data budget that
        // they are monitoring nothing, when in fact they are monitoring the
        // nine things their own register already knows.
        $this->actingAs($this->officer)
            ->get(route('tprm.monitoring.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sourceHealth.internal.enabled', true)
                ->has('sourceHealth.internal.signal_types', 10)
                ->where('sourceHealth.external', [])
            );
    }

    #[Test]
    public function the_console_reports_an_unrefreshed_sanctions_list_as_unusable(): void
    {
        // An empty list is the dangerous state: a search of it finds nothing,
        // which looks exactly like a clean result unless somebody says so.
        $this->actingAs($this->officer)
            ->get(route('tprm.monitoring.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $lists = collect($page->toArray()['props']['sourceHealth']['sanctions_lists']);

                $this->assertCount(2, $lists);
                $this->assertTrue($lists->every(fn (array $list) => $list['empty']));
                $this->assertStringContainsString('no entries', $lists->first()['note']);
            });
    }

    #[Test]
    public function a_mute_needs_a_reason_and_an_expiry(): void
    {
        $signal = MonitoringSignal::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'engagement_id' => $this->engagement->id,
            'signal_type' => SignalType::EvidenceExpired->value,
            'severity' => 'medium', 'title' => 'A certificate lapsed',
            'observed_at' => now(), 'dedupe_key' => 'mute-test',
        ]);

        $rule = AlertRule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Expired evidence',
            'signal_types' => [SignalType::EvidenceExpired->value],
            'actions' => [AlertRule::ACTION_NOTIFY],
        ]);

        $alert = app(\App\Services\Tprm\Monitoring\AlertEngine::class)->fire($rule, $signal, $this->engagement);

        $this->actingAs($this->officer)
            ->post(route('tprm.monitoring.alerts.mute', $alert), ['mute_reason' => 'noisy', 'muted_until' => ''])
            ->assertSessionHasErrors(['mute_reason', 'muted_until']);

        $this->actingAs($this->officer)
            ->post(route('tprm.monitoring.alerts.mute', $alert), [
                'mute_reason' => 'The certificate is being renewed and the replacement is with the vendor.',
                'muted_until' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($alert->fresh()->isMuted());
    }

    #[Test]
    public function a_muted_alert_appears_on_the_mute_register(): void
    {
        // Every silenced rule, with why and until when. Muting is how a
        // programme quietly stops covering what it claims to, so the silences
        // are themselves a register.
        $signal = MonitoringSignal::create([
            'organization_id' => $this->organization->id,
            'third_party_id' => $this->vendor->id,
            'signal_type' => SignalType::EvidenceExpired->value,
            'severity' => 'medium', 'title' => 'Lapsed', 'observed_at' => now(),
            'dedupe_key' => 'mute-register-test',
        ]);

        $rule = AlertRule::create([
            'organization_id' => $this->organization->id,
            'name' => 'Expired evidence', 'signal_types' => [SignalType::EvidenceExpired->value],
            'actions' => [AlertRule::ACTION_NOTIFY],
        ]);

        $alert = app(\App\Services\Tprm\Monitoring\AlertEngine::class)->fire($rule, $signal, null);
        $alert->forceFill([
            'status' => 'muted',
            'mute_reason' => 'Renewal in progress with the vendor.',
            'muted_until' => now()->addMonth(),
        ])->save();

        $this->actingAs($this->officer)
            ->get(route('tprm.monitoring.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('muteRegister', 1)
                ->where('muteRegister.0.reason', 'Renewal in progress with the vendor.')
            );
    }

    /* ------------------------------------------------------------------ */
    /*  The screening queue */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_queue_states_what_confirming_a_match_will_do_before_it_is_confirmed(): void
    {
        // A reviewer who learns the consequence afterwards has been ambushed
        // by their own tool.
        $this->pendingMatch();

        $this->actingAs($this->officer)
            ->get(route('tprm.screening.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $match = collect($page->toArray()['props']['pending'])->first();

                $this->assertSame(1, $match['consequence']['engagements']);
                $this->assertStringContainsString('suspends every engagement', $match['consequence']['note']);
                $this->assertStringContainsString('Reg. 38', $match['consequence']['note']);
            });
    }

    #[Test]
    public function a_decision_over_http_requires_a_rationale(): void
    {
        $match = $this->pendingMatch();

        $this->actingAs($this->officer)
            ->post(route('tprm.screening.decide', $match), ['decision' => 'false_positive', 'rationale' => 'no'])
            ->assertSessionHasErrors('rationale');

        $this->actingAs($this->officer)
            ->post(route('tprm.screening.decide', $match), [
                'decision' => 'false_positive',
                'rationale' => 'Different date of birth and nationality; no connection to this entity.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    #[Test]
    public function the_history_screen_exposes_the_providers_own_answer(): void
    {
        // Reg. 35: what has to be retrievable is the provider's answer, not
        // our summary of it.
        ScreeningCheck::create([
            'organization_id' => $this->organization->id,
            'subject_type' => ScreeningCheck::SUBJECT_THIRD_PARTY,
            'subject_id' => $this->vendor->id,
            'provider' => SanctionsList::UNSCR,
            'list_types' => ['sanctions'],
            'run_at' => now()->subYears(4),
            'status' => ScreeningCheck::STATUS_CLEAR,
            'raw_response' => ['list_entry_count' => 742, 'matches' => []],
        ]);

        $this->actingAs($this->officer)
            ->get(route('tprm.screening.history', $this->vendor))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tprm/Screening/History')
                ->has('checks', 1)
                ->where('checks.0.retainable', true)
                ->where('checks.0.raw_response.list_entry_count', 742)
                ->where('retention.years', 5)
            );
    }

    /* ------------------------------------------------------------------ */
    /*  Due diligence */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_checklist_is_scoped_by_tier_and_names_its_blockers(): void
    {
        $this->actingAs($this->officer)
            ->post(route('tprm.due-diligence.generate', $this->engagement))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->officer)
            ->get(route('tprm.due-diligence.show', $this->engagement))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $checklist = $page->toArray()['props']['checklist'];

                $this->assertSame('high', $checklist['tier_at_generation']);
                $this->assertSame(17, $checklist['progress']['total']);
                // A High engagement needs sixteen of the seventeen; the site
                // visit is Critical-only, because requiring one of every
                // vendor teaches people to waive the requirement.
                $this->assertSame(16, $checklist['progress']['mandatory']);
                $this->assertFalse($checklist['can_complete']);
            });
    }

    #[Test]
    public function due_diligence_cannot_complete_while_a_mandatory_item_is_open(): void
    {
        // FR-DDL-09, and the refusal names the items — "cannot complete" with
        // no list teaches a user to look for whoever can override it.
        $checklist = app(DueDiligenceService::class)->generate($this->engagement, $this->officer->id);

        $this->actingAs($this->officer)
            ->post(route('tprm.due-diligence.complete', $checklist))
            ->assertRedirect()
            ->assertSessionHas('error');

        $error = session('error');
        $this->assertStringContainsString('DD-ENT-01', (string) $error);
        $this->assertStringContainsString('16 mandatory item', (string) $error);
    }

    #[Test]
    public function an_item_needing_a_document_will_not_close_without_one(): void
    {
        // FR-DDL-08: no silent skipping. A checklist that can be ticked
        // through is one that gets ticked through.
        $checklist = app(DueDiligenceService::class)->generate($this->engagement, $this->officer->id);
        $item = $checklist->items()->where('code', 'DD-ENT-01')->firstOrFail();

        $this->actingAs($this->officer)
            ->post(route('tprm.due-diligence.items.complete', $item))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(DueDiligenceItem::STATUS_OPEN, $item->fresh()->status);
    }

    #[Test]
    public function a_waiver_needs_a_reason_and_an_expiry_and_then_unblocks(): void
    {
        $checklist = app(DueDiligenceService::class)->generate($this->engagement, $this->officer->id);
        $item = $checklist->items()->where('code', 'DD-ENT-01')->firstOrFail();

        $this->actingAs($this->officer)
            ->post(route('tprm.due-diligence.items.waive', $item), ['reason' => 'n/a', 'expires_at' => ''])
            ->assertSessionHasErrors(['reason', 'expires_at']);

        $this->actingAs($this->officer)
            ->post(route('tprm.due-diligence.items.waive', $item), [
                'reason' => 'The registry search was performed by group compliance and is filed centrally.',
                'expires_at' => now()->addMonths(6)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($item->fresh()->isWaived());
        $this->assertTrue($item->fresh()->isSettled());
    }

    #[Test]
    public function a_lapsed_waiver_blocks_again(): void
    {
        // A waiver that outlived its expiry and still read as settled would be
        // worse than none — it would look like a decision somebody was still
        // standing behind.
        $checklist = app(DueDiligenceService::class)->generate($this->engagement, $this->officer->id);
        $item = $checklist->items()->where('code', 'DD-ENT-01')->firstOrFail();

        $item->forceFill([
            'status' => DueDiligenceItem::STATUS_WAIVED,
            'waiver_reason' => 'Filed centrally.',
            'waiver_approver_id' => $this->officer->id,
            'waiver_expires_at' => now()->subDay()->toDateString(),
        ])->save();

        $this->assertFalse($item->fresh()->isSettled());
        $this->assertTrue($item->fresh()->waiverHasLapsed());
        $this->assertTrue($checklist->fresh()->blockers()->contains(fn ($blocker) => $blocker->is($item)));
    }

    /* ------------------------------------------------------------------ */

    private function pendingMatch(): ScreeningMatch
    {
        $check = ScreeningCheck::create([
            'organization_id' => $this->organization->id,
            'subject_type' => ScreeningCheck::SUBJECT_THIRD_PARTY,
            'subject_id' => $this->vendor->id,
            'provider' => SanctionsList::UNSCR,
            'list_types' => ['sanctions'],
            'run_at' => now(),
            'status' => ScreeningCheck::STATUS_MATCHES,
        ]);

        return ScreeningMatch::create([
            'organization_id' => $this->organization->id,
            'check_id' => $check->getKey(),
            'list_name' => 'UN Security Council Consolidated List',
            'matched_name' => 'Cloudspan Nigeria Ltd',
            'match_score' => 92.0,
        ]);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => Str::of($roleName)->afterLast('-')->ucfirst()->toString(),
            'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate($roleName, 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }
}
