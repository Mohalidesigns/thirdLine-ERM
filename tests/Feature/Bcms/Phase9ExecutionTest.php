<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Contact;
use App\Models\Bcms\CorrectiveAction;
use App\Models\Bcms\Evidence;
use App\Models\Bcms\ExerciseInject;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseScore;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Finding;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\TimelineEntry;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Exercises\CheckInService;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Exercises\OccurrenceExecutionService;
use App\Services\Bcms\Reminders\ReadinessService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 9 — the exercise execution workspace: start/complete, timeline,
 * injects, observer scoring, check-in and evidence
 * (plans/bcms/prompts/PHASE-09-execution-aar.md; docs/bcms/phase-9-aar-
 * clause-map.md; ADR 0019).
 *
 * The AAR gate, finalisation and its immutability live in
 * Phase9AarTest — this file is the workspace half.
 */
class Phase9ExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $facilitator;

    private User $owner;

    private User $evaluator;

    private ExerciseProgramme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);
        \Illuminate\Support\Facades\Storage::fake('local');

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
        $this->owner = $this->user('owner@khb.test');
        $this->evaluator = $this->user('evaluator@khb.test');

        $this->givePermission($this->facilitator, 'bcms.exercise.facilitate');
        $this->givePermission($this->facilitator, 'bcms.exercise.view');
        $this->givePermission($this->facilitator, 'bcms.readiness.override');
        $this->givePermission($this->facilitator, 'bcms.finding.manage');
        $this->givePermission($this->facilitator, 'bcms.report.export');
        // ADR 0017 — BCMS visibility is unit-scoped via the shared `RcsaScope`
        // assignment engine, not the `business_unit_id` column on `users`.
        // `rcsa_scope.all_units` is the same "sees the whole tenant" grant
        // `Phase1ScreensTest` already uses for the same reason: an observer
        // brought in from another unit for one exercise should not need a
        // separate business-unit assignment row just to open the workspace.
        $this->givePermission($this->facilitator, 'rcsa_scope.all_units');
        $this->givePermission($this->evaluator, 'bcms.exercise.evaluate');
        $this->givePermission($this->evaluator, 'bcms.exercise.view');
        $this->givePermission($this->evaluator, 'rcsa_scope.all_units');

        $this->programme = app(ExerciseProgrammeService::class)->create(
            (int) now()->year, 'Exercise programme', [], $this->owner->id,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance criterion 1 — a fire drill runs end to end. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_fire_drill_runs_end_to_end_and_carries_its_open_actions_forward(): void
    {
        $definition = $this->definition('FIREDRILL', ['readiness_gating' => false]);
        $occurrence1 = $this->occurrence($definition, 1);

        $alice = $this->user('alice@khb.test');
        $this->contactFor($alice, $occurrence1);

        // Start.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.start', $occurrence1))
            ->assertRedirect(route('bcms.occurrences.workspace', $occurrence1));
        $occurrence1->refresh();
        $this->assertNotNull($occurrence1->actual_start);

        // Check in the participant by their own token.
        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence1->getKey())->firstOrFail();
        $token = app(CheckInService::class)->tokenFor($participant);
        $this->post(route('bcms.check-in.store', $token))->assertRedirect();
        $this->assertNotNull($participant->refresh()->checked_in_at);
        $this->assertSame('qr', $participant->check_in_method);

        // Log a milestone entry (required for the AAR gate's condition 4).
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.timeline.store', $occurrence1), [
            'entry_type' => 'milestone',
            'content' => 'Assembly point confirms everybody accounted for.',
        ])->assertRedirect();
        $this->assertSame(1, TimelineEntry::query()->where('occurrence_id', $occurrence1->getKey())->where('entry_type', 'milestone')->count());

        // Score every objective as the evaluator.
        $objectives = $definition->refresh()->objectives;
        foreach (array_keys($objectives) as $index) {
            $this->actingAs($this->evaluator)->post(route('bcms.occurrences.scores.store', $occurrence1), [
                'objective_index' => $index,
                'score' => 4,
                'commentary' => null,
            ])->assertRedirect();
        }

        // End the exercise — the draft AAR is created here.
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.complete', $occurrence1), [
            'outcome' => 'pass_with_findings',
        ])->assertRedirect();
        $occurrence1->refresh();
        $this->assertNotNull($occurrence1->actual_end);
        $this->assertNotNull($occurrence1->aar);

        // Raise two findings from the AAR with open corrective actions.
        $aar = $occurrence1->aar;
        $refs = [];

        foreach (['assembly point signage was unclear', 'a fire marshal radio failed'] as $description) {
            $this->actingAs($this->facilitator)->post(route('bcms.findings.store'), [
                'source' => 'aar',
                'aar_id' => $aar->getKey(),
                'classification' => 'improvement',
                'description' => $description,
            ])->assertRedirect();

            $finding = Finding::query()->where('description', $description)->sole();
            $action = $this->actingAs($this->facilitator)->post(route('bcms.actions.store', $finding), [
                'title' => 'Fix: '.$description,
            ]);
            $action->assertRedirect();
            $refs[] = CorrectiveAction::query()->where('finding_id', $finding->getKey())->sole()->getKey();
        }

        // The next occurrence of the SAME definition. Opening its workspace
        // (via start()) carries the two open actions forward.
        $occurrence2 = $this->occurrence($definition, 2);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence2))->assertRedirect();

        foreach ($refs as $actionId) {
            $action = CorrectiveAction::query()->find($actionId);
            $this->assertSame($occurrence2->getKey(), $action->carried_to_occurrence_id, 'A corrective action from the previous fire drill was not carried forward as an item to validate.');
        }

        $carried = $this->actingAs($this->facilitator)
            ->get(route('bcms.occurrences.carried-actions', $occurrence2))
            ->json('carried_actions');
        $this->assertCount(2, $carried);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance criterion 2 — the readiness gate and its override. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function starting_with_an_open_blocking_readiness_task_is_refused_until_overridden(): void
    {
        $definition = $this->definition('FIREDRILL', ['readiness_gating' => true]);
        $occurrence = $this->occurrence($definition, 1);

        $task = ReadinessTask::query()->create([
            'organization_id' => $this->organization->id,
            'occurrence_id' => $occurrence->getKey(),
            'title' => 'Confirm marshal roster',
            'is_blocking' => true,
            'status' => 'open',
        ]);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.start', $occurrence))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($occurrence->refresh()->actual_start);

        // The documented override path. The FIREDRILL readiness template
        // materialises six blocking tasks of its own alongside this test's
        // one — every one of them has to be overridden, not just the one
        // this test added, or the gate stays shut for a reason nobody is
        // looking at.
        foreach (ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())
            ->where('is_blocking', true)->where('status', 'open')->get() as $blocking) {
            $this->actingAs($this->facilitator)
                ->post(route('bcms.readiness-tasks.override', $blocking), ['reason' => 'Roster confirmed verbally, paperwork to follow.'])
                ->assertRedirect();
        }

        $task->refresh();
        $this->assertSame('waived', $task->status);
        $this->assertNotNull($task->override_reason);
        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => \App\Models\Bcms\ExerciseOccurrence::class,
            'auditable_id' => $occurrence->getKey(),
            'event' => 'readiness_override',
        ]);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.start', $occurrence))
            ->assertRedirect();
        $this->assertNotNull($occurrence->refresh()->actual_start);
    }

    /* ------------------------------------------------------------------ */
    /*  Gap 1 — the date guard: nothing previously stopped a 2027 exercise
     *  starting today. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function starting_before_the_scheduled_date_is_refused_until_confirmed_and_is_audited(): void
    {
        $definition = $this->definition('FIREDRILL', ['readiness_gating' => false]);
        $occurrence = $this->occurrence($definition, 1);
        $occurrence->update(['scheduled_date' => now()->addMonths(6)->toDateString()]);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.start', $occurrence))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNull($occurrence->refresh()->actual_start, 'An unconfirmed early start must not start the exercise.');

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.start', $occurrence), ['confirmed_early_start' => 1])
            ->assertRedirect(route('bcms.occurrences.workspace', $occurrence));
        $this->assertNotNull($occurrence->refresh()->actual_start);

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => \App\Models\Bcms\ExerciseOccurrence::class,
            'auditable_id' => $occurrence->getKey(),
            'event' => 'exercise_started',
        ]);
        $log = \App\Models\Bcms\AuditLog::query()
            ->where('auditable_type', \App\Models\Bcms\ExerciseOccurrence::class)
            ->where('auditable_id', $occurrence->getKey())
            ->where('event', 'exercise_started')
            ->sole();
        $this->assertTrue((bool) $log->after['started_early'], 'Starting early must be audited as such, even when confirmed.');
    }

    #[Test]
    public function starting_on_or_after_the_scheduled_date_needs_no_confirmation(): void
    {
        $definition = $this->definition('FIREDRILL', ['readiness_gating' => false]);
        $occurrence = $this->occurrence($definition, 1);
        $occurrence->update(['scheduled_date' => now()->toDateString()]);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.start', $occurrence))
            ->assertRedirect(route('bcms.occurrences.workspace', $occurrence));
        $this->assertNotNull($occurrence->refresh()->actual_start);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance criterion 5 — a low score with no commentary is rejected. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_observer_score_below_three_without_commentary_is_rejected(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        // Caught at the Form Request layer first (`required_if:score,1,2`) —
        // a standard validation redirect, not the service's own flash. Both
        // layers refuse it; this is the client-facing one.
        $this->actingAs($this->evaluator)->post(route('bcms.occurrences.scores.store', $occurrence), [
            'objective_index' => 0,
            'score' => 2,
            'commentary' => null,
        ])->assertRedirect()->assertSessionHasErrors('commentary');

        $this->assertDatabaseCount('bcms_exercise_scores', 0);

        // With commentary, it is accepted.
        $this->actingAs($this->evaluator)->post(route('bcms.occurrences.scores.store', $occurrence), [
            'objective_index' => 0,
            'score' => 2,
            'commentary' => 'Marshals were slow to muster.',
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertDatabaseCount('bcms_exercise_scores', 1);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance criterion 4 — injects release on schedule and manually. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function releasing_an_inject_timestamps_it_and_appends_a_timeline_entry(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $inject = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id,
            'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1,
            'release_offset_minutes' => 5,
            'title' => 'The fire alarm sounds a second time',
        ]);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.release', [$occurrence, $inject]))
            ->assertRedirect();

        $inject->refresh();
        $this->assertNotNull($inject->released_at);
        $this->assertSame($this->facilitator->getKey(), $inject->released_by);
        $this->assertDatabaseHas('bcms_exercise_timeline', [
            'occurrence_id' => $occurrence->getKey(),
            'entry_type' => 'inject',
        ]);

        // A second release of the same inject is refused.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.release', [$occurrence, $inject]))
            ->assertRedirect()->assertSessionHas('error');
    }

    /* ------------------------------------------------------------------ */
    /*  Gap 2 — injects can now be authored, not only released. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function an_inject_can_be_authored_edited_deleted_and_reordered(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        // A viewer without facilitate is refused.
        $this->actingAs($this->evaluator)
            ->post(route('bcms.occurrences.injects.store', $occurrence), ['title' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.store', $occurrence), [
                'title' => 'The fire alarm sounds a second time',
                'content' => 'A klaxon repeats every thirty seconds.',
                'release_offset_minutes' => 5,
                'delivery_channel' => 'sim_sms',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $inject = ExerciseInject::query()->where('occurrence_id', $occurrence->getKey())->sole();
        $this->assertSame(1, $inject->sequence);
        $this->assertSame('sim_sms', $inject->delivery_channel);
        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ExerciseInject::class,
            'auditable_id' => $inject->getKey(),
            'event' => 'created',
            // A4: `InjectService::create()` no longer takes a `$by`
            // parameter — the actor still lands here because
            // `BcmsAuditable` reads it from `auth()->user()` on the model
            // event itself, not from an argument.
            'actor_id' => $this->facilitator->getKey(),
        ]);

        // An unknown channel is refused by validation.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.store', $occurrence), [
                'title' => 'Bad channel', 'delivery_channel' => 'sms',
            ])
            ->assertSessionHasErrors('delivery_channel');

        $second = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 2, 'title' => 'A journalist calls the front desk',
        ]);

        // Editing an unreleased inject.
        $this->actingAs($this->facilitator)
            ->patch(route('bcms.occurrences.injects.update', [$occurrence, $inject]), ['title' => 'The alarm sounds again, louder'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('The alarm sounds again, louder', $inject->refresh()->title);
        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ExerciseInject::class,
            'auditable_id' => $inject->getKey(),
            'event' => 'updated',
        ]);

        // Reordering — the payload has to name exactly this occurrence's injects.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), ['inject_ids' => [$second->getKey(), $inject->getKey()]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $second->refresh()->sequence);
        $this->assertSame(2, $inject->refresh()->sequence);
        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => \App\Models\Bcms\ExerciseOccurrence::class,
            'auditable_id' => $occurrence->getKey(),
            'event' => 'injects_reordered',
        ]);

        // Release, then editing and deleting are both refused.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.release', [$occurrence, $second]))
            ->assertRedirect();
        $this->actingAs($this->facilitator)
            ->patch(route('bcms.occurrences.injects.update', [$occurrence, $second]), ['title' => 'Too late'])
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->facilitator)
            ->delete(route('bcms.occurrences.injects.destroy', [$occurrence, $second]))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('bcms_exercise_injects', ['id' => $second->getKey()]);

        // Deleting an unreleased one works.
        $deletedInjectId = $inject->getKey();
        $this->actingAs($this->facilitator)
            ->delete(route('bcms.occurrences.injects.destroy', [$occurrence, $inject]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('bcms_exercise_injects', ['id' => $deletedInjectId]);
        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ExerciseInject::class,
            'auditable_id' => $deletedInjectId,
            'event' => 'deleted',
        ]);
    }

    /**
     * QA gate coverage — A3 (code review, non-EMNS demo-gap set):
     * `update_url`/`delete_url` must be genuinely ABSENT from the payload,
     * not sent as `null`, once an inject is released or for a viewer without
     * `bcms.exercise.facilitate` — the same "hidden either way" contract
     * `attendance.not_checked_in` already uses, and the one `Workspace.jsx`'s
     * `i.update_url || i.delete_url` guard depends on.
     */
    #[Test]
    public function released_and_viewer_only_injects_carry_no_update_or_delete_url(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $unreleased = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'Not yet released',
        ]);
        $released = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 2, 'title' => 'Already released',
            'released_at' => now(), 'released_by' => $this->facilitator->getKey(),
        ]);

        // A facilitator: the unreleased row carries both URLs and a
        // release_url; the released row carries neither update/delete URL
        // and its release_url is absent (null), not a link to release again.
        $this->actingAs($this->facilitator)
            ->get(route('bcms.occurrences.workspace', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('injects.0.title', $unreleased->title)
                ->has('injects.0.update_url')
                ->has('injects.0.delete_url')
                ->has('injects.0.release_url')
                ->where('injects.1.title', $released->title)
                ->missing('injects.1.update_url')
                ->missing('injects.1.delete_url')
                ->where('injects.1.release_url', null)
            );

        // A viewer without facilitate: neither row carries update/delete,
        // regardless of released state.
        $this->actingAs($this->evaluator)
            ->get(route('bcms.occurrences.workspace', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('injects.0.update_url')
                ->missing('injects.0.delete_url')
                ->missing('injects.1.update_url')
                ->missing('injects.1.delete_url')
            );
    }

    #[Test]
    public function the_workspace_shows_the_injects_seeded_on_the_occurrence_and_carries_authoring_urls(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'Seeded inject',
        ]);

        $this->actingAs($this->facilitator)
            ->get(route('bcms.occurrences.workspace', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('injects', 1)
                ->where('injects.0.title', 'Seeded inject')
                ->has('injects.0.update_url')
                ->has('injects.0.delete_url')
                ->has('urls.injects_store')
                ->has('urls.injects_reorder')
                ->has('options.inject_delivery_channels', 6)
            );
    }

    /**
     * QA gate coverage — `InjectService::reorder()`'s own guard: the payload
     * must name EXACTLY the occurrence's current set of injects, checked
     * against the database. A subset (an id missing), a superset (a
     * duplicate), and a foreign id (belonging to a different occurrence)
     * must all be refused, and none may change any `sequence`.
     */
    #[Test]
    public function reordering_injects_refuses_a_subset_a_duplicate_and_a_foreign_id(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $first = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'First inject',
        ]);
        $second = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 2, 'title' => 'Second inject',
        ]);

        $otherOccurrence = $this->occurrence($definition, 2);
        $foreign = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $otherOccurrence->getKey(),
            'sequence' => 1, 'title' => 'Belongs elsewhere',
        ]);

        // A subset — missing $second entirely.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), ['inject_ids' => [$first->getKey()]])
            ->assertRedirect()->assertSessionHas('error');

        // A duplicate id standing in for the missing one.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$first->getKey(), $first->getKey()],
            ])
            ->assertRedirect()->assertSessionHas('error');

        // A foreign id belonging to a different occurrence, in place of $second.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$first->getKey(), $foreign->getKey()],
            ])
            ->assertRedirect()->assertSessionHas('error');

        // None of the refused attempts moved anything.
        $this->assertSame(1, $first->refresh()->sequence);
        $this->assertSame(2, $second->refresh()->sequence);
        $this->assertSame(1, $foreign->refresh()->sequence);
    }

    /**
     * D2. `UpdateExerciseInjectRequest`'s `release_offset_minutes` stays
     * `nullable` — matching `create()`'s own rule — but `InjectService::
     * update()` now coerces an explicit `null` to `0` the same way `create()`
     * treats a missing one, rather than writing `null` straight into a
     * NOT NULL column and 500ing with `SQLSTATE[23000] 1048`.
     */
    #[Test]
    public function patching_a_null_release_offset_is_coerced_to_zero_not_a_500(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $inject = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'Has an offset', 'release_offset_minutes' => 10,
        ]);

        $this->actingAs($this->facilitator)
            ->patch(route('bcms.occurrences.injects.update', [$occurrence, $inject]), [
                'release_offset_minutes' => null,
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, $inject->refresh()->release_offset_minutes);
    }

    /**
     * A5. Reordering is refused once the occurrence is `completed` or
     * `cancelled` — there is no facilitation left for the order to serve.
     */
    #[Test]
    public function reordering_is_refused_once_the_occurrence_is_completed(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $first = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'First inject',
        ]);
        $second = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 2, 'title' => 'Second inject',
        ]);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.complete', $occurrence), ['outcome' => 'pass'])
            ->assertRedirect();
        $this->assertSame(OccurrenceStatus::Completed, $occurrence->refresh()->status);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$second->getKey(), $first->getKey()],
            ])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, $first->refresh()->sequence);
        $this->assertSame(2, $second->refresh()->sequence);
    }

    /**
     * A5. A released inject's `sequence` is fixed history — a reorder that
     * would move it is refused, even though the unreleased ones around it
     * may still move.
     */
    #[Test]
    public function reordering_refuses_to_move_a_released_injects_sequence(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $first = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'Released first', 'released_at' => now(), 'released_by' => $this->facilitator->getKey(),
        ]);
        $second = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 2, 'title' => 'Still scripted',
        ]);

        // Swapping them would move the released inject off sequence 1.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$second->getKey(), $first->getKey()],
            ])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, $first->refresh()->sequence);
        $this->assertSame(2, $second->refresh()->sequence);

        // A THIRD, unreleased inject may still be placed around it — only
        // the released one's own position is frozen.
        $third = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 3, 'title' => 'Newly scripted',
        ]);

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$first->getKey(), $third->getKey(), $second->getKey()],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $first->refresh()->sequence);
        $this->assertSame(2, $third->refresh()->sequence);
        $this->assertSame(3, $second->refresh()->sequence);
    }

    /**
     * Code review defect 1: `delete()` leaves a gap in `sequence`, so
     * comparing a released inject's raw `sequence` against its new 1-based
     * position drifts apart the moment anything has ever been deleted — and
     * never recovers. Repro: author A/B/C (1/2/3); release C; delete A
     * (B, C now stored as 2, 3 — a gap at 1); add D (max()+1 = 4, so D is
     * stored as 4). C's RANK among {B, C, D} is still 2nd, even though its
     * stored `sequence` is 3.
     */
    #[Test]
    public function reordering_after_a_gap_uses_the_released_injects_rank_not_its_stored_sequence(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $a = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'A',
        ]);
        $b = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 2, 'title' => 'B',
        ]);
        $c = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 3, 'title' => 'C', 'released_at' => now(), 'released_by' => $this->facilitator->getKey(),
        ]);

        $this->actingAs($this->facilitator)
            ->delete(route('bcms.occurrences.injects.destroy', [$occurrence, $a]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.store', $occurrence), ['title' => 'D'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $d = ExerciseInject::query()->where('title', 'D')->sole();

        // The gap is real: B and C kept their old stored numbers, D landed
        // past C, not past the gap.
        $this->assertSame(2, $b->refresh()->sequence);
        $this->assertSame(3, $c->refresh()->sequence);
        $this->assertSame(4, $d->refresh()->sequence);

        // The UNCHANGED order must still succeed — this is exactly what the
        // old sequence-vs-position check refused forever once the gap
        // existed.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$b->getKey(), $c->getKey(), $d->getKey()],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $b->refresh()->sequence);
        $this->assertSame(2, $c->refresh()->sequence);
        $this->assertSame(3, $d->refresh()->sequence);

        // Moving the unreleased ones around the released one, while C KEEPS
        // its rank (2nd of 3), succeeds.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$d->getKey(), $c->getKey(), $b->getKey()],
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $d->refresh()->sequence);
        $this->assertSame(2, $c->refresh()->sequence);
        $this->assertSame(3, $b->refresh()->sequence);

        // Moving C's RANK — 1st instead of 2nd — is still refused.
        $this->actingAs($this->facilitator)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), [
                'inject_ids' => [$c->getKey(), $d->getKey(), $b->getKey()],
            ])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, $d->refresh()->sequence);
        $this->assertSame(2, $c->refresh()->sequence);
        $this->assertSame(3, $b->refresh()->sequence);
    }

    /* ------------------------------------------------------------------ */
    /*  Acceptance criterion 3 — check-in: token, short code, manual. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function check_in_works_by_token_by_short_code_and_manually_and_is_idempotent(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $alice = $this->user('alice@khb.test');
        $bob = $this->user('bob@khb.test');
        $carol = $this->user('carol@khb.test');
        $this->contactFor($alice, $occurrence);
        $this->contactFor($bob, $occurrence);
        $this->contactFor($carol, $occurrence);

        $checkIn = app(CheckInService::class);
        $pAlice = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())->where('user_id', $alice->getKey())->sole();
        $pBob = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())->where('user_id', $bob->getKey())->sole();
        $pCarol = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())->where('user_id', $carol->getKey())->sole();

        // Token path.
        $this->post(route('bcms.check-in.store', $checkIn->tokenFor($pAlice)))->assertRedirect();
        $this->assertNotNull($pAlice->refresh()->checked_in_at);

        // A second tap on the same link is recorded, not an error.
        $this->post(route('bcms.check-in.store', $checkIn->tokenFor($pAlice)))->assertRedirect();
        $this->get(route('bcms.check-in.show', $checkIn->tokenFor($pAlice)))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('done', true));

        // Short-code path. Advisory 12 (Gate 2): a redirect, not a direct
        // render, so a refresh of the resulting page cannot resubmit the code.
        // Advisory 7a (Gate 2 review #3): recorded as 'code', not 'sms' —
        // this is a web submission of the short-code FORM, and nothing in
        // this module parses an inbound SMS reply into a check-in.
        $this->post(route('bcms.check-in.code.store'), ['code' => $checkIn->shortCodeFor($pBob)])
            ->assertRedirect(route('bcms.check-in.show', $checkIn->tokenFor($pBob)));
        $this->assertSame('code', $pBob->refresh()->check_in_method);

        // Manual, by the facilitator, from the workspace.
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.check-in', $occurrence), [
            'method' => 'manual',
            'participant_id' => $pCarol->getKey(),
        ])->assertRedirect();
        $this->assertSame('manual', $pCarol->refresh()->check_in_method);

        // An unknown token never reveals which exercise it might have belonged to.
        $this->get(route('bcms.check-in.show', 'chk-999999-'.str_repeat('a', 16)))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ok', false));
    }

    /**
     * Advisory 3 (Gate 2 review #3): `CheckInService::TOKEN_PATTERN` used to
     * be unanchored (`\b...\b`) and case-insensitive (`/i`), so a decorated
     * token — uppercased, prefixed, or with junk appended — still matched
     * the same participant id/tag pair while presenting a DIFFERENT string
     * to the `bcms-check-in-token` limiter (keyed on the route's raw
     * `{token}` path segment, verbatim). That let decoration sidestep the
     * per-token rate limit: ten variants of the same token, ten separate
     * buckets, the participant resolves every time. Anchored and
     * case-sensitive now — none of these resolve, only the undecorated
     * token does.
     */
    #[Test]
    public function a_decorated_token_is_refused_even_though_the_undecorated_token_resolves(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $alice = $this->user('decorated-token-alice@khb.test');
        $this->contactFor($alice, $occurrence);
        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
            ->where('user_id', $alice->getKey())->sole();

        $checkIn = app(CheckInService::class);
        $token = $checkIn->tokenFor($participant);

        foreach ([
            strtoupper($token),
            'x.'.$token,
            $token.'-junk',
            ' '.$token,
            $token.' ',
            // PHP's `$` also matches before a trailing newline; the pattern
            // ends in `\z` so this one is refused too (review #4 advisory 1).
            $token."\n",
        ] as $decorated) {
            $this->assertNull(
                $checkIn->participantForToken($decorated),
                "Decorated token unexpectedly resolved a participant: \"{$decorated}\"",
            );
        }

        // The plain, undecorated token still resolves — the refusals above
        // are about the decoration, not a pattern that now rejects
        // everything.
        $resolved = $checkIn->participantForToken($token);
        $this->assertNotNull($resolved);
        $this->assertSame($participant->getKey(), $resolved->getKey());
    }

    /* ------------------------------------------------------------------ */
    /*  Evidence — upload and delete-before-lock (ADR 0019). */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function evidence_can_be_uploaded_and_deleted_before_the_aar_locks_it(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $file = UploadedFile::fake()->createWithContent('assembly-point.txt', 'headcount sheet, 340 present');

        $this->actingAs($this->facilitator)->post(route('bcms.evidence.store', $occurrence), [
            'file' => $file,
            'owner_type' => Evidence::KIND_OCCURRENCE,
            'owner_id' => $occurrence->getKey(),
            'kind' => 'file',
            'caption' => 'Headcount sheet',
        ])->assertRedirect()->assertSessionMissing('error');

        $evidence = Evidence::query()->where('occurrence_id', $occurrence->getKey())->sole();
        $this->assertSame(64, strlen($evidence->hash));
        $this->assertFalse($evidence->isLocked());

        $this->actingAs($this->facilitator)
            ->delete(route('bcms.evidence.destroy', [$occurrence, $evidence]))
            ->assertRedirect()->assertSessionMissing('error');

        $this->assertNotNull($evidence->fresh()->deleted_at);
    }

    /**
     * DoD: "every evidence-bearing artefact carries an iso_clause_ref."
     * `bcms_evidence.iso_clause_ref` exists on the table for exactly this
     * (ADR 0019 §1's column list) — but neither `StoreEvidenceRequest` nor
     * `EvidenceController::store()` ever supplies one to
     * `EvidenceService::upload()`'s optional `$isoClauseRef` parameter, so
     * every row created through the real, permissioned HTTP route is
     * stamped null. This test documents that gap; it is expected to fail
     * until the upload path is made to set one (e.g. from the occurrence's
     * own `iso22301.8.5.exercise` per the clause map §1.2 row 1, or a
     * caller-supplied value validated against the same taxonomy every other
     * evidence-bearing artefact in this module uses).
     */
    #[Test]
    public function every_evidence_artefact_carries_an_iso_clause_ref(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $file = UploadedFile::fake()->createWithContent('assembly-point-2.txt', 'headcount sheet, 340 present');

        $this->actingAs($this->facilitator)->post(route('bcms.evidence.store', $occurrence), [
            'file' => $file,
            'owner_type' => Evidence::KIND_OCCURRENCE,
            'owner_id' => $occurrence->getKey(),
            'kind' => 'file',
            'caption' => 'Headcount sheet',
        ])->assertRedirect()->assertSessionMissing('error');

        $evidence = Evidence::query()->where('occurrence_id', $occurrence->getKey())->sole();

        $this->assertNotNull(
            $evidence->iso_clause_ref,
            'An evidence row uploaded through the real HTTP route has no iso_clause_ref — the column '
            .'exists but nothing on the upload path ever sets it.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Retirement of the orphan `evidence_file_id` columns (ADR 0019 §3). */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function no_bcms_controller_or_service_still_writes_evidence_file_id(): void
    {
        $offenders = [];

        foreach ([
            ...glob(app_path('Http/Controllers/Bcms/*.php')),
            ...glob(app_path('Services/Bcms/**/*.php')),
        ] as $file) {
            $source = file_get_contents($file);

            if (preg_match('/[\'"]evidence_file_id[\'"]\s*=>/', $source) === 1
                && ! str_contains($file, 'ExecutionController.php') // reads has_evidence-style facts only
            ) {
                // Reading the column (e.g. a `?->evidence_file_id`) is fine;
                // only an array key assignment intended for create/update/fill
                // is the defect this guards against.
                if (preg_match('/->(?:create|update|fill|forceFill)\s*\(\s*\[[^\]]*[\'"]evidence_file_id[\'"]\s*=>/s', $source) === 1) {
                    $offenders[] = $file;
                }
            }
        }

        $this->assertSame([], $offenders, "These files still write the retired `evidence_file_id` column:\n".implode("\n", $offenders));
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 defect 2 — check-in is frozen once the AAR is final, and audited. */
    /* ------------------------------------------------------------------ */

    /**
     * Before this fix, `CheckInService::checkIn()` had no status check at
     * all — a facilitator could mark a straggler present on a completed
     * occurrence whose after-action report had already been signed off.
     */
    #[Test]
    public function a_check_in_is_refused_once_the_aar_is_final(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        app(OccurrenceExecutionService::class)->start($occurrence->refresh(), $this->facilitator);

        app(\App\Services\Bcms\Exercises\TimelineService::class)
            ->log($occurrence, 'milestone', 'Assembly complete.', $this->facilitator);
        $occurrence->participants()->update(['attendance_status' => 'present']);

        $scoring = app(\App\Services\Bcms\Exercises\ScoringService::class);
        foreach ($scoring->objectivesFor($occurrence) as $objective) {
            $scoring->score($occurrence, $objective['index'], 4, null, $this->evaluator);
        }

        app(OccurrenceExecutionService::class)->complete($occurrence, $this->facilitator, 'pass_with_findings');

        $aar = $occurrence->fresh()->aar;
        app(\App\Services\Bcms\Exercises\AarService::class)->update($aar, [
            'summary' => 'All clear.', 'what_worked' => 'Fine.', 'what_failed' => 'Nothing.',
            'quantitative_results' => [
                'metrics' => ['target_seconds' => 300, 'unaccounted_resolved' => true],
                'not_measured' => [['metric' => 'time_to_assembly_seconds', 'reason' => 'No participant checked in during this test.']],
            ],
        ]);
        // `$this->owner` differs from the facilitator, so separation of
        // duties never blocks this finalisation.
        app(\App\Services\Bcms\Exercises\AarService::class)->finalise($aar->fresh(), $this->owner);

        $participant = ExerciseParticipant::query()->create([
            'organization_id' => $this->organization->id,
            'occurrence_id' => $occurrence->getKey(),
            'user_id' => $this->evaluator->id,
            'role' => 'participant',
            'business_unit_id' => $this->unit->id,
            'invitation_status' => 'accepted',
            'attendance_status' => 'unknown',
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(CheckInService::class)->checkIn($participant, 'manual', $this->facilitator);
    }

    /**
     * `$recordedBy` used to be accepted and silently discarded, and
     * `ExerciseParticipant` carried no audit trail of its own at all.
     */
    #[Test]
    public function a_check_in_is_recorded_with_the_actor_and_the_method(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $alice = $this->user('audit-alice@khb.test');
        $this->contactFor($alice, $occurrence);
        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())->where('user_id', $alice->getKey())->sole();

        app(CheckInService::class)->checkIn($participant, 'manual', $this->facilitator);

        $row = AuditLog::query()
            ->where('auditable_type', ExerciseParticipant::class)
            ->where('auditable_id', $participant->getKey())
            ->where('event', 'participant_checked_in')
            ->sole();

        $this->assertSame('manual', $row->after['method'] ?? null);
        $this->assertSame($this->facilitator->name, $row->after['recorded_by'] ?? null);

        // The automatic before/after diff `BcmsAuditable` now writes on
        // every `ExerciseParticipant` update, separately from the explicit
        // event above.
        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ExerciseParticipant::class,
            'auditable_id' => $participant->getKey(),
            'event' => 'updated',
        ]);
    }

    /**
     * Advisory 6 (Gate 2 review #3): `CheckInController::store()` and
     * `codeStore()` used to call `checkIn()` with no third argument, so a
     * logged-in facilitator opening a copied QR link — exactly what the
     * workspace's own `check_in_url` credential is FOR (Gate 2 review #2
     * finding A) — was recorded as `recorded_by: 'self'`, indistinguishable
     * from the participant scanning their own code. Both routes now pass
     * `$request->user()` through.
     */
    #[Test]
    public function a_facilitator_relaying_a_copied_link_is_recorded_as_the_actor_not_self(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $alice = $this->user('relay-alice@khb.test');
        $this->contactFor($alice, $occurrence);
        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
            ->where('user_id', $alice->getKey())->sole();

        $token = app(CheckInService::class)->tokenFor($participant);

        // Still authenticated as the facilitator (as every request in this
        // test method is, from the `actingAs()` above) — this is exactly
        // the "opens a copied link" scenario, not a fresh, anonymous scan.
        $this->post(route('bcms.check-in.store', $token))->assertRedirect();

        $row = AuditLog::query()
            ->where('auditable_type', ExerciseParticipant::class)
            ->where('auditable_id', $participant->getKey())
            ->where('event', 'participant_checked_in')
            ->sole();

        $this->assertSame('qr', $row->after['method'] ?? null);
        $this->assertSame($this->facilitator->name, $row->after['recorded_by'] ?? null);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 defect 6 — re-scoring an objective is audited. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function re_scoring_an_objective_writes_an_audit_row_with_the_old_and_new_score(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $this->actingAs($this->evaluator)->post(route('bcms.occurrences.scores.store', $occurrence), [
            'objective_index' => 0, 'score' => 4, 'commentary' => null,
        ])->assertRedirect();

        $score = ExerciseScore::query()->where('occurrence_id', $occurrence->getKey())->sole();

        $this->actingAs($this->evaluator)->post(route('bcms.occurrences.scores.store', $occurrence), [
            'objective_index' => 0, 'score' => 2, 'commentary' => 'Reconsidered after review.',
        ])->assertRedirect();

        $this->assertSame(2, $score->fresh()->score);

        $row = AuditLog::query()
            ->where('auditable_type', ExerciseScore::class)
            ->where('auditable_id', $score->getKey())
            ->where('event', 'updated')
            ->sole();

        $this->assertSame(4, $row->before['score'] ?? null);
        $this->assertSame(2, $row->after['score'] ?? null);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 defect 3 — evidence owner_id is scoped to this occurrence. */
    /* ------------------------------------------------------------------ */

    /**
     * Before this fix, `owner_id` was validated as nothing more than a
     * positive integer — a facilitator running occurrence A could close a
     * blocking readiness task on occurrence B by uploading evidence against
     * occurrence A naming B's task id, because `ReadinessService::complete()`
     * matched on `owner_type`+`owner_id` alone, with no occurrence filter.
     */
    #[Test]
    public function evidence_naming_a_readiness_task_on_a_different_occurrence_is_refused_and_the_task_stays_blocking(): void
    {
        $definition = $this->definition('FIREDRILL', ['readiness_gating' => true]);
        $occurrenceA = $this->occurrence($definition, 1);
        $occurrenceB = $this->occurrence($definition, 2);

        /** @var ReadinessTask $taskB */
        $taskB = ReadinessTask::query()
            ->where('occurrence_id', $occurrenceB->getKey())
            ->where('is_blocking', true)
            ->get()
            ->first(fn (ReadinessTask $t) => $t->templateTask?->requires_evidence === true);

        $this->assertNotNull($taskB, 'The FIREDRILL template must materialise at least one blocking task that requires evidence for this test to mean anything.');

        $file = UploadedFile::fake()->createWithContent('cross-occurrence.txt', 'forged evidence for a task on another occurrence');

        $this->actingAs($this->facilitator)->post(route('bcms.evidence.store', $occurrenceA), [
            'file' => $file,
            'owner_type' => Evidence::KIND_READINESS_TASK,
            'owner_id' => $taskB->getKey(),
            'kind' => 'file',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('bcms_evidence', 0);

        // The task on occurrence B is still blocking — nothing evidenced it.
        $this->expectException(InvalidArgumentException::class);
        app(ReadinessService::class)->complete($taskB->refresh(), $this->facilitator);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 defect 4 — the unauthenticated check-in routes are rate */
    /*  limited, and the short-code lookup is a single bounded query. */
    /* ------------------------------------------------------------------ */

    /**
     * Gate 2 review #2, blocking finding B: the single ip-keyed
     * `bcms-check-in` limiter this test used to exercise (60/min, shared by
     * BOTH check-in routes) locked out an evacuation drill's worth of people
     * scanning their own, distinct tokens from one office NAT at request 61
     * — the exact scenario the feature exists for. `bcms-check-in-token` is
     * now keyed per TOKEN, not per ip, so this test is rewritten honestly
     * against the surface that genuinely IS an ip-keyed guessing target: the
     * short-code form (`bcms-check-in-code`). The token route's own,
     * separate limiting is covered by the two tests below.
     */
    #[Test]
    public function the_short_code_form_is_rate_limited_per_ip(): void
    {
        cache()->flush();

        $limit = (int) config('bcms.check_in_rate_limit_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->post(route('bcms.check-in.code.store'), ['code' => 'DEADBEEF']);
        }

        $this->post(route('bcms.check-in.code.store'), ['code' => 'DEADBEEF'])->assertStatus(429);
    }

    /**
     * Gate 1 defect, this pass (docs/bcms/screens/qr-checkin.md §3, the
     * "Rate-limited" state): before this fix, a tripped limiter fell
     * through to Laravel's stock "429 | Too Many Requests" page — text/html,
     * no product shell — on a surface the Definition of Done says never
     * dead-ends. No special header is sent here on purpose: this is the
     * ordinary shape of the request a real browser makes (`expectsJson()`
     * false, exactly the discriminator the file's own 419 handler already
     * uses), the same shape every other passing `assertInertia()` test in
     * this file uses for the non-throttled path — Laravel answers it with
     * the full HTML shell either way, which is what `assertInertia()` reads.
     * The `CheckInCode` component now comes back on a 429 too, with the
     * spec's exact sentence and the form's usual `code_form_url`.
     */
    #[Test]
    public function a_throttled_browser_request_to_the_short_code_form_renders_check_in_code_not_the_stock_429_page(): void
    {
        cache()->flush();

        $limit = (int) config('bcms.check_in_rate_limit_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->post(route('bcms.check-in.code.store'), ['code' => 'DEADBEEF']);
        }

        $this->post(route('bcms.check-in.code.store'), ['code' => 'DEADBEEF'])
            ->assertStatus(429)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckInCode')
                ->where('error', 'Too many attempts — wait a moment and try again.')
                ->has('code_form_url'));
    }

    /**
     * BLOCKING DEFECT 1 (Gate 2 review #3): before this fix,
     * `CheckInController::renderThrottled()` still called `resolveToken()`
     * (via `render()`) for the token pair once throttled — the participant
     * lookup and its occurrence/site/definition/user loads (~5 queries)
     * that the ceiling exists to stop still ran — so a valid token came
     * back `ok: true` with the participant's NAME, exercise, site and a
     * working `check_in_url`, and a guessed token came back `ok: false`,
     * distinguishing the two at unlimited speed once over the limit —
     * exactly what `qr-checkin.md` §3(B) says this page must never reveal,
     * and a breach of ADR 0016 §4 ("a ceiling is only a ceiling on the work
     * that happens after it"). This test is REWRITTEN to prove the
     * opposite: the token is never resolved once throttled, a valid token
     * and a made-up token come back with IDENTICAL props (no name,
     * exercise, site or check_in_url on either), and zero queries reach
     * `bcms_exercise_participants` on the throttled request. Proven failing
     * against the pre-fix `renderThrottled()` before the fix landed.
     */
    #[Test]
    public function a_throttled_browser_get_on_a_token_page_renders_check_in_not_the_stock_429_page(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $alice = $this->user('token-page-throttle-alice@khb.test');
        $this->contactFor($alice, $occurrence);
        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
            ->where('user_id', $alice->getKey())->sole();

        $validToken = app(CheckInService::class)->tokenFor($participant);
        $guessedToken = 'chk-999999-'.str_repeat('a', 16);

        $limit = (int) config('bcms.check_in_token_rate_limit_per_minute');
        $errorSentence = 'Too many attempts — wait a moment and try again.';

        // Trip the VALID token's own per-token ceiling.
        cache()->flush();
        for ($i = 0; $i < $limit; $i++) {
            $this->get(route('bcms.check-in.show', $validToken));
        }

        DB::enableQueryLog();
        $throttledValid = $this->get(route('bcms.check-in.show', $validToken));
        $participantQueries = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains($q['query'], 'bcms_exercise_participants'),
        ));
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            [],
            $participantQueries,
            'A throttled request on the token route must run zero queries against bcms_exercise_participants — got: '
            .json_encode(array_column($participantQueries, 'query')),
        );

        $throttledValid->assertStatus(429)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckIn')
                ->where('ok', false)
                ->where('headline', $errorSentence)
                ->where('detail', null)
                ->missing('name')
                ->missing('exercise')
                ->missing('site')
                ->missing('check_in_url'));

        // Trip the GUESSED token's own, separate per-token ceiling.
        cache()->flush();
        for ($i = 0; $i < $limit; $i++) {
            $this->get(route('bcms.check-in.show', $guessedToken));
        }

        $throttledGuessed = $this->get(route('bcms.check-in.show', $guessedToken));

        $throttledGuessed->assertStatus(429)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/CheckIn')
                ->where('ok', false)
                ->where('headline', $errorSentence)
                ->where('detail', null)
                ->missing('name')
                ->missing('exercise')
                ->missing('site')
                ->missing('check_in_url'));

        // Identical shape either way: the props a client can read give no
        // signal at all about whether the token behind them was valid.
        $this->assertSame(
            ['ok' => false, 'headline' => $errorSentence, 'detail' => null],
            array_intersect_key(
                $throttledValid->viewData('page')['props'],
                ['ok' => true, 'headline' => true, 'detail' => true],
            ),
        );
        $this->assertSame(
            ['ok' => false, 'headline' => $errorSentence, 'detail' => null],
            array_intersect_key(
                $throttledGuessed->viewData('page')['props'],
                ['ok' => true, 'headline' => true, 'detail' => true],
            ),
        );
    }

    /**
     * The other half of the same defect: a caller that explicitly wants
     * JSON — `Accept: application/json`, `expectsJson() === true`, the same
     * signal a scripted client (an SMS gateway's health probe, `curl -H
     * Accept:application/json`) sends and a real browser never does — must
     * never receive Laravel's stock HTML stack page either. Plain text,
     * same sentence, never the product shell built for a human.
     */
    #[Test]
    public function a_throttled_json_expecting_request_gets_plain_text_429_not_the_stock_error_page(): void
    {
        cache()->flush();

        $limit = (int) config('bcms.check_in_rate_limit_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->post(route('bcms.check-in.code.store'), ['code' => 'DEADBEEF']);
        }

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->post(route('bcms.check-in.code.store'), ['code' => 'DEADBEEF']);

        $response->assertStatus(429);
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame('Too many attempts — wait a moment and try again.', $response->getContent());
    }

    /**
     * Criterion 3 / Gate 2 review #2 finding B: 200 people behind ONE ip,
     * each checking in with their OWN token, all succeed inside the same
     * minute — because `bcms-check-in-token` is keyed per token, not per ip,
     * and the per-ip ceiling (`bcms.check_in_ip_rate_limit_per_minute`,
     * default 600) is sized well above a drill's headcount.
     */
    #[Test]
    public function two_hundred_participants_behind_one_ip_all_check_in_by_their_own_token_within_a_minute(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        cache()->flush();

        $now = now();
        $rows = [];

        for ($i = 0; $i < 200; $i++) {
            $rows[] = [
                'organization_id' => $this->organization->id,
                'occurrence_id' => $occurrence->getKey(),
                'user_id' => null,
                'contact_id' => null,
                'role' => 'participant',
                'business_unit_id' => $this->unit->id,
                'invitation_status' => 'pending',
                'attendance_status' => 'unknown',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ExerciseParticipant::query()->insert($rows);

        $participants = ExerciseParticipant::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->whereNull('user_id')
            ->get();

        $this->assertCount(200, $participants);

        $checkIn = app(CheckInService::class);

        foreach ($participants as $participant) {
            $this->post(route('bcms.check-in.store', $checkIn->tokenFor($participant)))
                ->assertRedirect();
        }

        $this->assertSame(
            200,
            ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
                ->whereNotNull('checked_in_at')->count(),
        );
    }

    /**
     * Gate 2 review #2 finding B, the other half of the same fix: the
     * per-token limit still bites — the 11th attempt against the SAME token
     * within the minute is refused, regardless of ip.
     */
    #[Test]
    public function the_eleventh_attempt_on_the_same_token_within_a_minute_is_refused(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        cache()->flush();

        $alice = $this->user('token-limit-alice@khb.test');
        $this->contactFor($alice, $occurrence);
        $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
            ->where('user_id', $alice->getKey())->sole();

        $token = app(CheckInService::class)->tokenFor($participant);
        $limit = (int) config('bcms.check_in_token_rate_limit_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->get(route('bcms.check-in.show', $token));
        }

        $this->get(route('bcms.check-in.show', $token))->assertStatus(429);
    }

    /**
     * Advisory 2 (Gate 2 review #3): before this fix, both `config(...)`
     * reads in `AppServiceProvider::registerBcmsCheckInRateLimiters()` were
     * captured into the closures with `use(...)` at BOOT — so lowering
     * `bcms.check_in_ip_rate_limit_per_minute` with `config()->set()` here
     * did nothing, and deleting the per-ip `Limit` from the array entirely
     * would have left the suite green, because nothing exercised it as a
     * ceiling distinct from the per-token one. `config(...)` is now read
     * INSIDE the closure, at call time, so this test can lower the ceiling
     * from the outside and prove it actually bites — across DISTINCT
     * tokens, which the per-token ceiling (a separate bucket per token)
     * would never trip on its own.
     */
    #[Test]
    public function the_per_ip_ceiling_on_the_token_route_trips_across_distinct_tokens_from_one_ip(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        cache()->flush();
        config()->set('bcms.check_in_ip_rate_limit_per_minute', 3);

        $checkIn = app(CheckInService::class);
        $tokens = [];

        foreach (['ip-ceiling-a@khb.test', 'ip-ceiling-b@khb.test', 'ip-ceiling-c@khb.test', 'ip-ceiling-d@khb.test'] as $email) {
            $user = $this->user($email);
            $this->contactFor($user, $occurrence);
            $participant = ExerciseParticipant::query()->where('occurrence_id', $occurrence->getKey())
                ->where('user_id', $user->getKey())->sole();
            $tokens[] = $checkIn->tokenFor($participant);
        }

        // Three DISTINCT tokens, one each — each comfortably under its own
        // per-token ceiling (default 10/min), so only the shared per-ip
        // bucket (lowered to 3 above) can be what trips next.
        foreach (array_slice($tokens, 0, 3) as $token) {
            $this->get(route('bcms.check-in.show', $token))->assertStatus(200);
        }

        // A fourth, still-distinct token from the SAME ip is refused —
        // proving the per-ip ceiling is enforced independently of the
        // per-token one, not merely bookkeeping that nothing exercises.
        $this->get(route('bcms.check-in.show', $tokens[3]))->assertStatus(429);
    }

    #[Test]
    public function short_code_lookup_issues_one_bounded_query_against_participants(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        foreach (['q1@khb.test', 'q2@khb.test', 'q3@khb.test'] as $email) {
            $this->contactFor($this->user($email), $occurrence);
        }

        DB::enableQueryLog();
        app(CheckInService::class)->participantForShortCode('DEADBEEF');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $participantQueries = array_values(array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], 'bcms_exercise_participants'),
        ));

        $this->assertCount(
            1, $participantQueries,
            'The short-code lookup must issue exactly one query against bcms_exercise_participants, not one per candidate.',
        );
        $this->assertStringContainsStringIgnoringCase('limit', (string) $participantQueries[0]['query']);
    }

    /* ------------------------------------------------------------------ */
    /*  Gate 2 defect 7 — the live poll stays within a bounded query count. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function live_metrics_stays_within_a_bounded_query_count_with_fifty_timeline_rows(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        for ($i = 0; $i < 50; $i++) {
            TimelineEntry::query()->create([
                'organization_id' => $this->organization->id,
                'occurrence_id' => $occurrence->getKey(),
                'logged_at' => now(),
                'logged_by' => $this->facilitator->id,
                'entry_type' => 'manual',
                'content' => 'Entry '.$i,
            ]);
        }

        DB::enableQueryLog();
        app(OccurrenceExecutionService::class)->liveMetrics($occurrence->fresh());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            10, count($queries),
            'liveMetrics() must not issue one query per timeline row — got '.count($queries).' queries for 50 rows.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Cross-tenant isolation. */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_execution_route_404s_for_a_different_tenants_occurrence(): void
    {
        $definition = $this->definition('FIREDRILL');
        $occurrence = $this->occurrence($definition, 1);
        $this->actingAs($this->facilitator)->post(route('bcms.occurrences.start', $occurrence));

        $otherOrg = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $otherUser = User::query()->create([
            'name' => 'Outsider', 'email' => 'outsider@ob.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $otherOrg->id, 'is_active' => true,
        ]);
        $this->givePermission($otherUser, 'bcms.exercise.facilitate');
        $this->givePermission($otherUser, 'bcms.exercise.view');

        $this->actingAs($otherUser)->get(route('bcms.occurrences.workspace', $occurrence))->assertNotFound();
        $this->actingAs($otherUser)->post(route('bcms.occurrences.timeline.store', $occurrence), [
            'entry_type' => 'manual', 'content' => 'x',
        ])->assertNotFound();
        $this->actingAs($otherUser)->post(route('bcms.occurrences.complete', $occurrence))->assertNotFound();
    }

    /**
     * QA gate coverage — gap 2's four new routes specifically. Starting an
     * occurrence early, and authoring/editing/deleting/reordering its
     * injects, must all 404 for a different tenant's occurrence and inject —
     * store/reorder through the (unscoped-by-name) `{occurrence}` binding,
     * update/destroy through the nested, `scopeBindings()`-scoped
     * `{occurrence}/injects/{inject}` pair.
     */
    #[Test]
    public function starting_early_and_every_inject_route_404s_for_a_different_tenants_occurrence(): void
    {
        $definition = $this->definition('FIREDRILL', ['readiness_gating' => false]);
        $occurrence = $this->occurrence($definition, 1);
        $occurrence->update(['scheduled_date' => now()->addMonths(6)->toDateString()]);

        $inject = ExerciseInject::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'sequence' => 1, 'title' => 'Tenant A inject',
        ]);

        $otherOrg = Organization::create([
            'name' => 'Other Bank Two', 'short_name' => 'OB2',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        $otherUser = User::query()->create([
            'name' => 'Outsider Two', 'email' => 'outsider2@ob.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $otherOrg->id, 'is_active' => true,
        ]);
        $this->givePermission($otherUser, 'bcms.exercise.facilitate');

        $this->actingAs($otherUser)
            ->post(route('bcms.occurrences.start', $occurrence), ['confirmed_early_start' => 1])
            ->assertNotFound();
        $this->assertNull($occurrence->refresh()->actual_start);

        $this->actingAs($otherUser)
            ->post(route('bcms.occurrences.injects.store', $occurrence), ['title' => 'Planted from outside'])
            ->assertNotFound();
        $this->assertDatabaseMissing('bcms_exercise_injects', ['title' => 'Planted from outside']);

        $this->actingAs($otherUser)
            ->patch(route('bcms.occurrences.injects.update', [$occurrence, $inject]), ['title' => 'Overwritten from outside'])
            ->assertNotFound();
        $this->assertSame('Tenant A inject', $inject->refresh()->title);

        $this->actingAs($otherUser)
            ->delete(route('bcms.occurrences.injects.destroy', [$occurrence, $inject]))
            ->assertNotFound();
        $this->assertDatabaseHas('bcms_exercise_injects', ['id' => $inject->getKey()]);

        $this->actingAs($otherUser)
            ->post(route('bcms.occurrences.injects.reorder', $occurrence), ['inject_ids' => [$inject->getKey()]])
            ->assertNotFound();
        $this->assertSame(1, $inject->refresh()->sequence);
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
        $role = \Spatie\Permission\Models\Role::findOrCreate('bcms9-'.md5($permission.$user->email), 'web');
        $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        $user->assignRole($role);
    }

    private function contactFor(User $user, ExerciseOccurrence $occurrence): Contact
    {
        $contact = Contact::query()->firstOrCreate(
            ['organization_id' => $this->organization->id, 'employee_id' => 'E-'.$user->id],
            [
                'user_id' => $user->id, 'full_name' => $user->name, 'email' => $user->email,
                'mobile_primary' => '+23480000'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
                'business_unit_id' => $this->unit->id, 'source' => 'manual', 'is_active' => true,
            ]
        );

        ExerciseParticipant::query()->updateOrCreate(
            ['occurrence_id' => $occurrence->getKey(), 'user_id' => $user->id],
            [
                'organization_id' => $this->organization->id, 'contact_id' => $contact->getKey(),
                'role' => 'participant', 'business_unit_id' => $this->unit->id, 'invitation_status' => 'pending',
            ]
        );

        return $contact;
    }

    /** @param array<string, mixed> $attributes */
    private function definition(string $typeCode, array $attributes = []): \App\Models\Bcms\ExerciseDefinition
    {
        $type = ExerciseType::query()->where('code', $typeCode)->sole();

        return app(ExerciseDefinitionService::class)->create(
            $this->programme,
            $type,
            $typeCode.' '.Str::random(4),
            array_merge([
                'business_unit_id' => $this->unit->id,
                'owner_id' => $this->owner->id,
                'facilitator_id' => $this->facilitator->id,
                'status' => 'active',
            ], $attributes),
            $this->owner->id,
        );
    }

    private function occurrence(\App\Models\Bcms\ExerciseDefinition $definition, int $sequence): ExerciseOccurrence
    {
        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => $sequence,
            // Today, not a future offset: every test in this file starts the
            // occurrence via `bcms.occurrences.start` immediately after
            // creating it, and `start()` now refuses an early start (gap 1)
            // without `confirmed_early_start`.
            'scheduled_date' => now()->toDateString(),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $this->facilitator->id,
        ]);

        app(ReadinessService::class)->materialise($occurrence);

        return $occurrence->refresh();
    }
}
