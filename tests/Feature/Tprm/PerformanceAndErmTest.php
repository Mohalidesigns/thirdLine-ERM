<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Enums\Tprm\RiskTier;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\Finding;
use App\Models\Tprm\Incident;
use App\Models\Tprm\InherentAssessment;
use App\Models\Tprm\ServiceReview;
use App\Models\Tprm\Sla;
use App\Models\Tprm\SlaMeasurement;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Incidents\IncidentErmBridge;
use App\Services\Tprm\Performance\ServiceReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Service reviews (FR-PRF) and the incident's route into the ERM loss
 * register (Phase 9, build item 3).
 *
 * THE ERM HALF IS WHY THIS MODULE IS PART OF THE ERM SOLUTION rather than a
 * silo beside it — the user's standing requirement. A third-party incident
 * that cost money is an operational loss whatever caused it, and a bank whose
 * ORM figures excluded vendor losses would under-report on its CBN ORMS
 * return.
 */
class PerformanceAndErmTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $owner;

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

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@lub.test',
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

    /* ------------------------------------------------------------------ */
    /*  Service reviews */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_review_that_did_not_happen_becomes_a_finding(): void
    {
        app(ServiceReviewService::class)->schedule($this->engagement, now()->subWeek());

        $result = app(ServiceReviewService::class)->markMissed($this->bank->id);

        $this->assertSame(1, $result['missed']);
        $this->assertSame(1, $result['findings']);

        $finding = Finding::query()->where('title', 'Service review not held')->firstOrFail();

        // A review that does not happen is not a gap in a diary: it is the
        // control that would have caught a degrading service.
        $this->assertStringContainsString('would have caught a degrading service', $finding->description);
    }

    #[Test]
    public function holding_a_review_snapshots_the_pack_and_schedules_the_next(): void
    {
        $this->slaWithMeasurements(breaches: 1);

        $review = app(ServiceReviewService::class)->schedule($this->engagement, now());

        app(ServiceReviewService::class)->hold($review, [
            'held_on' => now()->toDateString(),
            'minutes' => 'Discussed the availability miss in August.',
            'attendees' => ['Head of Operations', 'Vendor account manager'],
        ], $this->owner->id);

        $review->refresh();

        $this->assertSame(ServiceReview::STATUS_HELD, $review->status);

        // A minute referring to "the SLA pack" is worthless once the
        // measurements have moved on.
        $this->assertNotNull($review->performance_snapshot);
        $this->assertSame(1, $review->performance_snapshot['breached_count']);

        // A programme that schedules the next review when somebody remembers
        // is a programme with a gap in it.
        $this->assertSame(1, ServiceReview::query()
            ->where('status', ServiceReview::STATUS_SCHEDULED)->count());
    }

    #[Test]
    public function the_pack_names_an_unmeasured_service_level_rather_than_passing_it(): void
    {
        // An SLA with a target and no measurement at all — the row a vendor
        // benefits from nobody noticing.
        Sla::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $this->engagement->id,
            'metric_code' => 'RESOLUTION',
            'metric_name' => 'P1 resolution time',
            'unit' => 'hours',
            'target_operator' => '<=',
            'target_value' => 4,
            'measurement_window' => 'monthly',
        ]);

        $pack = app(ServiceReviewService::class)->performancePack($this->engagement);

        $this->assertSame(1, $pack['unmeasured_count']);
        $this->assertTrue($pack['slas'][0]['unmeasured']);
        $this->assertStringContainsString('not a met target', $pack['note']);
    }

    #[Test]
    public function an_unrecognised_target_operator_is_not_treated_as_a_pass(): void
    {
        $sla = Sla::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $this->engagement->id,
            'metric_code' => 'ODD',
            'metric_name' => 'A metric with a strange operator',
            'unit' => '%',
            'target_operator' => 'approximately',
            'target_value' => 99,
            'measurement_window' => 'monthly',
        ]);

        SlaMeasurement::create([
            'organization_id' => $this->bank->id,
            'sla_id' => $sla->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'actual_value' => 99.9,
        ]);

        $pack = app(ServiceReviewService::class)->performancePack($this->engagement);

        // Treating an unknown operator as a pass would silently mark every
        // measurement compliant.
        $this->assertSame(1, $pack['slas'][0]['periods_missed']);
    }

    /* ------------------------------------------------------------------ */
    /*  The ERM loss register */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_incident_with_a_loss_reaches_the_erm_loss_register(): void
    {
        $incident = $this->incident([
            'type' => 'outage',
            'estimated_loss_minor' => 4_500_000_00,
            'currency' => 'NGN',
        ]);

        $result = app(IncidentErmBridge::class)->mirror($incident);

        $this->assertNotNull($result['loss_event']);

        $lossEvent = $result['loss_event'];

        // The Basel category is the honest one, not "Other" — that is the
        // category that makes a capital calculation meaningless.
        $this->assertSame('Business Disruption and System Failures', $lossEvent->basel_l1_category);
        $this->assertSame(4_500_000_00, (int) $lossEvent->gross_loss_amount_kobo);
        $this->assertStringContainsString('Cloudspan', $lossEvent->title);

        $this->assertSame($lossEvent->getKey(), $incident->refresh()->erm_loss_event_id);
    }

    #[Test]
    public function mirroring_twice_updates_rather_than_duplicating(): void
    {
        $incident = $this->incident([
            'type' => 'outage', 'estimated_loss_minor' => 1_000_000_00, 'currency' => 'NGN',
        ]);

        app(IncidentErmBridge::class)->mirror($incident);

        // Amounts move as an investigation proceeds; the event does not become
        // a second event. A duplicated loss overstates operational risk capital.
        $incident->refresh()->forceFill(['estimated_loss_minor' => 3_000_000_00])->save();

        app(IncidentErmBridge::class)->mirror($incident->refresh());

        $this->assertSame(1, LossEvent::query()->count());
        $this->assertSame(3_000_000_00, (int) LossEvent::query()->first()->gross_loss_amount_kobo);
    }

    #[Test]
    public function an_incident_with_no_loss_is_not_a_loss_event(): void
    {
        $incident = $this->incident(['type' => 'outage']);

        $result = app(IncidentErmBridge::class)->mirror($incident);

        // Mirroring it would fill the ORM register with zero-value rows and
        // make the loss distribution wrong.
        $this->assertNull($result['loss_event']);
        $this->assertStringContainsString('No financial loss', $result['reason']);
        $this->assertSame(0, LossEvent::query()->count());
    }

    #[Test]
    public function an_unmappable_incident_type_is_refused_rather_than_guessed(): void
    {
        $incident = $this->incident([
            'type' => 'reputational', 'estimated_loss_minor' => 500_000_00, 'currency' => 'NGN',
        ]);

        $result = app(IncidentErmBridge::class)->mirror($incident);

        // Basel categories drive capital. A wrong one is worse than a missing
        // one, because it reaches the ORMS return.
        $this->assertNull($result['loss_event']);
        $this->assertStringContainsString('Classify it by hand', $result['reason']);
    }

    /* ------------------------------------------------------------------ */

    private function slaWithMeasurements(int $breaches): Sla
    {
        $sla = Sla::create([
            'organization_id' => $this->bank->id,
            'engagement_id' => $this->engagement->id,
            'metric_code' => 'AVAIL',
            'metric_name' => 'Platform availability',
            'unit' => '%',
            'target_operator' => '>=',
            'target_value' => 99.5,
            'measurement_window' => 'monthly',
        ]);

        foreach (range(1, 3) as $index) {
            SlaMeasurement::create([
                'organization_id' => $this->bank->id,
                'sla_id' => $sla->id,
                'period_start' => now()->subMonths($index)->startOfMonth()->toDateString(),
                'period_end' => now()->subMonths($index)->endOfMonth()->toDateString(),
                'actual_value' => $index <= $breaches ? 98.1 : 99.9,
            ]);
        }

        return $sla;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function incident(array $attributes): Incident
    {
        $incident = Incident::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $this->vendor->id,
            'engagement_ids' => [$this->engagement->id],
            'reference' => 'TPI-2026-'.Str::upper(Str::random(4)),
            'title' => 'A service failure at the provider',
            'description' => 'Fixture.',
            'detected_at' => now()->subHours(4),
        ] + $attributes);

        $incident->forceFill([
            'reported_to_us_at' => now(),
            'reported_by' => Incident::SOURCE_PORTAL,
        ])->save();

        return $incident->refresh();
    }

    private function makeEngagement(): Engagement
    {
        $this->vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => 'Cloudspan Nigeria Limited',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $this->vendor->id,
            'reference' => 'ENG-'.Str::upper(Str::random(6)),
            'name' => 'Core banking hosting',
            'service_description' => 'Fixture.',
            'engagement_type' => 'ict_service',
            'relationship_owner_id' => $this->owner->id,
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
