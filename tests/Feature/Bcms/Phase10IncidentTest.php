<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\IncidentLogEntryType;
use App\Enums\Bcms\IncidentSeverity;
use App\Enums\Bcms\IncidentStatus;
use App\Events\LossEventAmountChanged;
use App\Events\LossEventCreated;
use App\Models\Bcms\Aar;
use App\Models\Bcms\Incident;
use App\Models\Bcms\IncidentNotification;
use App\Models\Bcms\Plan;
use App\Models\BusinessUnit;
use App\Models\LossEvent;
use App\Models\Organization;
use App\Models\RiskAuditTrail;
use App\Models\User;
use App\Services\Bcms\Exercises\AarService;
use App\Services\Bcms\Incidents\IncidentService;
use App\Services\Bcms\Incidents\NotificationService;
use App\Services\Bcms\Incidents\PirService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 10 — incident declaration through stand-down, the regulatory
 * notification clocks (ADR 0020 §2), and the post-incident review sharing
 * `bcms_aars` (ADR 0020 §1).
 */
class Phase10IncidentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);

        $this->organization = Organization::create([
            'name' => 'Kano Heritage Bank', 'short_name' => 'KHB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($this->organization->id);

        $this->unit = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $this->officer = $this->user('officer@khb.test');

        foreach (['bcms.incident.view', 'bcms.incident.declare', 'bcms.incident.manage', 'bcms.incident.notify', 'bcms.plan.activate', 'bcms.report.export'] as $p) {
            $this->givePermission($this->officer, $p);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  ADR 0020 §1 — the schema */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_aar_table_still_refuses_two_reports_on_one_occurrence_and_now_accepts_a_pir(): void
    {
        $occurrence = $this->fakeOccurrenceId();

        $first = Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);
        $this->assertNotNull($first->id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Aar::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);
    }

    #[Test]
    public function a_pir_row_has_incident_id_and_null_occurrence_id_and_is_addressable_that_way(): void
    {
        $incident = $this->declareIncident();

        $pir = app(PirService::class)->ensureDraftFor($incident);

        $this->assertNull($pir->occurrence_id);
        $this->assertSame($incident->getKey(), $pir->incident_id);
        $this->assertTrue($pir->isPostIncident());
        $this->assertSame($incident->getKey(), $pir->subject()->getKey());

        // Idempotent — a second call returns the same row.
        $again = app(PirService::class)->ensureDraftFor($incident);
        $this->assertSame($pir->getKey(), $again->getKey());

        // The unique index on incident_id still holds — see Phase10SchemaTest
        // for the dedicated FK/unique-index assertions and the raw-insert
        // duplicate-refusal case.
        $this->assertSame(1, Aar::query()->where('incident_id', $incident->getKey())->count());
    }

    /**
     * Gate 1 re-gate defect 6: `AarAiDrafter::draft()` ran exercise AI
     * synthesis (`AAR_SYNTHESIS`, always on) against a PIR, bypassing
     * `post_incident_learning` (off by default) entirely.
     */
    #[Test]
    public function ai_drafting_is_refused_for_a_post_incident_review(): void
    {
        $incident = $this->declareIncident();
        $pir = app(PirService::class)->ensureDraftFor($incident);

        $this->expectException(InvalidArgumentException::class);
        app(\App\Services\Bcms\Exercises\AarAiDrafter::class)->draft($pir);
    }

    #[Test]
    public function the_model_guard_refuses_a_row_with_both_or_neither_edge_set(): void
    {
        $this->expectException(\LogicException::class);

        Aar::query()->create([
            'organization_id' => $this->organization->id,
            'status' => 'draft', 'quantitative_results' => [], 'participant_feedback' => [],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Declaration, the severity matrix, plan activation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function declaring_an_incident_activates_a_plan_and_logs_the_first_decision(): void
    {
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Kano BCP',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);

        $incident = app(IncidentService::class)->declare([
            'title' => 'Core banking outage', 'incident_type' => 'system',
            'severity' => IncidentSeverity::Sev1->value,
            'detected_at' => now()->subMinutes(10)->toIso8601String(),
            'activate_plan_id' => $plan->getKey(),
            'activation_reason' => 'Sev1 declared, activating the BCP.',
        ], $this->officer);

        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame(1, $incident->entries()->count());
        $this->assertSame(1, $incident->planActivations()->count());
        $this->assertFalse((bool) $incident->is_exercise);
    }

    #[Test]
    public function detected_at_after_now_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(IncidentService::class)->declare([
            'title' => 'Time travel', 'severity' => IncidentSeverity::Sev3->value,
            'detected_at' => now()->addHour()->toIso8601String(),
        ], $this->officer);
    }

    /**
     * Gate 1 re-gate defect 1: `Carbon::parse()` keeps a request's offset,
     * and Eloquent's `datetime` cast then writes that instance's LOCAL
     * wall-clock figure into a column with no offset of its own —
     * `10:00+05:00` (the true instant `05:00` UTC) was stored as literal
     * `10:00`, read back 5 hours later than it really happened. Comparing
     * the ROUND-TRIPPED value against the original true instant is what
     * catches this — an in-memory-only comparison would pass even with the
     * bug present, since Carbon's own comparison operators are already
     * instant-aware.
     */
    #[Test]
    public function declaring_with_a_non_utc_offset_stores_the_correct_instant(): void
    {
        $trueInstant = Carbon::parse('2026-01-15T10:00:00+05:00'); // = 05:00 UTC

        $incident = app(IncidentService::class)->declare([
            'title' => 'Offset test', 'severity' => IncidentSeverity::Sev3->value,
            'detected_at' => '2026-01-15T10:00:00+05:00',
        ], $this->officer);

        $this->assertTrue($trueInstant->equalTo($incident->fresh()->detected_at));
    }

    #[Test]
    public function classifying_with_a_non_utc_offset_awareness_at_stores_the_correct_instant(): void
    {
        $incident = $this->declareIncident();
        $trueInstant = Carbon::parse('2026-01-15T10:00:00+05:00');

        $row = app(NotificationService::class)->classify($incident, $this->officer, 'cbn', $trueInstant->copy());

        $this->assertTrue($trueInstant->equalTo($row->fresh()->awareness_at));
    }

    #[Test]
    public function recording_a_submission_with_a_non_utc_offset_awareness_at_stores_the_correct_instant(): void
    {
        $incident = $this->declareIncident();
        $trueInstant = Carbon::parse('2026-01-15T10:00:00+05:00');

        $row = app(NotificationService::class)->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'awareness_at' => '2026-01-15T10:00:00+05:00',
        ]);

        $this->assertTrue($trueInstant->equalTo($row->fresh()->awareness_at));
    }

    #[Test]
    public function a_decision_entry_requires_options_considered_and_rationale(): void
    {
        $incident = $this->declareIncident();

        $this->expectException(InvalidArgumentException::class);

        app(IncidentService::class)->log($incident, $this->officer, [
            'entry_type' => IncidentLogEntryType::Decision->value,
            'content' => 'We decided something.',
        ]);
    }

    #[Test]
    public function severity_may_only_move_with_a_reason(): void
    {
        $incident = $this->declareIncident();

        $this->expectException(InvalidArgumentException::class);
        app(IncidentService::class)->regrade($incident, $this->officer, IncidentSeverity::Sev1->value, '');
    }

    #[Test]
    public function a_regrade_with_a_reason_updates_severity_and_activation_and_logs_it(): void
    {
        $incident = $this->declareIncident(IncidentSeverity::Sev3);

        $updated = app(IncidentService::class)->regrade($incident, $this->officer, IncidentSeverity::Sev1->value, 'Now confirmed customer-impacting.');

        $this->assertSame(IncidentSeverity::Sev1, $updated->severity);
        $this->assertSame('full', $updated->activation_level->value);
        $this->assertSame(2, $incident->entries()->count()); // declaration + regrade
    }

    /* ------------------------------------------------------------------ */
    /*  Reportability — two clocks, "unknown" as a first-class state */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function both_reportability_questions_default_to_unknown(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $this->assertSame('unknown', $notifications->reportabilityStatus($incident, 'cbn'));
        $this->assertSame('unknown', $notifications->reportabilityStatus($incident, 'personal_data'));
    }

    #[Test]
    public function classifying_personal_data_yes_creates_the_ndpa_row_and_the_clock_survives_backdating(): void
    {
        // The clause map's own criterion-5 test: detected yesterday,
        // classified as a breach TODAY. The deadline must be yesterday + 72h,
        // not a fresh 72 hours from now.
        $incident = $this->declareIncident(detectedAt: now()->subDay());
        $notifications = app(NotificationService::class);

        $row = $notifications->classify($incident, $this->officer, 'ndpc', $incident->detected_at);

        $this->assertSame('yes', $notifications->reportabilityStatus($incident, 'personal_data'));
        $this->assertNotNull($row->due_at);
        $this->assertTrue($row->due_at->between(
            $incident->detected_at->copy()->addHours(72)->subMinute(),
            $incident->detected_at->copy()->addHours(72)->addMinute(),
        ));
        $this->assertTrue($row->isOpen());
    }

    #[Test]
    public function two_clocks_on_one_incident_never_merge(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->classify($incident, $this->officer, 'ndpc');

        $this->assertSame(2, $incident->notifications()->count());
        $this->assertSame(['cbn', 'ndpc'], $incident->notifications()->get()->map(fn ($n) => $n->regulator->value)->sort()->values()->all());
    }

    #[Test]
    public function reassessing_as_not_reportable_is_a_decision_log_entry_not_a_row(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->reassessNotReportable($incident, $this->officer, 'cbn', 'No cyber element; a hardware failure only.');

        $this->assertSame('no', $notifications->reportabilityStatus($incident, 'cbn'));
        $this->assertSame(0, $incident->notifications()->count());
    }

    /**
     * Advisory A10 (Gate 1 re-gate): a SUBMITTED notification cannot be
     * walked back by a decision-log entry alone — `liveObligation()`
     * excludes a submitted row (it is not "live"), so without this refusal
     * the code silently fell through to writing a decision-log entry that
     * contradicted the regulator's own record.
     */
    #[Test]
    public function reassessing_as_not_reportable_after_the_initial_was_submitted_is_refused(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);
        $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-001',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $notifications->reassessNotReportable($incident, $this->officer, 'cbn', 'Changed my mind.');
    }

    #[Test]
    public function recording_a_submission_never_writes_a_transmission_only_a_record(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->classify($incident, $this->officer, 'cbn');
        $row = $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-001',
        ]);

        $this->assertNotNull($row->submitted_at);
        $this->assertSame('CBN-ACK-001', $row->reference);
        $this->assertFalse($row->isOpen());
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 1 re-gate defect 2 — a follow-up never classifies implicitly */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_supplementary_submission_with_no_initial_classified_yet_is_refused(): void
    {
        $incident = $this->declareIncident();

        $this->expectException(InvalidArgumentException::class);
        app(NotificationService::class)->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'supplementary',
        ]);

        $this->assertSame(0, IncidentNotification::query()->where('incident_id', $incident->getKey())->count());
    }

    #[Test]
    public function a_final_submission_is_refused_while_the_initial_is_still_unsubmitted(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->classify($incident, $this->officer, 'cbn');

        $this->expectException(InvalidArgumentException::class);
        app(NotificationService::class)->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'final',
        ]);
    }

    #[Test]
    public function a_follow_up_on_a_withdrawn_obligation_does_not_silently_reopen_it(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);
        $initial = $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->withdraw($initial, $this->officer, 'Not reportable after all.');

        try {
            $notifications->recordSubmission($incident, $this->officer, [
                'regulator' => 'cbn', 'kind' => 'supplementary',
            ]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // expected
        }

        // The defect this closes: before the fix, this silently opened a
        // brand-new `initial` row at sequence+1 with no decision-log entry.
        $this->assertSame(1, IncidentNotification::query()->where('incident_id', $incident->getKey())->count());
    }

    #[Test]
    public function a_supplementary_submission_after_the_initial_is_submitted_succeeds(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);
        $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-001',
        ]);

        $row = $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'supplementary', 'reference' => 'CBN-ACK-002',
        ]);

        $this->assertSame(1, $row->sequence);
        $this->assertSame('CBN-ACK-002', $row->reference);
    }

    #[Test]
    public function the_watchdog_query_finds_an_overdue_notification(): void
    {
        $incident = $this->declareIncident();

        IncidentNotification::query()->create([
            'organization_id' => $this->organization->id, 'incident_id' => $incident->getKey(),
            'regulator' => 'cbn', 'basis_clause_ref' => 'cbn.rcf.incident_response', 'kind' => 'initial',
            'sequence' => 1, 'awareness_at' => now()->subHours(30), 'due_at' => now()->subHours(6),
        ]);

        $overdue = app(NotificationService::class)->overdueQuery()->count();
        $this->assertSame(1, $overdue);
    }

    /** Advisory A8 (Gate 1 re-gate): a drill's obligation never pages anyone. */
    #[Test]
    public function the_watchdog_query_excludes_an_exercise_incidents_overdue_notification(): void
    {
        $incident = $this->declareIncident();
        $incident->forceFill(['is_exercise' => true])->save();

        IncidentNotification::query()->create([
            'organization_id' => $this->organization->id, 'incident_id' => $incident->getKey(),
            'regulator' => 'cbn', 'basis_clause_ref' => 'cbn.rcf.incident_response', 'kind' => 'initial',
            'sequence' => 1, 'awareness_at' => now()->subHours(30), 'due_at' => now()->subHours(6),
        ]);

        $this->assertSame(0, app(NotificationService::class)->overdueQuery()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  ADR 0020 Amendment 2 — a second "initial" submission does not
     *  overwrite the first (Gate 2 review #1 defect 4) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_second_initial_submission_is_refused_once_the_first_was_submitted(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-001',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-002-OVERWRITE',
        ]);
    }

    #[Test]
    public function a_second_initial_submission_does_not_overwrite_the_first_rows_evidence(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->classify($incident, $this->officer, 'cbn');
        $row = $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-001',
        ]);
        $firstSubmittedAt = $row->submitted_at;

        try {
            $notifications->recordSubmission($incident, $this->officer, [
                'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-002-OVERWRITE',
            ]);
        } catch (InvalidArgumentException) {
            // expected — asserted in the sibling test.
        }

        $row->refresh();
        $this->assertSame('CBN-ACK-001', $row->reference);
        $this->assertTrue($firstSubmittedAt->equalTo($row->submitted_at));
    }

    #[Test]
    public function the_model_guard_refuses_editing_a_submitted_rows_submission_fields_directly(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->classify($incident, $this->officer, 'cbn');
        $row = $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-001',
        ]);

        $this->expectException(\LogicException::class);
        $row->update(['reference' => 'REWRITTEN']);
    }

    /* ------------------------------------------------------------------ */
    /*  ADR 0020 Amendment 2 — both clocks default to detected_at, falling
     *  back to declared_at, never now() (Gate 2 review #1 defect 5) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function classifying_with_no_awareness_at_defaults_to_the_incidents_detected_at_not_now(): void
    {
        $incident = $this->declareIncident(detectedAt: now()->subHours(5));
        $notifications = app(NotificationService::class);

        $row = $notifications->classify($incident, $this->officer, 'cbn');

        $this->assertTrue($row->awareness_at->equalTo($incident->detected_at));
        $this->assertTrue($row->awareness_at->diffInMinutes(now()) >= 4);
    }

    #[Test]
    public function classifying_with_no_detected_or_declared_at_is_refused(): void
    {
        $incident = Incident::query()->create([
            'organization_id' => $this->organization->id,
            'reference' => 'INC-NO-CLOCK', 'title' => 'No recorded clock', 'status' => 'open',
            'is_exercise' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(NotificationService::class)->classify($incident, $this->officer, 'cbn');
    }

    #[Test]
    public function setting_awareness_later_than_the_default_requires_a_reason(): void
    {
        $incident = $this->declareIncident(detectedAt: now()->subHours(5));
        $notifications = app(NotificationService::class);

        $this->expectException(InvalidArgumentException::class);
        $notifications->classify($incident, $this->officer, 'cbn', now()->subHours(1));
    }

    #[Test]
    public function setting_awareness_later_than_the_default_with_a_reason_succeeds_and_is_logged(): void
    {
        $incident = $this->declareIncident(detectedAt: now()->subHours(5));
        $notifications = app(NotificationService::class);

        $before = $incident->entries()->count();

        $row = $notifications->classify(
            $incident, $this->officer, 'cbn', now()->subHours(1),
            'The personal-data element was not known until the later time.',
        );

        // `awareness_at` is a MariaDB TIMESTAMP with no fractional-seconds
        // precision, so it loses the microseconds a fresh now()->subHours(1)
        // still carries — compare at second precision.
        $this->assertLessThanOrEqual(3, $row->awareness_at->diffInSeconds(now()->subHours(1)));
        $this->assertSame($before + 1, $incident->entries()->count());
    }

    #[Test]
    public function setting_awareness_earlier_than_the_default_needs_no_reason(): void
    {
        $incident = $this->declareIncident(detectedAt: now()->subHours(5));
        $notifications = app(NotificationService::class);

        $row = $notifications->classify($incident, $this->officer, 'cbn', now()->subHours(6));

        $this->assertLessThanOrEqual(3, $row->awareness_at->diffInSeconds(now()->subHours(6)));
    }

    /* ------------------------------------------------------------------ */
    /*  ADR 0020 Amendment 2 — withdrawal (Gate 2 review #1 defect 6a) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function reassessing_an_open_obligation_as_not_reportable_withdraws_it_and_the_gate_can_pass(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $row = $notifications->classify($incident, $this->officer, 'cbn');
        $this->assertSame(1, $notifications->overdueOrOpenCount($incident));

        $notifications->reassessNotReportable($incident, $this->officer, 'cbn', 'No cyber element; a hardware failure only.');

        $row->refresh();
        $this->assertNotNull($row->withdrawn_at);
        $this->assertSame($this->officer->getKey(), $row->withdrawn_by);
        $this->assertNotNull($row->withdrawal_entry_id);
        $this->assertSame('no', $notifications->reportabilityStatus($incident, 'cbn'));

        // The defect this closes: before Amendment 2, this stayed 1 for
        // ever and the stand-down gate could never pass.
        $this->assertSame(0, $notifications->overdueOrOpenCount($incident));
        $this->assertSame(0, $notifications->overdueQuery()->where('incident_id', $incident->getKey())->count());
    }

    #[Test]
    public function withdrawing_a_submitted_notification_is_refused(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->classify($incident, $this->officer, 'cbn');
        $row = $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'CBN-ACK-001',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $notifications->withdraw($row, $this->officer, 'Too late, changed my mind.');
    }

    #[Test]
    public function withdrawing_twice_is_refused(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $row = $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->withdraw($row, $this->officer, 'Not reportable after all.');

        $this->expectException(InvalidArgumentException::class);
        $notifications->withdraw($row->fresh(), $this->officer, 'Withdrawing again.');
    }

    #[Test]
    public function reclassifying_after_a_withdrawal_opens_a_new_row_defaulting_to_the_withdrawn_rows_awareness(): void
    {
        $incident = $this->declareIncident(detectedAt: now()->subDay());
        $notifications = app(NotificationService::class);

        $first = $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable after all.');

        $second = $notifications->classify($incident, $this->officer, 'cbn');

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, $second->sequence);
        $this->assertNull($second->withdrawn_at);
        $this->assertLessThanOrEqual(3, $second->awareness_at->diffInSeconds($first->awareness_at));
        $this->assertSame(2, IncidentNotification::query()->where('incident_id', $incident->getKey())->where('regulator', 'cbn')->count());
    }

    /**
     * Code review #3, probe 1: after withdraw + reclassify there are TWO
     * `initial` rows for the same regulator — the withdrawn one and the
     * live one the reclassify opened. The follow-up path's own lookup
     * picked whichever the database happened to return first with no
     * ordering at all, not necessarily the live row, so a follow-up on the
     * obligation the officer had correctly reclassified and submitted could
     * be refused telling them to do what they had already done.
     */
    #[Test]
    public function a_follow_up_after_withdraw_reclassify_and_submit_is_accepted(): void
    {
        $incident = $this->declareIncident();
        $notifications = app(NotificationService::class);

        $notifications->classify($incident, $this->officer, 'cbn');
        $notifications->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable after all.');
        $notifications->classify($incident, $this->officer, 'cbn'); // reclassify: new initial, sequence 2
        $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'initial', 'reference' => 'ACK-1',
        ]);

        $final = $notifications->recordSubmission($incident, $this->officer, [
            'regulator' => 'cbn', 'kind' => 'final', 'reference' => 'ACK-2',
        ]);

        $this->assertSame('final', $final->kind->value);
        $this->assertSame(2, IncidentNotification::query()
            ->where('incident_id', $incident->getKey())->where('regulator', 'cbn')
            ->where('kind', 'initial')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Stand-down gate */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function stand_down_is_refused_with_open_tasks_or_unresolved_reportability(): void
    {
        $incident = $this->declareIncident();
        app(IncidentService::class)->addTask($incident, ['title' => 'Check the backup site']);

        $this->expectException(InvalidArgumentException::class);
        app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
        ]);
    }

    #[Test]
    public function stand_down_succeeds_once_every_condition_is_met_and_closes_the_incident(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $closed = app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'The situation is resolved; normal operations have resumed.',
            'reason' => 'Root cause fixed and verified.',
        ]);

        $this->assertSame(IncidentStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 1 re-gate defect 3 — a closed/cancelled incident refuses a
     *  second stand-down and every other mutating action */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_second_stand_down_is_refused_and_closed_at_is_unchanged(): void
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $closed = app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
        ]);
        $firstClosedAt = $closed->closed_at;
        $entriesAfterFirst = $closed->entries()->count();

        $this->expectException(InvalidArgumentException::class);

        try {
            app(IncidentService::class)->standDown($closed->fresh(), $this->officer, [
                'all_clear_message' => 'All clear, again.', 'reason' => 'Resolved, again.',
            ]);
        } finally {
            $closed->refresh();
            $this->assertTrue($firstClosedAt->equalTo($closed->closed_at));
            $this->assertSame($entriesAfterFirst, $closed->entries()->count());
        }
    }

    /**
     * `IncidentService::log()` itself stays ungated — `NotificationService`'s
     * three late-correction callers, and this file's own PIR fixtures below,
     * write a `decision`/`escalation` entry on an already-closed incident
     * through that same method deliberately (see `assertNotTerminal()`'s
     * docblock). A NEW MANUAL entry is refused one layer up, at
     * `IncidentController::storeLog()` — the only real path a person's
     * submission takes — which calls `assertNotTerminal()` before `log()`
     * for every entry type, so this is exercised over HTTP.
     */
    #[Test]
    public function a_closed_incident_refuses_a_new_manual_log_entry(): void
    {
        $incident = $this->closedIncident();

        $this->actingAs($this->officer)->post(route('bcms.incidents.log.store', $incident), [
            'entry_type' => IncidentLogEntryType::SituationReport->value,
            'content' => 'A late situation report.',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(
            0,
            $incident->entries()->where('entry_type', IncidentLogEntryType::SituationReport->value)->count()
        );
    }

    #[Test]
    public function a_closed_incident_refuses_a_new_task(): void
    {
        $incident = $this->closedIncident();

        $this->expectException(InvalidArgumentException::class);
        app(IncidentService::class)->addTask($incident, ['title' => 'Too late.']);
    }

    /**
     * A6 (code review #3 advisory). The stand-down gate itself already
     * requires every task complete/cancelled before an incident can close,
     * so an OPEN task on an already-closed incident cannot arise through
     * the guarded `addTask()`/stand-down path — created directly here to
     * prove `completeTask()`'s own guard, independent of that gate.
     */
    #[Test]
    public function a_closed_incident_refuses_completing_a_task(): void
    {
        $incident = $this->closedIncident();
        $task = \App\Models\Bcms\IncidentTask::query()->create([
            'organization_id' => $incident->organization_id, 'incident_id' => $incident->getKey(),
            'title' => 'Left open', 'status' => 'open',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(IncidentService::class)->completeTask($task);
    }

    /** A6 (code review #3 advisory): a cancelled task is a final state — it cannot be flipped to complete. */
    #[Test]
    public function completing_a_cancelled_task_is_refused(): void
    {
        $incident = $this->declareIncident();
        $task = app(IncidentService::class)->addTask($incident, ['title' => 'Check the backup site']);
        $task->update(['status' => 'cancelled']);

        $this->expectException(InvalidArgumentException::class);
        app(IncidentService::class)->completeTask($task->fresh());
    }

    /** A6 (code review #3 advisory): a task due date with a non-UTC offset stores the same true instant defect 1 already fixed for `detected_at`/`awareness_at`. */
    #[Test]
    public function a_task_due_at_with_a_non_utc_offset_stores_the_correct_instant(): void
    {
        $incident = $this->declareIncident();
        $trueInstant = Carbon::parse('2026-02-01T09:00:00+05:00')->utc();

        $task = app(IncidentService::class)->addTask($incident, [
            'title' => 'Check the backup site', 'due_at' => '2026-02-01T09:00:00+05:00',
        ]);

        $this->assertTrue($trueInstant->equalTo($task->fresh()->due_at));
    }

    #[Test]
    public function a_closed_incident_refuses_a_regrade(): void
    {
        $incident = $this->closedIncident();

        $this->expectException(InvalidArgumentException::class);
        app(IncidentService::class)->regrade($incident, $this->officer, IncidentSeverity::Sev1->value, 'Reassessed after the fact.');
    }

    private function closedIncident(): Incident
    {
        $incident = $this->declareIncident();
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        return app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  ADR 0020 Amendment 4 — a plan kept active past stand-down
     *  (Gate 2 review #1 defect 6, second half) */
    /* ------------------------------------------------------------------ */

    /** The defect this closes: before Amendment 4, this could never pass. */
    #[Test]
    public function stand_down_is_refused_when_an_activated_plan_has_no_disposition(): void
    {
        $incident = $this->declareIncident();
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Kept-active plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        \App\Models\Bcms\PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Declared.',
        ]);
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $this->expectException(InvalidArgumentException::class);
        app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
        ]);
    }

    #[Test]
    public function stand_down_succeeds_with_a_plan_explicitly_kept_active(): void
    {
        $incident = $this->declareIncident();
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Kept-active plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $activation = \App\Models\Bcms\PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Declared.',
        ]);
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $closed = app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
            'plans_remaining_active' => [
                $activation->getKey() => 'The relocated team has not yet returned; the plan stays active.',
            ],
        ]);

        $this->assertSame(IncidentStatus::Closed, $closed->status);

        $activation->refresh();
        $this->assertNull($activation->deactivated_at);
        $this->assertNotNull($activation->kept_active_entry_id);
        $this->assertSame('Declared.', $activation->activation_reason, 'activation_reason must never be rewritten at stand-down.');

        $entry = $activation->keptActiveEntry;
        $this->assertNotNull($entry);
        $this->assertSame(IncidentLogEntryType::Decision, $entry->entry_type);
        $this->assertStringContainsString('Kept-active plan', $entry->content);
        $this->assertStringContainsString('relocated team', $entry->content);
    }

    #[Test]
    public function keeping_a_plan_active_with_a_blank_statement_is_refused_and_names_the_plan(): void
    {
        $incident = $this->declareIncident();
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Named plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $activation = \App\Models\Bcms\PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Declared.',
        ]);
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        try {
            app(IncidentService::class)->standDown($incident, $this->officer, [
                'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
                'plans_remaining_active' => [$activation->getKey() => '   '],
            ]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Named plan', $e->getMessage());
        }

        // The refusal rolled back — no mark left on the activation, the
        // incident, or the decision log (Amendment 4 rule 4).
        $activation->refresh();
        $this->assertNull($activation->kept_active_entry_id);
        $incident->refresh();
        $this->assertNotSame(IncidentStatus::Closed, $incident->status);
    }

    #[Test]
    public function a_failed_stand_down_rolls_back_a_kept_active_disposition_alongside_the_closure(): void
    {
        $incident = $this->declareIncident();
        $plan = Plan::query()->create([
            'organization_id' => $this->organization->id, 'plan_type' => 'bcp', 'title' => 'Rollback plan',
            'status' => 'approved', 'version' => '1', 'content' => [],
        ]);
        $activation = \App\Models\Bcms\PlanActivation::query()->create([
            'organization_id' => $this->organization->id, 'plan_id' => $plan->getKey(),
            'incident_id' => $incident->getKey(), 'is_exercise' => false,
            'activated_by' => $this->officer->getKey(), 'activated_at' => now(),
            'activation_reason' => 'Declared.',
        ]);
        // Deliberately leave reportability unresolved so the gate still
        // refuses AFTER the kept-active disposition is written.
        $entriesBefore = $incident->entries()->count();

        try {
            app(IncidentService::class)->standDown($incident, $this->officer, [
                'all_clear_message' => 'All clear.', 'reason' => 'Resolved.',
                'plans_remaining_active' => [$activation->getKey() => 'Stays active.'],
            ]);
            $this->fail('Expected an InvalidArgumentException — reportability is unresolved.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $activation->refresh();
        $this->assertNull($activation->kept_active_entry_id);
        $this->assertSame($entriesBefore, $incident->entries()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  is_exercise never pollutes a live aggregate (clause map §6.11) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_declared_incident_is_never_marked_as_an_exercise(): void
    {
        $incident = $this->declareIncident();

        $this->assertFalse((bool) $incident->is_exercise);
        $this->assertSame(0, Incident::query()->where('is_exercise', true)->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Code review #3 A1: `ErmBridge::mirrorIncident()` — a direct
     *  `LossEvent` write superseded by `mirrorRealisedLoss()` below and
     *  called from nowhere else — is deleted. Its two tests are redundant
     *  with `finalising_with_a_zero_confirmed_loss_creates_no_loss_event`
     *  and `finalising_with_a_confirmed_realised_loss_mirrors_it_through_
     *  loss_event_service` immediately below, which exercise the same "no
     *  loss, nothing mirrored" / "a loss, mirrored and idempotent" pair
     *  through the real, currently-used path instead. */
    /* ------------------------------------------------------------------ */

    /* ------------------------------------------------------------------ */
    /*  Gate 1 re-gate defect 7 — the realised-loss confirmation, mirrored
     *  THROUGH LossEventService, never the declaration estimate
     *  (clause map §4.3, pir-post-incident-review.md §7) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function finalising_with_a_confirmed_realised_loss_mirrors_it_through_loss_event_service(): void
    {
        Event::fake([LossEventCreated::class]);

        $incident = $this->declareIncident();
        // The DECLARATION estimate — deliberately different from the
        // confirmed figure below, to prove the estimate is never what gets
        // mirrored.
        $incident->update(['estimated_impact_minor' => 750_000_00, 'currency' => 'NGN']);
        $aar = $this->readyToFinalisePir($incident);

        // A separate approver — `$this->officer` both declared this
        // incident and logged its opening decision, which the defect 5
        // separation-of-duties rule now refuses on its own (covered below).
        $approver = $this->independentApprover();
        $finalised = app(PirService::class)->finalise($aar, $approver, 300_000_00);

        $this->assertSame('final', $finalised->status);
        $this->assertSame(300_000_00, $finalised->quantitative_results['realised_loss_minor']);
        $this->assertSame($approver->getKey(), $finalised->quantitative_results['realised_loss_confirmed_by']);
        $this->assertNotNull($finalised->quantitative_results['realised_loss_confirmed_at']);

        $this->assertSame(1, LossEvent::query()->count());
        $lossEvent = LossEvent::query()->firstOrFail();
        // The CONFIRMED figure, not the 750,000.00 declaration estimate.
        $this->assertSame(300_000_00, $lossEvent->gross_loss_amount_kobo);
        // Canonical, upper-case status — the defect this closes: the old
        // direct write stored lower-case 'reported', invisible to
        // `LossEventDashboardService::OPEN_STATUSES`.
        $this->assertSame('REPORTED', $lossEvent->current_status);
        $this->assertSame($incident->fresh()->erm_loss_event_id, $lossEvent->getKey());

        Event::assertDispatched(LossEventCreated::class);

        // `LossEventService::report()`'s own `AuditTrailService::record()`
        // row — the defect this closes: the old direct write bypassed it
        // entirely.
        $this->assertTrue(
            RiskAuditTrail::query()->where('entity_id', $lossEvent->getKey())->exists()
        );
    }

    #[Test]
    public function finalising_with_a_zero_confirmed_loss_creates_no_loss_event(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $finalised = app(PirService::class)->finalise($aar, $this->independentApprover(), 0);

        $this->assertSame(0, $finalised->quantitative_results['realised_loss_minor']);
        $this->assertSame(0, LossEvent::query()->count());
        $this->assertNull($incident->fresh()->erm_loss_event_id);
    }

    #[Test]
    public function finalising_an_exercise_incidents_review_never_mirrors_even_with_a_confirmed_loss(): void
    {
        $incident = $this->declareIncident();
        // No legitimate path through `IncidentService::declare()` produces
        // an exercise incident (it hardcodes `is_exercise = false`) — this
        // mirrors how `Phase10DrTest`'s own "is_exercise never pollutes"
        // fixtures reach the state directly.
        $incident->forceFill(['is_exercise' => true])->save();
        $aar = $this->readyToFinalisePir($incident->fresh());

        app(PirService::class)->finalise($aar, $this->independentApprover(), 300_000_00);

        $this->assertSame(0, LossEvent::query()->count());
        $this->assertNull($incident->fresh()->erm_loss_event_id);
    }

    #[Test]
    public function reopening_and_refinalising_with_a_new_confirmed_figure_amends_not_duplicates(): void
    {
        Event::fake([LossEventCreated::class, LossEventAmountChanged::class]);

        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);
        $approver = $this->independentApprover();

        $first = app(PirService::class)->finalise($aar, $approver, 500_000_00);
        $firstLossEventId = $incident->fresh()->erm_loss_event_id;
        $this->assertNotNull($firstLossEventId);
        $this->assertSame(1, LossEvent::query()->count());

        $reopened = app(AarService::class)->reopen($first, $approver, 'Correcting the confirmed loss figure.');
        app(PirService::class)->finalise($reopened->fresh(), $approver, 650_000_00);

        $this->assertSame(1, LossEvent::query()->count());
        $this->assertSame($firstLossEventId, $incident->fresh()->erm_loss_event_id);
        $this->assertSame(650_000_00, LossEvent::query()->find($firstLossEventId)->gross_loss_amount_kobo);
        Event::assertDispatched(LossEventAmountChanged::class);
    }

    /**
     * Code review #3, probe 2 (extended per the coordinator's instruction to
     * also cover root cause and recoveries): `LossEventService::amend()`
     * runs its FULL `$validated` array through `canonicalAttributes()`
     * unconditionally, resetting Basel L1/L2, CBN category, severity,
     * title, description, root cause to null and insurance/other
     * recoveries to zero on every call. An ERM analyst who has since
     * triaged the mirrored loss — classified it, recorded a recovery,
     * written a root cause — must not have that overwritten by BCMS simply
     * because the PIR was reopened and refinalised with a corrected
     * figure.
     */
    #[Test]
    public function refinalising_a_pir_leaves_erm_owned_loss_fields_alone(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);
        $approver = $this->independentApprover();

        $first = app(PirService::class)->finalise($aar, $approver, 500_000_00);
        $loss = LossEvent::query()->findOrFail($incident->fresh()->erm_loss_event_id);

        // ERM's own analyst triages the mirrored loss in the loss register.
        $loss->forceFill([
            'basel_l1_category' => 'EXTERNAL_FRAUD',
            'basel_l2_category' => 'EXTERNAL_FRAUD',
            'cbn_risk_category' => 'FRAUD',
            'insurance_recovery_kobo' => 120_000_00,
            'other_recovery_kobo' => 50_000_00,
            'initial_root_cause' => 'Vendor patch failure.',
        ])->save();

        $reopened = app(AarService::class)->reopen($first, $approver, 'Correct the figure.');
        app(PirService::class)->finalise($reopened->fresh(), $approver, 650_000_00);

        $loss->refresh();
        $this->assertSame(650_000_00, (int) $loss->gross_loss_amount_kobo);
        $this->assertSame('EXTERNAL_FRAUD', $loss->basel_l1_category, 'BCMS overwrote ERM Basel L1 classification');
        $this->assertSame('EXTERNAL_FRAUD', $loss->basel_l2_category, 'BCMS overwrote ERM Basel L2 classification');
        $this->assertSame('FRAUD', $loss->cbn_risk_category, 'BCMS overwrote ERM CBN category');
        $this->assertSame(120_000_00, (int) $loss->insurance_recovery_kobo, 'BCMS wiped ERM insurance recovery');
        $this->assertSame(50_000_00, (int) $loss->other_recovery_kobo, 'BCMS wiped ERM other recovery');
        $this->assertSame('Vendor patch failure.', $loss->initial_root_cause, 'BCMS wiped ERM root cause');
    }

    /**
     * A2 (code review #3 advisory): re-finalising with a confirmed ZERO
     * after an earlier positive figure must still correct the mirrored
     * event down to zero through `amend()` — not leave the stale positive
     * amount sitting in the loss register once the officer has said there
     * was, in fact, no realised loss.
     */
    #[Test]
    public function refinalising_with_a_zero_confirmed_loss_amends_the_mirrored_event_to_zero(): void
    {
        Event::fake([LossEventCreated::class, LossEventAmountChanged::class]);

        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);
        $approver = $this->independentApprover();

        $first = app(PirService::class)->finalise($aar, $approver, 500_000_00);
        $lossEventId = $incident->fresh()->erm_loss_event_id;
        $this->assertNotNull($lossEventId);

        $reopened = app(AarService::class)->reopen($first, $approver, 'No realised loss after all.');
        app(PirService::class)->finalise($reopened->fresh(), $approver, 0);

        $this->assertSame(1, LossEvent::query()->count());
        $this->assertSame($lossEventId, $incident->fresh()->erm_loss_event_id);
        $this->assertSame(0, (int) LossEvent::query()->find($lossEventId)->gross_loss_amount_kobo);
        Event::assertDispatched(LossEventAmountChanged::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 1 re-gate defect 5 — bcms.aar.approve required alongside
     *  bcms.incident.manage; separation of duties against declared_by and
     *  decision-loggers (pir-post-incident-review.md §1) */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_incidents_own_declaring_officer_cannot_finalise_its_review_alone(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        // `$this->officer` both declared this incident (via `declareIncident()`)
        // and holds `bcms.aar.approve`/`bcms.incident.manage` — the exact
        // combination the separation-of-duties rule exists to catch.
        $this->givePermission($this->officer, 'bcms.aar.approve');

        $this->expectException(InvalidArgumentException::class);
        app(PirService::class)->finalise($aar, $this->officer, 0);
    }

    #[Test]
    public function a_user_who_logged_a_decision_during_the_response_cannot_finalise_its_review_alone(): void
    {
        $incident = $this->declareIncident();

        $decisionLogger = $this->user('decision-logger@khb.test');
        $this->givePermission($decisionLogger, 'bcms.incident.manage');
        $this->givePermission($decisionLogger, 'bcms.aar.approve');
        app(IncidentService::class)->log($incident, $decisionLogger, [
            'entry_type' => IncidentLogEntryType::Decision->value,
            'content' => 'Escalated to the regulator liaison.',
            'options_considered' => 'Escalate now or wait for more information.',
            'rationale' => 'Time pressure made escalating now the safer choice.',
        ]);

        $aar = $this->readyToFinalisePir($incident);

        $this->expectException(InvalidArgumentException::class);
        app(PirService::class)->finalise($aar, $decisionLogger, 0);
    }

    #[Test]
    public function a_third_party_with_both_permissions_can_finalise_the_review(): void
    {
        $incident = $this->declareIncident();
        $aar = $this->readyToFinalisePir($incident);

        $approver = $this->user('third-party-approver@khb.test');
        $this->givePermission($approver, 'bcms.incident.manage');
        $this->givePermission($approver, 'bcms.aar.approve');

        $finalised = app(PirService::class)->finalise($aar, $approver, 0);

        $this->assertSame('final', $finalised->status);
    }

    /**
     * Gets an incident closed and its PIR draft into a state every one of
     * `PirService::conditions()`'s eight conditions already passes, the same
     * fixture shape `Phase10ScreensTest::the_review_finalises_through_its_own_incident_scoped_route_stamping_iso22320`
     * uses.
     */
    private function readyToFinalisePir(Incident $incident): Aar
    {
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'cbn', 'Not reportable.');
        app(NotificationService::class)->reassessNotReportable($incident, $this->officer, 'personal_data', 'No personal data.');

        $incident = app(IncidentService::class)->standDown($incident, $this->officer, [
            'all_clear_message' => 'The situation is resolved; normal operations have resumed.',
            'reason' => 'Root cause fixed and verified.',
        ]);

        $aar = app(PirService::class)->ensureDraftFor($incident);
        $aar->update([
            'summary' => 'Summary text.', 'what_worked' => 'Comms worked well.', 'what_failed' => 'Detection was slow.',
            'quantitative_results' => [
                'metrics' => ['time_to_detect_minutes' => 5, 'time_to_declare_minutes' => 2, 'time_to_activate_minutes' => 3],
            ],
        ]);

        return $aar->fresh();
    }

    /* ------------------------------------------------------------------ */
    /*  ADR 0020 §1 / pir-post-incident-review.md §1 permission note:
     *  a PIR is incident-shaped evidence and the AAR permissions alone must
     *  not be sufficient to read or write one. `bcms.aars.show`/`.update`
     *  (and the approve-gated actions) must ALSO require `bcms.incident.view`/
     *  `.manage` when `Aar::isPostIncident()` is true. This is not
     *  hypothetical: the `risk-owner` catalog role
     *  (`App\Authorization\RiskPermissionCatalog`) grants exactly
     *  `bcms.aar.manage` + `bcms.incident.view` (never `.incident.manage`),
     *  which is the precise combination reproduced below. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_aar_manage_holder_without_incident_manage_cannot_read_a_pir_via_the_shared_aar_route(): void
    {
        $incident = $this->declareIncident();
        $pir = app(PirService::class)->ensureDraftFor($incident);

        // Mirrors the `risk-owner` catalog role's actual grant combination
        // exactly: it holds `bcms.exercise.view` + `bcms.aar.manage` (so it
        // satisfies `bcms.aars.show`'s own middleware) and `bcms.incident.view`
        // only, never `.incident.manage`.
        $leakUser = $this->user('bu-head@khb.test');
        $this->givePermission($leakUser, 'bcms.exercise.view');
        $this->givePermission($leakUser, 'bcms.aar.manage');
        $this->givePermission($leakUser, 'bcms.incident.view');

        $this->actingAs($leakUser)->get(route('bcms.aars.show', $pir))->assertForbidden();
    }

    #[Test]
    public function an_aar_manage_holder_without_incident_manage_cannot_write_a_pir_via_the_shared_aar_route(): void
    {
        $incident = $this->declareIncident();
        $pir = app(PirService::class)->ensureDraftFor($incident);

        $leakUser = $this->user('bu-head2@khb.test');
        $this->givePermission($leakUser, 'bcms.aar.manage');
        $this->givePermission($leakUser, 'bcms.incident.view');

        $this->actingAs($leakUser)->patch(route('bcms.aars.update', $pir), [
            'summary' => 'A BU head with no bcms.incident.manage should not be able to write this.',
        ])->assertForbidden();

        $this->assertNotSame(
            'A BU head with no bcms.incident.manage should not be able to write this.',
            $pir->fresh()->summary,
        );
    }

    /**
     * The re-gate note: now that `AarController::show()` additionally
     * requires `bcms.incident.manage` for a PIR (stricter than
     * `pir-post-incident-review.md` §1's own `bcms.incident.view`), a viewer
     * must not lose all access to the review — the DEDICATED
     * `bcms.incidents.review.show` route (`IncidentReviewController::show()`,
     * gated on `bcms.incident.view` alone) is where that read is meant to
     * happen instead. Confirms nothing was lost by tightening the shared
     * legacy route.
     */
    #[Test]
    public function an_incident_view_only_holder_can_open_the_dedicated_review_route(): void
    {
        $incident = $this->declareIncident();
        app(PirService::class)->ensureDraftFor($incident);

        $viewOnly = $this->user('view-only@khb.test');
        $this->givePermission($viewOnly, 'bcms.incident.view');

        $this->actingAs($viewOnly)->get(route('bcms.incidents.review.show', $incident))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Bcms/Incidents/Review'));
    }

    /* ------------------------------------------------------------------ */
    /*  Cross-tenant/unit isolation */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_user_in_another_unit_cannot_open_this_incident_by_uuid(): void
    {
        $incident = $this->declareIncident();
        $incident->update(['business_unit_id' => $this->unit->id]);

        $lagos = BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-LAGOS', 'name' => 'Lagos', 'is_active' => true,
        ]);
        $lagosUser = $this->user('lagos@khb.test', $lagos);
        $this->givePermission($lagosUser, 'bcms.incident.view');

        $this->actingAs($lagosUser)->get(route('bcms.incidents.crisis-room', $incident))->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function declareIncident(IncidentSeverity $severity = IncidentSeverity::Sev2, ?Carbon $detectedAt = null): Incident
    {
        return app(IncidentService::class)->declare([
            'title' => 'A test incident', 'severity' => $severity->value,
            'detected_at' => ($detectedAt ?? now())->toIso8601String(),
        ], $this->officer);
    }

    private function fakeOccurrenceId(): int
    {
        $type = \App\Models\Bcms\ExerciseType::query()->firstOrFail();
        $programme = \App\Models\Bcms\ExerciseProgramme::query()->create(['year' => 2027, 'name' => 'Programme 2027']);
        $definition = \App\Models\Bcms\ExerciseDefinition::query()->create([
            'exercise_programme_id' => $programme->getKey(),
            'exercise_type_id' => $type->getKey(),
            'name' => 'Fire drill',
        ]);

        return \App\Models\Bcms\ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'status' => 'planned',
        ])->getKey();
    }

    private function user(string $email, ?BusinessUnit $unit = null): User
    {
        return User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')),
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => ($unit ?? $this->unit)->id, 'is_active' => true,
        ]);
    }

    private function givePermission(User $user, string $permission): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('bcms10inc-'.md5($permission.$user->email), 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        $user->assignRole($role);
    }

    /**
     * A user who never declared the incident and never logged a decision on
     * it — the one who CAN finalise its PIR under the defect 5 separation-
     * of-duties rule. `$this->officer` cannot: `declareIncident()` both
     * names it `declared_by` and has it log the declaration's own `decision`
     * entry.
     */
    private function independentApprover(): User
    {
        $approver = $this->user('independent-approver-'.Str::random(8).'@khb.test');
        $this->givePermission($approver, 'bcms.incident.manage');
        $this->givePermission($approver, 'bcms.aar.approve');

        return $approver;
    }
}
