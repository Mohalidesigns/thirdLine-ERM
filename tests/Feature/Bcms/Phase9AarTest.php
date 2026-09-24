<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\FindingSeverity;
use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\Aar;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Evidence;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Finding;
use App\Models\Bcms\ReminderSchedule;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Exercises\AarExportService;
use App\Services\Bcms\Exercises\AarService;
use App\Services\Bcms\Exercises\EvidenceService;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Exercises\OccurrenceExecutionService;
use App\Services\Bcms\Exercises\ScoringService;
use App\Services\Bcms\Exercises\TimelineService;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderDispatcher;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use App\Services\FileUploadService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 9 — the after-action report: the eleven-plus-one condition
 * gate, finalisation and its immutability, reopening, the carry, the export
 * and the finding→CAPA due-date rule (docs/bcms/phase-9-aar-clause-map.md,
 * ADR 0019).
 */
class Phase9AarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $facilitator;

    private User $approver;

    private User $evaluator;

    private ExerciseProgramme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);
        Storage::fake('local');

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

        $this->facilitator = $this->user('facilitator@khb.test');
        $this->approver = $this->user('approver@khb.test');
        $this->evaluator = $this->user('evaluator@khb.test');

        foreach (['bcms.exercise.facilitate', 'bcms.exercise.view', 'bcms.aar.manage', 'bcms.finding.manage', 'bcms.report.export', 'rcsa_scope.all_units'] as $p) {
            $this->givePermission($this->facilitator, $p);
        }
        // `rcsa_scope.all_units`: the approver is deliberately a different
        // person from the facilitator (separation of duties) and need not
        // share a business-unit assignment to be able to see and finalise
        // the report — see `Phase1ScreensTest`'s use of the same grant.
        foreach (['bcms.exercise.view', 'bcms.aar.approve', 'bcms.aar.manage', 'rcsa_scope.all_units'] as $p) {
            $this->givePermission($this->approver, $p);
        }
        $this->givePermission($this->evaluator, 'bcms.exercise.evaluate');
        $this->givePermission($this->evaluator, 'bcms.exercise.view');
        $this->givePermission($this->evaluator, 'rcsa_scope.all_units');

        $this->programme = app(ExerciseProgrammeService::class)->create(
            (int) now()->year, 'Exercise programme', [], $this->approver->id,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  The gate: each unmet condition refuses with a named reason. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function finalisation_refuses_with_a_named_reason_per_unmet_condition(): void
    {
        $occurrence = $this->startedOccurrence();
        // `OccurrenceExecutionService::complete()` always logs its own
        // milestone entry ("Exercise ended."), so condition 4 (the timeline
        // has at least one milestone) is met the moment an exercise ends —
        // this test's unmet conditions are the ones nothing auto-satisfies:
        // no scores, no narrative, and the required metrics for the type.
        $aar = $this->completeExercise($occurrence, scoreAll: false, logMilestone: false, fillRequiredMetrics: false);

        $conditions = app(AarService::class)->conditions($aar);
        $byKey = collect($conditions)->keyBy('key');

        $this->assertTrue($byKey['4_timeline']['met'], 'complete() logs its own milestone entry.');
        $this->assertFalse($byKey['5_scored']['met'], 'An occurrence with no scores should fail condition 5.');
        $this->assertFalse($byKey['3_narrative']['met'], 'An occurrence with no summary/what-worked/what-failed should fail condition 3.');
        $this->assertFalse($byKey['11_metrics']['met'], 'A FIREDRILL AAR with no required metrics recorded, and none listed as not measured, should fail condition 11.');

        $this->expectException(InvalidArgumentException::class);
        app(AarService::class)->finalise($aar, $this->approver);
    }

    #[Test]
    public function a_score_of_one_or_two_blocks_finalisation_until_dispositioned(): void
    {
        $occurrence = $this->startedOccurrence();
        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true, lowScore: true);

        $conditions = app(AarService::class)->conditions($aar);
        $condition6 = collect($conditions)->firstWhere('key', '6_low_scores');
        $this->assertFalse($condition6['met']);

        // Dispositioning the low score in what-failed (rather than linking a
        // finding) satisfies condition 6.
        $objectiveText = $aar->refresh()->quantitative_results['objectives'][0]['objective_text'];
        app(AarService::class)->disposeObjective($aar, $objectiveText, 'Accepted — marshal was new, retrained.');

        $conditions = app(AarService::class)->conditions($aar->fresh());
        $condition6 = collect($conditions)->firstWhere('key', '6_low_scores');
        $this->assertTrue($condition6['met']);
    }

    /**
     * Clause map §1.3's separation-of-duties rule: at ladder level `functional`
     * or above, the approver must not be the occurrence's own facilitator.
     * None of the other tests in this file ever put the same person in both
     * roles, so `AarService::approverAllowed()`'s actual blocking branch has
     * never been exercised — this is the first test that puts a facilitator
     * up against their own exercise directly, at the service layer.
     */
    #[Test]
    public function the_facilitator_cannot_approve_their_own_functional_level_exercise(): void
    {
        $type = ExerciseType::query()->where('code', 'DRFAILOVER')->sole();
        $definition = app(ExerciseDefinitionService::class)->create(
            $this->programme, $type, 'DR failover '.Str::random(4),
            [
                'business_unit_id' => $this->unit->id,
                'owner_id' => $this->approver->id,
                'facilitator_id' => $this->facilitator->id,
                'status' => 'active',
            ],
            $this->approver->id,
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $this->facilitator->id,
        ]);
        app(ReadinessService::class)->materialise($occurrence);
        app(OccurrenceExecutionService::class)->start($occurrence->refresh(), $this->facilitator);

        $aar = app(AarService::class)->ensureDraftFor($occurrence->refresh());

        $blocked = app(AarService::class)->approverAllowed($aar, $this->facilitator);
        $this->assertFalse($blocked['allowed'], 'The exercise\'s own facilitator should not be allowed to approve a functional-level AAR.');
        $this->assertTrue($blocked['blocking']);

        $allowed = app(AarService::class)->approverAllowed($aar, $this->approver);
        $this->assertTrue($allowed['allowed'], 'A different approver should be allowed to finalise the same report.');
    }

    /**
     * Acceptance criterion 6, and the prompt's own named test-data scenario
     * (a core-banking DR failover, RTO actual 3h12m against a 2h target):
     * DRFAILOVER carries a cadence-type extra refusal (clause map §2.2's
     * closing note) that no other test in this class exercises — every other
     * test here uses FIREDRILL, so `AarService::REQUIRED_METRICS['DRFAILOVER']`
     * and `CADENCE_TYPES` were otherwise dead as far as this suite could tell.
     */
    #[Test]
    public function a_dr_failover_missing_its_recovery_time_blocks_finalisation_until_recorded(): void
    {
        $type = ExerciseType::query()->where('code', 'DRFAILOVER')->sole();
        $definition = app(ExerciseDefinitionService::class)->create(
            $this->programme, $type, 'Core banking DR failover',
            [
                'business_unit_id' => $this->unit->id,
                'owner_id' => $this->approver->id,
                'facilitator_id' => $this->facilitator->id,
                'status' => 'active',
            ],
            $this->approver->id,
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $this->facilitator->id,
        ]);
        app(ReadinessService::class)->materialise($occurrence);
        app(OccurrenceExecutionService::class)->start($occurrence->refresh(), $this->facilitator);

        app(TimelineService::class)->log($occurrence, 'milestone', 'Failover confirmed.', $this->facilitator);
        $occurrence->participants()->update(['attendance_status' => 'present']);
        app(OccurrenceExecutionService::class)->complete($occurrence, $this->facilitator, 'fail');

        $aar = $occurrence->fresh()->aar;
        $aar->update([
            'summary' => 'Failover missed its target.',
            'what_worked' => 'Traffic cut over to the DR site.',
            'what_failed' => 'Recovery took far longer than the recovery time objective.',
        ]);

        $conditions = app(AarService::class)->conditions($aar->fresh());
        $condition11 = collect($conditions)->firstWhere('key', '11_metrics');
        $this->assertFalse(
            $condition11['met'],
            'A DRFAILOVER AAR with no rto_actual_minutes recorded, and none listed as not_measured, '
            .'should fail condition 11 — the cadence-type extra refusal.'
        );

        // Recording the actual — 3h12m (192 minutes) against a 2h (120
        // minute) target, the prompt's own named scenario — and the rest of
        // DRFAILOVER's required metrics satisfies it.
        app(AarService::class)->update($aar, [
            'quantitative_results' => [
                'metrics' => [
                    'rto_target_minutes' => 120, 'rto_actual_minutes' => 192,
                    'rpo_target_minutes' => 15, 'rpo_actual_minutes' => 10,
                    'met_objectives' => false, 'downtime_minutes' => 192,
                    'threshold_breached' => true,
                ],
            ],
        ]);

        $conditions = app(AarService::class)->conditions($aar->fresh());
        $condition11 = collect($conditions)->firstWhere('key', '11_metrics');
        $this->assertTrue($condition11['met'], 'DRFAILOVER with every required metric recorded should satisfy condition 11.');
    }

    /* ------------------------------------------------------------------ */
    /*  Condition 12 — evidence hash verification, and immutability. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_tampered_evidence_file_refuses_finalisation_by_name(): void
    {
        $occurrence = $this->startedOccurrence();
        $evidence = app(EvidenceService::class)->upload(
            $occurrence,
            UploadedFile::fake()->createWithContent('sign-in-sheet.txt', 'the original bytes'),
            Evidence::KIND_OCCURRENCE,
            $occurrence->getKey(),
            'file',
            $this->facilitator,
        );

        // Tamper the stored bytes after upload — the hash on the row no
        // longer matches what is on disk.
        Storage::disk(FileUploadService::DISK)->put($evidence->file_path, 'tampered bytes');

        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true);

        $conditions = app(AarService::class)->conditions($aar);
        $condition12 = collect($conditions)->firstWhere('key', '12_evidence');
        $this->assertFalse($condition12['met']);
        $this->assertStringContainsString($evidence->file_name, $condition12['message']);

        $this->expectException(InvalidArgumentException::class);
        app(AarService::class)->finalise($aar, $this->approver);
    }

    #[Test]
    public function finalising_locks_evidence_and_freezes_the_report_and_a_later_edit_is_refused_and_logged(): void
    {
        $occurrence = $this->startedOccurrence();
        $evidence = app(EvidenceService::class)->upload(
            $occurrence,
            UploadedFile::fake()->createWithContent('assembly.txt', 'headcount reconciliation'),
            Evidence::KIND_OCCURRENCE,
            $occurrence->getKey(),
            'file',
            $this->facilitator,
        );

        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true);
        $aar->update(['summary' => 'All clear.', 'what_worked' => 'Marshals responded fast.', 'what_failed' => 'Nothing of note.']);

        $final = app(AarService::class)->finalise($aar->fresh(), $this->approver);
        $this->assertSame('final', $final->status);
        $this->assertNotNull($final->approved_at);

        $evidence->refresh();
        $this->assertTrue($evidence->isLocked());

        // An edit attempt on a frozen field is refused.
        $this->expectException(InvalidArgumentException::class);
        app(AarService::class)->update($final, ['summary' => 'Changed my mind.']);
    }

    /**
     * Gate 2 defect 1: `refreshComputedSections()` used to rewrite
     * `quantitative_results` from live data on EVERY read and export, even
     * once the report was final — so a manual check-in, a page render or an
     * export recorded after finalisation could silently move a signed-off
     * report's attendance figures, ladder warnings and CALLTREE metrics.
     */
    #[Test]
    public function a_final_aars_quantitative_results_is_byte_identical_across_a_check_in_attempt_a_render_and_an_export(): void
    {
        $occurrence = $this->startedOccurrence();

        $participant = ExerciseParticipant::query()->create([
            'organization_id' => $this->organization->id,
            'occurrence_id' => $occurrence->getKey(),
            'user_id' => $this->evaluator->id,
            'role' => 'participant',
            'business_unit_id' => $this->unit->id,
            'invitation_status' => 'accepted',
            'attendance_status' => 'unknown',
        ]);

        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true);
        $aar->update(['summary' => 'All clear.', 'what_worked' => 'Fine.', 'what_failed' => 'Nothing.']);
        $final = app(AarService::class)->finalise($aar->fresh(), $this->approver);

        $before = $final->fresh()->quantitative_results;

        // A manual check-in attempt after finalisation, through the real
        // route: refused (Gate 2 defect 2) — and, the point of THIS test,
        // the snapshot must not have moved either way.
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.check-in', $occurrence), [
            'method' => 'manual',
            'participant_id' => $participant->getKey(),
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertNull($participant->fresh()->checked_in_at, 'The frozen check-in guard must have refused this write.');
        $this->assertSame($before, $final->fresh()->quantitative_results, 'A refused check-in attempt must not rewrite a final AAR.');

        // A page render.
        $this->actingAs($this->approver)->get(route('bcms.aars.show', $final))->assertOk();
        $this->assertSame($before, $final->fresh()->quantitative_results, 'A page render must not rewrite a final AAR.');

        // The examiner export.
        app(AarExportService::class)->build($occurrence->fresh());
        $this->assertSame($before, $final->fresh()->quantitative_results, 'The export must not rewrite a final AAR.');
    }

    #[Test]
    public function a_locked_evidence_row_refuses_delete_and_logs_the_refusal(): void
    {
        $occurrence = $this->startedOccurrence();
        $evidence = app(EvidenceService::class)->upload(
            $occurrence,
            UploadedFile::fake()->createWithContent('assembly.txt', 'headcount reconciliation'),
            Evidence::KIND_OCCURRENCE,
            $occurrence->getKey(),
            'file',
            $this->facilitator,
        );

        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true);
        $aar->update(['summary' => 'All clear.', 'what_worked' => 'Fine.', 'what_failed' => 'Nothing.']);
        app(AarService::class)->finalise($aar->fresh(), $this->approver);

        try {
            app(EvidenceService::class)->delete($evidence->fresh(), $this->facilitator);
            $this->fail('Deleting a locked evidence row should have been refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => Evidence::class,
            'auditable_id' => $evidence->getKey(),
            'event' => 'evidence_delete_refused_locked',
        ]);

        // The timeline is frozen too — logging after finalise is refused.
        $this->expectException(InvalidArgumentException::class);
        app(TimelineService::class)->log($occurrence->fresh(), 'manual', 'late addition');
    }

    /* ------------------------------------------------------------------ */
    /*  Reopen: does not unlock evidence, requires re-approval. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function reopening_a_final_report_does_not_unlock_its_evidence(): void
    {
        $occurrence = $this->startedOccurrence();
        $evidence = app(EvidenceService::class)->upload(
            $occurrence,
            UploadedFile::fake()->createWithContent('assembly.txt', 'headcount reconciliation'),
            Evidence::KIND_OCCURRENCE,
            $occurrence->getKey(),
            'file',
            $this->facilitator,
        );

        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true);
        $aar->update(['summary' => 'All clear.', 'what_worked' => 'Fine.', 'what_failed' => 'Nothing.']);
        $final = app(AarService::class)->finalise($aar->fresh(), $this->approver);

        $reopened = app(AarService::class)->reopen($final, $this->approver, 'The RTO figure was transcribed wrong.');
        $this->assertSame('draft', $reopened->status);
        $this->assertNull($reopened->approved_at);

        $this->assertTrue($evidence->fresh()->isLocked(), 'Reopening a final AAR must not unlock its existing evidence.');
        $this->assertDatabaseHas('bcms_exercise_timeline', [
            'occurrence_id' => $occurrence->getKey(),
            'entry_type' => 'system',
        ]);
    }

    /**
     * Clause map refinement 8: "the T+3/T+7 AAR-overdue rungs must be voided
     * on status = final, not on AAR creation." `ReminderLadder` materialises
     * `exercise.aar_due` (T+1), `.aar_overdue` (T+3) and `.aar_escalation`
     * (T+7) the moment the occurrence gets a date — long before anybody has
     * written a word of the report — so a report finalised on day 2 must not
     * let the T+3/T+7 chasers fire about a report that already exists, and
     * reopening it must bring them back rather than leaving them silenced
     * for good.
     */
    #[Test]
    public function finalising_the_aar_voids_its_overdue_chasers_and_reopening_resumes_them(): void
    {
        $occurrence = $this->startedOccurrence();
        app(ReminderScheduleBuilder::class)->build($occurrence->refresh());

        $chaserIds = ReminderSchedule::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->whereIn('template_key', ['exercise.aar_due', 'exercise.aar_overdue', 'exercise.aar_escalation'])
            ->pluck('id');
        $this->assertGreaterThan(0, $chaserIds->count(), 'The ladder must materialise its AAR chasers before this test can prove they are voided.');
        $this->assertSame(
            $chaserIds->count(),
            ReminderSchedule::query()->whereIn('id', $chaserIds)->where('status', 'pending')->count(),
        );

        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true);
        $aar->update(['summary' => 'All clear.', 'what_worked' => 'Fine.', 'what_failed' => 'Nothing.']);

        $final = app(AarService::class)->finalise($aar->fresh(), $this->approver);

        $this->assertSame(
            0,
            ReminderSchedule::query()->whereIn('id', $chaserIds)->where('status', 'pending')->count(),
            'Finalising the AAR must void its T+3/T+7 chaser rungs rather than leave them pending.',
        );
        $this->assertSame(
            $chaserIds->count(),
            ReminderSchedule::query()->whereIn('id', $chaserIds)->where('status', 'voided')->count(),
        );

        // A dispatcher tick at a time when every chaser would have been due
        // sends none of them — they are not `pending`, so `dispatchDue()`
        // never even considers them.
        app(ReminderDispatcher::class)->dispatchDue($occurrence->scheduled_date->copy()->addDays(10));
        $this->assertSame(0, ReminderSchedule::query()->whereIn('id', $chaserIds)->where('status', 'sent')->count());

        // Reopening resumes (or re-materialises) them — the counterpart rule.
        app(AarService::class)->reopen($final, $this->approver, 'The RTO figure was transcribed wrong.');

        $this->assertGreaterThan(
            0,
            ReminderSchedule::query()
                ->where('occurrence_id', $occurrence->getKey())
                ->whereIn('template_key', ['exercise.aar_due', 'exercise.aar_overdue', 'exercise.aar_escalation'])
                ->where('status', 'pending')
                ->count(),
            'Reopening a final AAR must resume or re-materialise its chaser rungs.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The examiner export carries the evidence index. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_export_carries_the_full_evidence_index(): void
    {
        $occurrence = $this->startedOccurrence();
        $evidence = app(EvidenceService::class)->upload(
            $occurrence,
            UploadedFile::fake()->createWithContent('assembly.txt', 'headcount reconciliation'),
            Evidence::KIND_OCCURRENCE,
            $occurrence->getKey(),
            'file',
            $this->facilitator,
        );

        // The export reads the AAR the occurrence's own `complete()` creates
        // — there is nothing to export before that draft exists.
        $this->completeExercise($occurrence, scoreAll: false, logMilestone: false);

        $payload = $this->actingAs($this->facilitator)
            ->get(route('bcms.occurrences.aar.export', $occurrence))
            ->json();

        $this->assertArrayHasKey('evidence_index', $payload);
        $this->assertSame($evidence->file_name, $payload['evidence_index'][0]['file_name']);
        $this->assertSame($evidence->hash, $payload['evidence_index'][0]['hash']);
        $this->assertArrayHasKey('findings', $payload);
        $this->assertArrayHasKey('timeline', $payload);
    }

    /* ------------------------------------------------------------------ */
    /*  Finding → CAPA due-date rule (clause map §3.3). */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_capa_raised_from_an_aar_gets_the_computed_due_date_when_none_is_supplied(): void
    {
        $occurrence1 = $this->startedOccurrence(sequence: 1);
        $aar = $this->completeExercise($occurrence1, scoreAll: true, logMilestone: true);

        // The next occurrence of the same definition, close enough that the
        // 5-working-day floor before it is what actually binds.
        $occurrence2 = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $occurrence1->definition_id,
            'sequence_no' => 2,
            'scheduled_date' => now()->addDays(10)->toDateString(),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $this->facilitator->id,
        ]);

        $this->actingAs($this->facilitator)->post(route('bcms.findings.store'), [
            'source' => 'aar',
            'aar_id' => $aar->getKey(),
            'classification' => 'improvement',
            'severity' => 'high',
            'description' => 'A must-reach node was never confirmed.',
        ])->assertRedirect();

        $finding = Finding::query()->where('description', 'A must-reach node was never confirmed.')->sole();
        $this->assertSame(FindingSeverity::High, $finding->severity);

        $this->actingAs($this->facilitator)->post(route('bcms.actions.store', $finding), [
            'title' => 'Reconfirm the node contact',
        ])->assertRedirect();

        $action = CorrectiveAction::query()->where('finding_id', $finding->getKey())->sole();
        $this->assertNotNull($action->due_date, 'A CAPA raised from an AAR finding with no due date must get the computed default.');

        // High defaults to +60 days, but occurrence2 is only 10 days out, so
        // the "5 working days before the next occurrence" branch must have
        // bound tighter than the severity default.
        $this->assertTrue($action->due_date->lt(now()->addDays(60)));
        $occurrence2->delete();
    }

    /* ------------------------------------------------------------------ */
    /*  Cross-tenant isolation on the AAR routes. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function aar_routes_404_for_a_different_tenant(): void
    {
        $occurrence = $this->startedOccurrence();
        $aar = $this->completeExercise($occurrence, scoreAll: true, logMilestone: true);

        $otherOrg = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $otherUser = User::query()->create([
            'name' => 'Outsider', 'email' => 'outsider@ob.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $otherOrg->id, 'is_active' => true,
        ]);
        $this->givePermission($otherUser, 'bcms.exercise.view');
        $this->givePermission($otherUser, 'bcms.aar.manage');

        $this->actingAs($otherUser)->get(route('bcms.aars.show', $aar))->assertNotFound();
        $this->actingAs($otherUser)->patch(route('bcms.aars.update', $aar), ['summary' => 'x'])->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /*  AI draft: unavailable in this test environment, and refuses cleanly. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function requesting_an_ai_draft_never_sets_outcome_or_raises_a_finding_on_its_own(): void
    {
        $occurrence = $this->startedOccurrence();
        $aar = $this->completeExercise($occurrence, scoreAll: false, logMilestone: false);
        $outcomeBeforeDraft = $occurrence->fresh()->outcome;

        $this->actingAs($this->facilitator)
            ->post(route('bcms.aars.ai-draft', $aar))
            ->assertRedirect();

        // With no LLM gateway configured in this test environment, the draft
        // is refused rather than fabricated — either way, `AarAiDrafter::
        // draft()` never touches the occurrence at all (clause map
        // refinement 11: "it may not set outcome"), so the value `complete()`
        // recorded is exactly what is still there, and no finding is raised
        // as a side effect of drafting.
        $this->assertSame($outcomeBeforeDraft, $occurrence->fresh()->outcome);
        $this->assertSame(0, Finding::query()->where('aar_id', $aar->getKey())->count());
    }

    /**
     * The positive path the negative test above cannot exercise (no LLM
     * gateway is configured in this environment by default): a real draft,
     * faked at the HTTP boundary the same way `Phase2BiaEngineTest::
     * posting_to_the_props_ai_draft_url_drafts_and_flags_it_as_ai_generated()`
     * does for `BiaAiDrafter`. `Http::preventStrayRequests()` makes the fake
     * below the only network path this test can take. Acceptance criterion 7
     * ("AI draft produces a coherent AAR ... lands in draft with provenance")
     * and ADR 0015 §9's usage ledger are both unverifiable without this.
     */
    #[Test]
    public function a_successful_ai_draft_flags_ai_generated_and_records_an_llm_usage_event(): void
    {
        Http::preventStrayRequests();

        config()->set('services.llm.enabled', true);
        config()->set('bcms.ai.capabilities.aar_synthesis', true);
        config()->set('llm.default_profile', 'local-ollama');
        config()->set('llm.profiles', [
            'local-ollama' => [
                'label' => 'Local model', 'endpoint' => 'http://localhost:11434', 'model' => 'granite4:micro',
                'keep_alive' => '30m', 'unit_cost_per_1k_tokens_minor' => null, 'currency' => null,
            ],
        ]);
        app(\App\Services\Bcms\BcmsSettings::class)->update(['ai_enabled' => true], $this->organization->id);

        Http::fake([
            '*/api/tags' => Http::response(['models' => []], 200),
            '*/api/generate' => Http::response([
                'response' => json_encode([
                    'summary' => 'The fire drill ran to plan, with one marshal delay noted.',
                    'what_worked' => 'Assembly point marshals responded within target.',
                    'what_failed' => 'One marshal radio failed mid-drill.',
                    'suggested_findings' => [
                        ['description' => 'A marshal radio failed during the drill.', 'classification' => 'improvement', 'root_cause' => 'Battery not checked pre-drill.'],
                    ],
                ]),
                'prompt_eval_count' => 40, 'eval_count' => 20,
            ], 200),
        ]);

        $occurrence = $this->startedOccurrence();
        $aar = $this->completeExercise($occurrence, scoreAll: false, logMilestone: false);
        $outcomeBeforeDraft = $occurrence->fresh()->outcome;

        $this->actingAs($this->facilitator)
            ->post(route('bcms.aars.ai-draft', $aar))
            ->assertRedirect()
            ->assertSessionHas('success');

        $aar->refresh();
        $this->assertTrue((bool) $aar->ai_generated);
        $this->assertNotNull($aar->ai_draft_generated_at);
        $this->assertStringContainsString('marshal delay', (string) $aar->summary);

        // Refinement 11: the AI draft never sets the occurrence's outcome and
        // never raises a finding on its own — it only proposes one.
        $this->assertSame($outcomeBeforeDraft, $occurrence->fresh()->outcome);
        $this->assertSame(0, Finding::query()->where('aar_id', $aar->getKey())->count());

        // ADR 0015 §9 — a draft that updates the AAR but leaves no
        // `llm_usage_events` row would be invisible to cost and usage
        // reporting.
        $this->assertDatabaseHas('llm_usage_events', [
            'organization_id' => $this->organization->id,
            'module' => 'bcms',
            'service' => 'aar_synthesis',
        ]);
    }

    /**
     * `docs/compliance/ndpa-register.md` §8.3 records, as a known gap owned by
     * the backend engineer "at the Phase 9 re-gate", that the published
     * `bcms.aar.feedback.v1` contract (clause map §2.3) — comments carry role
     * and unit, NEVER a `user_id` and NEVER a name — is enforced by nothing:
     * `UpdateAarRequest` validates `participant_feedback` as a bare
     * `['nullable', 'array']`. This test documents that gap directly; it is
     * expected to fail until the update path strips or rejects either key.
     */
    #[Test]
    public function participant_feedback_comments_can_smuggle_a_user_identifier_because_nothing_strips_it(): void
    {
        $occurrence = $this->startedOccurrence();
        $aar = $this->completeExercise($occurrence, scoreAll: false, logMilestone: false);

        $this->actingAs($this->facilitator)->patch(route('bcms.aars.update', $aar), [
            'participant_feedback' => [
                'schema' => 'bcms.aar.feedback.v1',
                'comments' => [[
                    'text' => 'The branch manager did not know who could activate the plan.',
                    'user_id' => $this->evaluator->getKey(),
                    'name' => 'Evaluator Name',
                ]],
            ],
        ])->assertRedirect();

        $stored = $aar->fresh()->participant_feedback['comments'][0] ?? [];

        $this->assertArrayNotHasKey(
            'user_id', $stored,
            'participant_feedback accepted a user_id on a comment — the NDPA-required stripping (§2.3, '
            .'ndpa-register.md §8.3) is not enforced.'
        );
        $this->assertArrayNotHasKey(
            'name', $stored,
            'participant_feedback accepted a name on a comment — the NDPA-required stripping (§2.3, '
            .'ndpa-register.md §8.3) is not enforced.'
        );
    }

    /**
     * The test above only proves the stored row has no `user_id`/`name` —
     * which is also what would happen if the whole request had simply been
     * dropped on the floor. This test proves the *mechanism*: the request is
     * refused by validation (session errors named on the exact keys), not
     * silently accepted-and-stripped, and nothing else in the same payload
     * is partially saved alongside the refusal.
     */
    #[Test]
    public function a_comment_with_a_user_identifier_is_refused_by_validation_not_silently_stripped(): void
    {
        $occurrence = $this->startedOccurrence();
        $aar = $this->completeExercise($occurrence, scoreAll: false, logMilestone: false);

        $response = $this->actingAs($this->facilitator)->patch(route('bcms.aars.update', $aar), [
            'summary' => 'This summary must not be saved either — the whole request is one validation unit.',
            'participant_feedback' => [
                'schema' => 'bcms.aar.feedback.v1',
                'comments' => [[
                    'text' => 'The branch manager did not know who could activate the plan.',
                    'user_id' => $this->evaluator->getKey(),
                    'name' => 'Evaluator Name',
                    'email' => 'evaluator@khb.test',
                ]],
            ],
        ]);

        $response->assertSessionHasErrors([
            'participant_feedback.comments.0.user_id',
            'participant_feedback.comments.0.name',
            'participant_feedback.comments.0.email',
        ]);

        $fresh = $aar->fresh();
        $this->assertNotSame(
            'This summary must not be saved either — the whole request is one validation unit.',
            $fresh->summary,
            'A validation failure on participant_feedback must refuse the whole request, not save the other fields '
            .'while quietly dropping the offending key.'
        );
        $this->assertSame(
            [],
            $fresh->participant_feedback['comments'] ?? [],
            'Nothing should have been written at all — the comment was refused, not accepted in a stripped form.'
        );

        // The counterpart: the identical comment minus the forbidden keys
        // goes through and is stored in the published shape.
        $this->actingAs($this->facilitator)->patch(route('bcms.aars.update', $aar), [
            'participant_feedback' => [
                'schema' => 'bcms.aar.feedback.v1',
                'comments' => [[
                    'text' => 'The branch manager did not know who could activate the plan.',
                    'role' => 'branch_manager',
                    'business_unit' => 'BU-OPS',
                ]],
            ],
        ])->assertSessionDoesntHaveErrors();

        $stored = $aar->fresh()->participant_feedback['comments'][0] ?? [];
        $this->assertSame('The branch manager did not know who could activate the plan.', $stored['text'] ?? null);
        $this->assertSame('branch_manager', $stored['role'] ?? null);
        $this->assertArrayNotHasKey('user_id', $stored);
    }

    /**
     * `UpdateAarRequest`'s `prohibited` rules only guard the HTTP boundary.
     * `AarService::normaliseFeedback()` is the belt-and-braces the notes
     * claim exists for "an import or the AI drafter" — any caller that
     * reaches `AarService::update()` directly, never through the Form
     * Request. Prove it here by calling the service directly with a payload
     * a Form Request would have refused outright.
     */
    #[Test]
    public function a_service_level_caller_bypassing_the_form_request_cannot_smuggle_an_identifier_either(): void
    {
        $occurrence = $this->startedOccurrence();
        $aar = $this->completeExercise($occurrence, scoreAll: false, logMilestone: false);

        $updated = app(AarService::class)->update($aar, [
            'participant_feedback' => [
                'schema' => 'bcms.aar.feedback.v1',
                'comments' => [[
                    'text' => 'Nobody at the assembly point knew the escalation path.',
                    'role' => 'teller',
                    'business_unit' => 'BU-OPS',
                    'user_id' => $this->evaluator->getKey(),
                    'name' => 'Evaluator Name',
                    'email' => 'evaluator@khb.test',
                ]],
            ],
        ]);

        $stored = $updated->participant_feedback['comments'][0] ?? [];

        $this->assertArrayNotHasKey(
            'user_id', $stored,
            'AarService::update() must normalise participant_feedback even when called directly, bypassing '
            .'UpdateAarRequest — an import or the AI drafter goes through this exact path.'
        );
        $this->assertArrayNotHasKey('name', $stored);
        $this->assertArrayNotHasKey('email', $stored);
        $this->assertSame('Nobody at the assembly point knew the escalation path.', $stored['text'] ?? null);
        $this->assertSame('teller', $stored['role'] ?? null);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function user(string $email): User
    {
        return User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')),
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);
    }

    private function givePermission(User $user, string $permission): void
    {
        $role = \Spatie\Permission\Models\Role::findOrCreate('bcms9aar-'.md5($permission.$user->email), 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        $user->assignRole($role);
    }

    private function startedOccurrence(int $sequence = 1): ExerciseOccurrence
    {
        $type = ExerciseType::query()->where('code', 'FIREDRILL')->sole();

        $definition = app(ExerciseDefinitionService::class)->create(
            $this->programme,
            $type,
            'Fire drill '.Str::random(4),
            [
                'business_unit_id' => $this->unit->id,
                'owner_id' => $this->approver->id,
                'facilitator_id' => $this->facilitator->id,
                'status' => 'active',
            ],
            $this->approver->id,
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => $sequence,
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $this->facilitator->id,
        ]);

        app(ReadinessService::class)->materialise($occurrence);
        $occurrence->refresh();

        app(OccurrenceExecutionService::class)->start($occurrence, $this->facilitator);

        return $occurrence->refresh();
    }

    /**
     * Runs the occurrence to completion and returns its draft AAR, optionally
     * scoring every objective and logging a milestone so most gate
     * conditions are already met.
     */
    private function completeExercise(
        ExerciseOccurrence $occurrence,
        bool $scoreAll,
        bool $logMilestone,
        bool $lowScore = false,
        bool $fillRequiredMetrics = true,
    ): Aar {
        if ($logMilestone) {
            app(TimelineService::class)->log($occurrence, 'milestone', 'Assembly complete.', $this->facilitator);
        }

        if ($scoreAll) {
            $objectives = app(ScoringService::class)->objectivesFor($occurrence);

            foreach ($objectives as $i => $objective) {
                app(ScoringService::class)->score(
                    $occurrence,
                    $objective['index'],
                    $lowScore && $i === 0 ? 2 : 4,
                    $lowScore && $i === 0 ? 'Marshals were slow.' : null,
                    $this->evaluator,
                );
            }
        }

        // Every participant must be accounted for (condition 7).
        $occurrence->participants()->update(['attendance_status' => 'present']);

        app(OccurrenceExecutionService::class)->complete($occurrence, $this->facilitator, 'pass_with_findings');

        $aar = $occurrence->fresh()->aar;

        if ($fillRequiredMetrics) {
            // FIREDRILL's required `metrics` (clause map §2.2): headcount is
            // auto-computed by `refreshComputedSections()` from participants;
            // `target_seconds` and `unaccounted_resolved` have no source to
            // compute them from and are always human-supplied. These tests
            // have no checked-in participant, so `time_to_assembly_seconds`
            // auto-computes to null on every refresh (there is nobody whose
            // check-in time it could read) — `not_measured[]` is exactly the
            // "a missing number and a number nobody measured are different
            // facts" case condition 11 asks for, not a fabricated value.
            app(AarService::class)->update($aar, [
                'quantitative_results' => [
                    'metrics' => ['target_seconds' => 300, 'unaccounted_resolved' => true],
                    'not_measured' => [['metric' => 'time_to_assembly_seconds', 'reason' => 'No participant checked in during this test scenario.']],
                ],
            ]);
        }

        return $aar->fresh();
    }
}
