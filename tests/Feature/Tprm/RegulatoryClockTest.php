<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\Regulator;
use App\Enums\Tprm\RiskTier;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Incident;
use App\Models\Tprm\IncidentEscalation;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\NotificationDraft;
use App\Models\Tprm\ThirdParty;
use App\Models\Tprm\TprmSetting;
use App\Models\User;
use App\Services\Tprm\Incidents\ClockEscalationService;
use App\Services\Tprm\Incidents\NotificationDraftService;
use App\Services\Tprm\Incidents\ObligationClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * AC-07 — the regulatory clocks.
 *
 * "Recording a vendor personal-data breach starts a 72-hour NDPC countdown
 * and, where the CBN cyber-incident definition is met, a parallel 24-hour
 * countdown; both render as countdowns, both escalate at 50% and 80% elapsed,
 * and both produce a pre-filled notification draft."
 *
 * And the phase's own criterion: the two drafts "cannot be marked submitted
 * without an explicit approval action by a permissioned user".
 */
class RegulatoryClockTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $owner;

    private User $sponsor;

    private User $officer;

    private Engagement $engagement;

    private ThirdParty $vendor;

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

        $this->owner = $this->user('owner@lub.test');
        $this->sponsor = $this->user('sponsor@lub.test');
        $this->officer = $this->user('compliance@lub.test');

        TenantContext::set($this->bank->id);

        $this->vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $this->engagement = $this->makeEngagement();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  AC-07 — both clocks */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_personal_data_breach_starts_both_clocks(): void
    {
        $incident = $this->reportBreach();

        $assessments = app(ObligationClockService::class)->assess($incident);

        $this->assertTrue($assessments['ndpc']->reportable);
        $this->assertTrue($assessments['cbn']->reportable);

        $incident->refresh();

        // 72 and 24 hours FROM WHEN WE BECAME AWARE, which is when the vendor
        // told us — not from when they detected it.
        $this->assertEquals(
            $incident->reported_to_us_at->copy()->addHours(72),
            $incident->ndpc_deadline_at,
        );
        $this->assertEquals(
            $incident->reported_to_us_at->copy()->addHours(24),
            $incident->cbn_deadline_at,
        );
    }

    #[Test]
    public function the_clock_runs_from_when_we_were_told_not_from_when_they_detected(): void
    {
        $incident = $this->reportBreach(detectedHoursAgo: 200);

        app(ObligationClockService::class)->assess($incident);
        $incident->refresh();

        // A clock started from the vendor's own detection would already be
        // expired at the moment it was created, against a period in which the
        // bank could not have acted.
        $this->assertTrue($incident->ndpc_deadline_at->isFuture());
        $this->assertEquals(
            $incident->reported_to_us_at->copy()->addHours(72),
            $incident->ndpc_deadline_at,
        );
    }

    #[Test]
    public function both_clocks_render_as_countdowns_with_state(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $countdowns = app(ObligationClockService::class)->countdowns($incident->refresh());

        $this->assertCount(2, $countdowns);

        foreach ($countdowns as $countdown) {
            $this->assertSame('running', $countdown['state']);
            $this->assertGreaterThan(0, $countdown['hours_remaining']);
            $this->assertLessThan(50, $countdown['elapsed_pct']);
        }
    }

    #[Test]
    public function a_breached_clock_reports_hours_past_rather_than_stopping_at_zero(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $countdown = app(ObligationClockService::class)->countdown(
            $incident->refresh(),
            Regulator::Cbn,
            $incident->reported_to_us_at->copy()->addHours(30),
        );

        // The bar stops at full and the number keeps counting: the window is
        // gone, AND it went six hours ago. Both are true and only one of them
        // is actionable.
        $this->assertSame(100.0, $countdown['elapsed_pct']);
        $this->assertSame(-6.0, $countdown['hours_remaining']);
        $this->assertSame('breached', $countdown['state']);
        $this->assertTrue($countdown['breached']);
    }

    /* ------------------------------------------------------------------ */
    /*  The materiality test that cannot be guessed */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_uncomputable_materiality_test_is_undetermined_and_not_a_quiet_no(): void
    {
        // A pure financial-loss incident: no personal data, no outage. The
        // only limb available is the 0.01% test, and shareholders' funds have
        // not been set.
        $incident = $this->report([
            'type' => 'fraud',
            'title' => 'Funds diverted through a compromised vendor account',
            'personal_data_involved' => false,
            'customer_impact' => false,
            'estimated_loss_minor' => 250_000_00,
            'currency' => 'NGN',
        ]);

        $assessment = app(ObligationClockService::class)->assessCbn($incident);

        $this->assertTrue($assessment->undetermined);
        $this->assertFalse($assessment->reportable);
        $this->assertNull($assessment->deadlineAt);

        // Silently answering "not reportable" is a missed 24-hour deadline
        // nobody knows about until the supervisor asks.
        $this->assertStringContainsString('cannot be run', $assessment->rationale);
        $this->assertStringContainsString('shareholders\' funds', $assessment->rationale);
    }

    #[Test]
    public function with_shareholders_funds_set_the_materiality_test_decides(): void
    {
        TprmSetting::forOrganization($this->bank->id)->forceFill([
            'shareholders_funds_minor' => 100_000_000_00,
            'shareholders_funds_currency' => 'NGN',
            'shareholders_funds_as_at' => now()->subMonths(3)->toDateString(),
        ])->save();

        // 0.01% of 100,000,000.00 is 10,000.00.
        $under = $this->report([
            'type' => 'fraud', 'title' => 'A small loss',
            'estimated_loss_minor' => 900_000, 'currency' => 'NGN',
        ]);
        $over = $this->report([
            'type' => 'fraud', 'title' => 'A material loss',
            'estimated_loss_minor' => 1_100_000, 'currency' => 'NGN',
        ]);

        $this->assertFalse(app(ObligationClockService::class)->assessCbn($under)->reportable);
        $this->assertTrue(app(ObligationClockService::class)->assessCbn($over)->reportable);
    }

    #[Test]
    public function a_data_breach_is_cbn_reportable_even_without_the_materiality_figure(): void
    {
        // Four limbs, any one is enough. A missing figure only leaves the
        // assessment undetermined when materiality was the last limb left.
        $incident = $this->reportBreach();

        $assessment = app(ObligationClockService::class)->assessCbn($incident);

        $this->assertTrue($assessment->reportable);
        $this->assertFalse($assessment->undetermined);
        $this->assertContains('data_breach', $assessment->triggers);
    }

    /* ------------------------------------------------------------------ */
    /*  Escalation at 50% and 80% */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function each_clock_escalates_at_fifty_and_eighty_percent(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $start = $incident->refresh()->reported_to_us_at;

        // 13 hours into a 24-hour CBN clock: past 50%, short of 80%.
        app(ClockEscalationService::class)->sweep($this->bank->id, $start->copy()->addHours(13));

        $this->assertSame(1, IncidentEscalation::query()
            ->where('regulator', Regulator::Cbn->value)->count());

        // 20 hours: past 80% too.
        app(ClockEscalationService::class)->sweep($this->bank->id, $start->copy()->addHours(20));

        $thresholds = IncidentEscalation::query()
            ->where('regulator', Regulator::Cbn->value)
            ->pluck('threshold_pct')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([50, 80], $thresholds);
    }

    #[Test]
    public function an_escalation_fires_once_however_often_the_sweep_runs(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $at = $incident->refresh()->reported_to_us_at->copy()->addHours(20);

        app(ClockEscalationService::class)->sweep($this->bank->id, $at);
        $second = app(ClockEscalationService::class)->sweep($this->bank->id, $at->copy()->addMinutes(15));
        $third = app(ClockEscalationService::class)->sweep($this->bank->id, $at->copy()->addMinutes(30));

        // An inbox full of the same warning is an inbox in which the 80% one
        // is not noticed.
        $this->assertSame(0, $second['escalated']);
        $this->assertSame(0, $third['escalated']);
    }

    #[Test]
    public function the_eighty_percent_escalation_reaches_the_executive_sponsor(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $start = $incident->refresh()->reported_to_us_at;

        app(ClockEscalationService::class)->sweep($this->bank->id, $start->copy()->addHours(13));
        app(ClockEscalationService::class)->sweep($this->bank->id, $start->copy()->addHours(20));

        $at50 = IncidentEscalation::query()->where('threshold_pct', 50)
            ->where('regulator', Regulator::Cbn->value)->firstOrFail();
        $at80 = IncidentEscalation::query()->where('threshold_pct', 80)
            ->where('regulator', Regulator::Cbn->value)->firstOrFail();

        // Halfway is a reminder to the owner; four hours from a supervisory
        // deadline is a matter for whoever can sign the notification. An
        // escalation to the same person twice is not an escalation.
        $this->assertSame([$this->owner->id], $at50->notified_user_ids);
        $this->assertContains($this->sponsor->id, $at80->notified_user_ids);
    }

    #[Test]
    public function a_reported_clock_stops_escalating(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $draft = app(NotificationDraftService::class)->build($incident->refresh(), Regulator::Cbn);
        app(NotificationDraftService::class)->approve($draft, $this->officer);
        app(NotificationDraftService::class)->recordSubmission($draft, $this->officer, 'CBN/2026/0912');

        $start = $incident->refresh()->reported_to_us_at;

        app(ClockEscalationService::class)->sweep($this->bank->id, $start->copy()->addHours(20));

        $this->assertSame(0, IncidentEscalation::query()
            ->where('regulator', Regulator::Cbn->value)->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Two drafts, neither submittable without approval */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function both_clocks_produce_a_distinct_pre_filled_draft(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $drafts = app(NotificationDraftService::class)->buildDue($incident->refresh());

        $this->assertCount(2, $drafts);

        $regulators = collect($drafts)->map(fn (NotificationDraft $d) => $d->regulator->value)->sort()->values();
        $this->assertSame(['cbn', 'ndpc'], $regulators->all());

        // Distinct documents, addressed to different people, citing different
        // instruments — not one draft with a recipient field.
        $ndpc = collect($drafts)->firstWhere('regulator', Regulator::Ndpc);
        $cbn = collect($drafts)->firstWhere('regulator', Regulator::Cbn);

        $this->assertStringContainsString('National Commissioner', $ndpc->body);
        $this->assertStringContainsString('Director, Banking Supervision', $cbn->body);
        $this->assertNotSame($ndpc->body, $cbn->body);
    }

    #[Test]
    public function a_draft_cannot_be_marked_submitted_without_an_approval(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $draft = app(NotificationDraftService::class)->build($incident->refresh(), Regulator::Ndpc);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/has to be approved/');

        app(NotificationDraftService::class)->recordSubmission($draft, $this->officer, 'NDPC/2026/1');
    }

    #[Test]
    public function recording_a_submission_stops_that_clock_and_only_that_clock(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $service = app(NotificationDraftService::class);
        $draft = $service->build($incident->refresh(), Regulator::Ndpc);
        $service->approve($draft, $this->officer);
        $service->recordSubmission($draft, $this->officer, 'NDPC/2026/0031');

        $incident->refresh();

        $this->assertNotNull($incident->ndpc_reported_at);
        // The CBN clock is a different obligation and is still running.
        $this->assertNull($incident->cbn_reported_at);

        $clocks = app(ObligationClockService::class);
        $this->assertSame('reported', $clocks->countdown($incident, Regulator::Ndpc)['state']);
        $this->assertSame('running', $clocks->countdown($incident, Regulator::Cbn)['state']);
    }

    #[Test]
    public function the_draft_lists_what_a_regulator_will_ask_that_we_cannot_answer(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $draft = app(NotificationDraftService::class)->build($incident->refresh(), Regulator::Ndpc);

        // A document that reads fluently while omitting the number of data
        // subjects invites somebody to send it as it stands.
        $this->assertNotEmpty($draft->gaps);
        $this->assertStringContainsString('NOT YET ESTABLISHED', $draft->body);
        $this->assertTrue(collect($draft->gaps)->contains(
            fn (string $gap): bool => str_contains($gap, 'data subjects'),
        ));
    }

    #[Test]
    public function rebuilding_an_approved_draft_clears_the_approval(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $service = app(NotificationDraftService::class);
        $draft = $service->build($incident->refresh(), Regulator::Ndpc);
        $service->approve($draft, $this->officer);

        $incident->forceFill(['root_cause' => 'A reused credential on a support account.'])->save();

        $rebuilt = $service->build($incident->refresh(), Regulator::Ndpc);

        // The officer approved words that no longer exist. Carrying their name
        // onto new text is a signature on a document they never read.
        $this->assertNull($rebuilt->approved_at);
        $this->assertSame(NotificationDraft::STATUS_DRAFT, $rebuilt->status);
    }

    #[Test]
    public function a_submitted_draft_is_never_rewritten(): void
    {
        $incident = $this->reportBreach();
        app(ObligationClockService::class)->assess($incident);

        $service = app(NotificationDraftService::class);
        $draft = $service->build($incident->refresh(), Regulator::Cbn);
        $service->approve($draft, $this->officer);
        $service->recordSubmission($draft, $this->officer, 'CBN/2026/0044');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Raise a follow-up/');

        $service->build($incident->refresh(), Regulator::Cbn);
    }

    #[Test]
    public function reassessing_never_retracts_a_clock_already_reported_against(): void
    {
        $incident = $this->reportBreach();
        $clocks = app(ObligationClockService::class);
        $clocks->assess($incident);

        $service = app(NotificationDraftService::class);
        $draft = $service->build($incident->refresh(), Regulator::Ndpc);
        $service->approve($draft, $this->officer);
        $service->recordSubmission($draft, $this->officer, 'NDPC/2026/9');

        // Somebody corrects the facts to say no personal data was involved.
        $incident->refresh()->forceFill(['personal_data_involved' => false])->save();

        $clocks->assess($incident->refresh());

        // A module that could decide an incident it had already notified on
        // was not reportable after all is worse than one that never assessed.
        $this->assertTrue($incident->refresh()->ndpc_reportable);
        $this->assertNotNull($incident->ndpc_reported_at);
    }

    /* ------------------------------------------------------------------ */
    /*  Fixtures */
    /* ------------------------------------------------------------------ */

    private function reportBreach(int $detectedHoursAgo = 6): Incident
    {
        return $this->report([
            'type' => 'data_breach',
            'title' => 'Unauthorised access to a customer support mailbox',
            'personal_data_involved' => true,
            'customer_impact' => true,
            'detected_at' => now()->subHours($detectedHoursAgo),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function report(array $attributes): Incident
    {
        $incident = Incident::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $this->vendor->id,
            'engagement_ids' => [$this->engagement->id],
            'reference' => 'TPI-2026-'.Str::upper(Str::random(4)),
            'description' => 'Recorded for the AC-07 fixtures.',
        ] + $attributes);

        $incident->forceFill([
            'reported_to_us_at' => now(),
            'reported_by' => Incident::SOURCE_PORTAL,
        ])->save();

        return $incident->refresh();
    }

    private function makeEngagement(): Engagement
    {
        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $this->vendor->id,
            'reference' => 'ENG-'.Str::upper(Str::random(6)),
            'name' => 'Core banking hosting',
            'service_description' => 'Fixture.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->owner->id,
            'executive_sponsor_id' => $this->sponsor->id,
        ]);

        $engagement->forceFill([
            'status' => EngagementStatus::Active->value,
            'inherent_score' => 82,
            'inherent_tier' => RiskTier::Critical->value,
            'effective_tier' => RiskTier::Critical->value,
        ])->save();

        InherentAssessment::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $engagement->id,
            'version' => 1, 'ruleset_version' => '1.0.0',
            'raw_score' => 82, 'resulting_tier' => RiskTier::Critical->value,
            'assessed_at' => now(), 'is_current' => true,
            'answers' => [], 'factor_scores' => [], 'weights' => [], 'explanation' => [],
        ]);

        return $engagement->refresh();
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
            'email_verified_at' => now(),
            'organization_id' => $this->bank->id,
            'is_active' => true,
        ]);
    }
}
