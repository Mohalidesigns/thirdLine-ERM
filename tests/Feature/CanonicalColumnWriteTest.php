<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\Issue;
use App\Models\LossEvent;
use App\Models\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * WP-01 TASK 1 acceptance.
 *
 * Two things have to hold after the consolidation:
 *   1. the writers touch the canonical column and nothing else — the
 *      deprecated duplicate stays NULL, which is what makes it safe to drop
 *      in Migration B;
 *   2. the read-only accessors still answer to the old names, so the Blade
 *      views that were never touched keep rendering.
 *
 * The loss-event case also covers the regulatory bug the case normalisation
 * fixed: basel_l1_category written in lower case meant the NFIU STR, EFCC and
 * cyber-fraud alerts never fired. See docs/schema/canonical-columns.md.
 */
class CanonicalColumnWriteTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    private BusinessUnit $businessUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();

        $this->businessUnit = BusinessUnit::create([
            'organization_id' => $this->organization->id,
            'name' => 'Operations',
            'code' => 'OPS',
        ]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->actor->assignRole('super-admin');
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  loss_events */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function creating_a_loss_event_writes_canonical_columns_only(): void
    {
        $this->post(route('risk.loss-events.store'), $this->lossEventPayload())
            ->assertRedirect();

        $event = LossEvent::latest('id')->firstOrFail();

        // Canonical
        $this->assertSame('Wire transfer fraud', $event->title);
        $this->assertSame('Unauthorised outbound transfer', $event->description);
        $this->assertSame('INTERNAL_FRAUD', $event->basel_l1_category);
        $this->assertSame('OPERATIONAL', $event->cbn_risk_category);
        $this->assertSame('MAJOR', $event->event_severity);
        $this->assertSame('actual_loss', $event->loss_category);
        $this->assertSame('REPORTED', $event->current_status);
        $this->assertSame('Weak dual-control', $event->initial_root_cause);
        // Money in kobo.
        $this->assertSame(750_000_000, (int) $event->gross_loss_amount_kobo);
        $this->assertSame(100_000_000, (int) $event->insurance_recovery_kobo);
        $this->assertSame(50_000_000, (int) $event->other_recovery_kobo);

        // Deprecated duplicates untouched — this is what makes Migration B safe.
        $raw = DB::table('loss_events')->where('id', $event->id)->first();
        foreach ([
            'event_title', 'event_description', 'status', 'gross_loss_amount',
            'recovery_amount', 'insurance_recovery', 'net_loss_amount',
            'basel_event_type', 'cbn_loss_category', 'event_type', 'severity',
            'root_cause_summary',
        ] as $deprecated) {
            $this->assertNull($raw->{$deprecated}, "deprecated column {$deprecated} was written");
        }
    }

    #[Test]
    public function the_accessors_still_answer_to_the_deprecated_names(): void
    {
        $this->post(route('risk.loss-events.store'), $this->lossEventPayload());

        $event = LossEvent::latest('id')->firstOrFail();

        $this->assertSame('Wire transfer fraud', $event->event_title);
        $this->assertSame('Unauthorised outbound transfer', $event->event_description);
        // Lower-cased back to the contract the views and forms use.
        $this->assertSame('reported', $event->status);
        $this->assertSame('major', $event->severity);
        $this->assertSame('internal_fraud', $event->basel_event_type);
        $this->assertSame('actual_loss', $event->event_type);
        $this->assertSame('Weak dual-control', $event->root_cause_summary);
        // Naira, from kobo.
        $this->assertSame(7_500_000.0, (float) $event->gross_loss_amount);
        $this->assertSame(1_000_000.0, (float) $event->insurance_recovery);
        $this->assertSame(500_000.0, (float) $event->recovery_amount);
        $this->assertSame(6_000_000.0, (float) $event->net_loss_amount);
    }

    #[Test]
    public function a_fraud_event_created_through_the_ui_now_raises_its_nfiu_and_efcc_alerts(): void
    {
        // The regression this fixes: basel_l1_category was written
        // 'internal_fraud' while RegulatoryThresholdService matches
        // 'INTERNAL_FRAUD', so none of these alerts ever fired.
        $this->post(route('risk.loss-events.store'), $this->lossEventPayload())
            ->assertSessionHas('regulatory_alerts');

        $types = array_column(session('regulatory_alerts'), 'type');

        $this->assertContains('NFIU_STR_REQUIRED', $types);
        $this->assertContains('EFCC_REPORTING_RECOMMENDED', $types);
        $this->assertContains('CBN_REPORTING_REQUIRED', $types);

        $event = LossEvent::latest('id')->firstOrFail();
        $this->assertTrue((bool) $event->nfiu_reportable);
        $this->assertTrue((bool) $event->cbn_reportable);
    }

    #[Test]
    public function net_loss_aggregates_read_the_kobo_columns(): void
    {
        // sum() runs in the database, so it cannot go through the accessor.
        // Before the consolidation this summed a naira column that only the
        // CRUD screen ever wrote.
        $this->makeLossEvent([
            'gross_loss_amount_kobo' => 900_000_00,
            'insurance_recovery_kobo' => 200_000_00,
            'other_recovery_kobo' => 100_000_00,
        ]);
        $this->makeLossEvent(['gross_loss_amount_kobo' => 100_000_00]);

        $total = (float) LossEvent::where('organization_id', $this->organization->id)
            ->sum(LossEvent::netLossNairaSql());

        $this->assertSame(700_000.0, $total);
    }

    /* ------------------------------------------------------------------ */
    /*  treatment_plans */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function creating_a_treatment_plan_writes_canonical_columns_only(): void
    {
        $risk = $this->makeRisk(['status' => 'active']);

        $this->post(route('risk.treatments.store'), [
            'risk_id' => $risk->id,
            'treatment_title' => 'Introduce dual authorisation',
            'treatment_description' => 'Second approver on outbound wires',
            'treatment_type' => 'mitigate',
            'treatment_owner_id' => $this->actor->id,
            'priority' => 'high',
            'target_completion_date' => now()->addMonth()->toDateString(),
            'estimated_cost' => 2_500_000,
        ])->assertRedirect();

        $plan = TreatmentPlan::latest('id')->firstOrFail();

        $this->assertSame('Introduce dual authorisation', $plan->action_title);
        $this->assertSame('Second approver on outbound wires', $plan->action_description);
        $this->assertSame('mitigate', $plan->strategy);
        $this->assertSame($this->actor->id, $plan->owner_id);
        $this->assertSame('2500000.00', $plan->cost_estimate_ngn);
        $this->assertSame(0, $plan->progress_pct);

        $raw = DB::table('treatment_plans')->where('id', $plan->id)->first();
        foreach ([
            'treatment_title', 'treatment_description', 'treatment_type',
            'treatment_owner_id', 'target_completion_date', 'estimated_cost',
        ] as $deprecated) {
            $this->assertNull($raw->{$deprecated}, "deprecated column {$deprecated} was written");
        }

        // Bridges still resolve.
        $this->assertSame('Introduce dual authorisation', $plan->treatment_title);
        $this->assertSame('mitigate', $plan->treatment_type);
        $this->assertSame($this->actor->id, $plan->treatment_owner_id);
        $this->assertSame(0, $plan->progress_percentage);
    }

    /* ------------------------------------------------------------------ */
    /*  issues */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function issue_bridges_resolve_to_the_canonical_columns(): void
    {
        $issue = Issue::create([
            'organization_id' => $this->organization->id,
            'issue_reference' => 'ISS-TEST-0001',
            'title' => 'Reconciliation backlog',
            'description' => 'Nostro accounts unreconciled for 40 days',
            'issue_source' => 'audit',
            'issue_category' => 'process',
            'priority' => 'high',
            'issue_status' => 'OPEN',
            'responsible_owner_id' => $this->actor->id,
            'remediation_due_date' => '2026-09-30',
            'actual_close_date' => '2026-09-15',
            'examination_ref' => 'IA-2026-11',
            'current_escalation_level' => 2,
            'created_by' => $this->actor->id,
        ]);

        $issue->refresh();

        $this->assertSame('Reconciliation backlog', $issue->issue_title);
        $this->assertSame('Nostro accounts unreconciled for 40 days', $issue->issue_description);
        $this->assertSame($this->actor->id, $issue->issue_owner_id);
        $this->assertSame('2026-09-30', $issue->target_resolution_date->toDateString());
        $this->assertSame('2026-09-15', $issue->actual_resolution_date->toDateString());
        $this->assertSame('IA-2026-11', $issue->source_reference);
        $this->assertSame(2, $issue->escalation_level);

        // Mass assignment can no longer reach the deprecated columns.
        $raw = DB::table('issues')->where('id', $issue->id)->first();
        $this->assertNull($raw->issue_title);
        $this->assertNull($raw->target_resolution_date);
    }

    #[Test]
    public function the_deprecated_issue_columns_are_not_mass_assignable(): void
    {
        $issue = Issue::create([
            'organization_id' => $this->organization->id,
            'issue_reference' => 'ISS-TEST-0002',
            'title' => 'Canonical title',
            'description' => 'Body',
            'issue_source' => 'audit',
            'issue_category' => 'process',
            'priority' => 'low',
            'issue_status' => 'OPEN',
            'created_by' => $this->actor->id,
            // Both of these must be ignored rather than written.
            'issue_title' => 'Duplicate title',
            'target_resolution_date' => '2026-12-31',
        ]);

        $raw = DB::table('issues')->where('id', $issue->id)->first();

        $this->assertSame('Canonical title', $raw->title);
        $this->assertNull($raw->issue_title);
        $this->assertNull($raw->target_resolution_date);
    }

    /* ------------------------------------------------------------------ */

    private function lossEventPayload(): array
    {
        return [
            'event_title' => 'Wire transfer fraud',
            'event_description' => 'Unauthorised outbound transfer',
            'date_of_loss' => now()->subDays(5)->toDateString(),
            'date_discovered' => now()->subDays(2)->toDateString(),
            'business_unit_id' => $this->businessUnit->id,
            'reported_by' => $this->actor->id,
            'basel_event_type' => 'internal_fraud',
            'cbn_loss_category' => 'operational',
            'event_type' => 'actual_loss',
            'severity' => 'major',
            // Above the CBN (NGN 5m), EFCC (NGN 1m) and NDIC (NGN 500k)
            // thresholds, so a fraud event here must raise all three.
            'gross_loss_amount' => 7_500_000,
            'recovery_amount' => 500_000,
            'insurance_recovery' => 1_000_000,
            'currency' => 'NGN',
            'root_cause_summary' => 'Weak dual-control',
        ];
    }
}
