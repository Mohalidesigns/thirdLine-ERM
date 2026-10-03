<?php

namespace Tests\Feature\Bcms;

use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\TrainingCurriculum;
use App\Models\Bcms\TrainingRecord;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Training\TrainingComplianceService;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * BCMS Phase 11 — training & competency, phase-11-spec §2.1, §6 criterion 6.
 *
 * COMPETENCY AND ATTENDANCE ARE NEVER BLENDED. Every test that touches both
 * asserts them as two separate facts, never one derived from the other.
 */
class Phase11TrainingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

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
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    #[Test]
    public function the_six_role_based_curricula_are_seeded_with_the_right_shape(): void
    {
        $curricula = TrainingCurriculum::query()->where('is_system_default', true)->get()->keyBy('code');

        $this->assertCount(6, $curricula);

        $emns = $curricula->get('BC-EMNS');
        $this->assertSame(6, $emns->frequency_months, 'The EMNS operator curriculum recertifies at six months.');
        $this->assertTrue($emns->requires_assessment);

        $aware = $curricula->get('BC-AWARE-ALL');
        $this->assertFalse($aware->requires_assessment, 'Only BC-AWARE-ALL is unassessed.');
    }

    /**
     * ADR 0021 §3 / phase-11-notes.md gap 1 — a tenant that lived through the
     * Phase 0 placeholder pack (`BC-AWARE`, `BC-CHAMPION`, `BC-FACILITATOR`,
     * `BC-CRISIS`) must end up with exactly six ACTIVE curricula once the
     * reference seeder runs the six-curriculum pack, not eight — and no
     * training record already keyed to a superseded code is left dangling.
     */
    #[Test]
    public function the_reference_seeder_retires_the_phase_0_placeholder_codes_it_superseded(): void
    {
        // Simulate a tenant on the Phase 0 placeholder pack: seed the four
        // old-shape rows the way the original seeder did — as SYSTEM rows,
        // organization_id null (`TenantContext::clear()`, exactly as
        // `BcmsReferenceSeeder::run()` does before writing its own system
        // half), before this phase's content pack existed.
        TenantContext::clear();

        $placeholderAware = TrainingCurriculum::query()->create([
            'organization_id' => null, 'code' => 'BC-AWARE',
            'name' => 'Business continuity awareness (all staff)',
            'target_roles' => ['*'], 'modules' => [],
            'frequency_months' => 12, 'is_mandatory' => true, 'requires_assessment' => false,
            'is_system_default' => true, 'is_active' => true,
        ]);

        $placeholderFacilitator = TrainingCurriculum::query()->create([
            'organization_id' => null, 'code' => 'BC-FACILITATOR',
            'name' => 'Exercise facilitator',
            'target_roles' => ['risk-manager'], 'modules' => [],
            'frequency_months' => 24, 'is_mandatory' => false, 'requires_assessment' => true, 'pass_mark' => 70,
            'is_system_default' => true, 'is_active' => true,
        ]);

        TenantContext::set($this->organization->id);

        // A historical training record against the code that is about to be
        // superseded — this must not become an orphan.
        $holder = $this->user('Facilitator', 'facilitator@khb.test', []);
        $historicalRecord = TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $placeholderFacilitator->getKey(),
            'user_id' => $holder->getKey(),
            'completed_at' => now()->subYear(),
            'competency_assessed' => true,
            'assessor_id' => null,
            'score' => 72,
        ]);

        // Re-run the reference seeder — the six-curriculum pack, then the
        // retirement pass, both already ran once in setUp() and must be
        // idempotent on a second run too.
        $this->seed(\Database\Seeders\Bcms\BcmsReferenceSeeder::class);

        $active = TrainingCurriculum::query()->where('is_active', true)->get()->keyBy('code');
        $this->assertCount(6, $active, 'Exactly six curricula are active once the superseded codes are retired.');
        $this->assertNull($active->get('BC-AWARE'), 'BC-AWARE was superseded by BC-AWARE-ALL.');
        $this->assertNull($active->get('BC-FACILITATOR'), 'BC-FACILITATOR has no successor and is retired outright.');
        $this->assertNotNull($active->get('BC-AWARE-ALL'));
        $this->assertNotNull($active->get('BC-WARDEN'));

        // The superseded rows still exist — retired, not deleted — so the
        // historical record's foreign key is never orphaned.
        $this->assertFalse($placeholderAware->refresh()->is_active);
        $this->assertFalse($placeholderFacilitator->refresh()->is_active);

        $historicalRecord->refresh();
        $this->assertNotNull($historicalRecord->curriculum, 'The historical record still resolves to a real curriculum row.');
        $this->assertSame($placeholderFacilitator->getKey(), $historicalRecord->curriculum_id);

        // Running the seeder again does not flip anything back on or create
        // a second copy of either superseded row.
        $this->seed(\Database\Seeders\Bcms\BcmsReferenceSeeder::class);
        $this->assertSame(1, TrainingCurriculum::query()->where('code', 'BC-AWARE')->count());
        $this->assertSame(1, TrainingCurriculum::query()->where('code', 'BC-FACILITATOR')->count());
        $this->assertFalse(TrainingCurriculum::query()->where('code', 'BC-AWARE')->value('is_active'));
        $this->assertCount(6, TrainingCurriculum::query()->where('is_active', true)->get());
    }

    #[Test]
    public function recording_an_outcome_where_the_actor_is_the_subject_is_refused(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $person = $this->user('Warden', 'warden@khb.test', []);

        $this->expectException(InvalidArgumentException::class);

        // B4: the assessor is the acting user, never a free field. A manage
        // holder recording their own score is self-assessment regardless of
        // any assessor_id they might submit.
        app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $person->getKey(),
            'completed_at' => now(),
            'score' => 90,
        ], $person->getKey());
    }

    #[Test]
    public function b4_a_submitted_assessor_id_naming_a_colleague_is_ignored_the_assessor_is_always_the_acting_user(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $actor = $this->user('Manager', 'manager@khb.test', []);
        $colleague = $this->user('Colleague', 'colleague@khb.test', []);
        $warden = $this->user('Warden', 'warden@khb.test', []);

        // A manage holder ($actor) submits a form naming $colleague as the
        // assessor. The service must ignore that free field and record
        // $actor (the one actually submitting the outcome) as the assessor.
        $record = app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $warden->getKey(),
            'assessor_id' => $colleague->getKey(),
            'completed_at' => now(),
            'score' => 85,
        ], $actor->getKey());

        $this->assertSame($actor->getKey(), $record->assessor_id, 'The assessor is the acting user, never a free request field.');
    }

    #[Test]
    public function an_assessed_record_is_green_while_attendance_only_is_amber(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $assessor = $this->user('Assessor', 'assessor@khb.test', []);
        $warden = $this->user('Warden', 'warden@khb.test', []);

        $service = app(TrainingComplianceService::class);

        $record = $service->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $warden->getKey(),
            'completed_at' => now(),
            'score' => 85,
        ], $assessor->getKey());

        $this->assertTrue($record->competency_assessed);
        $this->assertSame(85.0, (float) $record->score);
        $this->assertSame($assessor->getKey(), $record->assessor_id);

        // A second person: attendance recorded, no score given.
        $other = $this->user('Attendee', 'attendee@khb.test', []);
        $attendanceOnly = $service->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $other->getKey(),
            'completed_at' => now(),
        ], $assessor->getKey());

        $this->assertFalse($attendanceOnly->competency_assessed, 'Attendance alone must never be recorded as competence.');
        $this->assertNull($attendanceOnly->assessor_id, 'No assessment happened, so no assessor is recorded.');
    }

    #[Test]
    public function assessing_an_existing_attendance_record_never_lets_the_attendee_assess_themselves(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-CHAMPION')->firstOrFail();
        $person = $this->user('Champion', 'champion@khb.test', []);

        $record = TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $person->getKey(),
            'completed_at' => now(),
            'competency_assessed' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(TrainingComplianceService::class)->assess($record, 80.0, $person->getKey());
    }

    #[Test]
    public function b4_assess_refuses_to_re_assess_a_record_that_already_carries_a_score(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-CHAMPION')->firstOrFail();
        $firstAssessor = $this->user('First Assessor', 'first@khb.test', []);
        $secondAssessor = $this->user('Second Assessor', 'second@khb.test', []);
        $person = $this->user('Champion', 'champion@khb.test', []);

        $record = app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $person->getKey(),
            'completed_at' => now(),
            'score' => 40, // failed, but still assessed
        ], $firstAssessor->getKey());

        $this->expectException(InvalidArgumentException::class);
        app(TrainingComplianceService::class)->assess($record, 90.0, $secondAssessor->getKey());
    }

    #[Test]
    public function a_failed_assessment_is_still_a_valid_record_with_an_actor_a_date_and_a_result(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-CHAMPION')->firstOrFail();
        $assessor = $this->user('Assessor', 'assessor@khb.test', []);
        $person = $this->user('Champion', 'champion@khb.test', []);

        $record = app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $person->getKey(),
            'completed_at' => now(),
            'score' => 40, // below the 75 pass mark
        ], $assessor->getKey());

        // B3: a fail is assessed-and-failed, never recorded as competence
        // achieved. It is still a first-class record — an actor, a date and
        // a result — distinguishable from "not yet assessed" by its
        // non-null score and assessor_id, not by competency_assessed.
        $this->assertFalse($record->competency_assessed, 'A fail must never be recorded as competence achieved (ADR 0021 §3).');
        $this->assertSame(40.0, (float) $record->score);
        $this->assertSame($assessor->getKey(), $record->assessor_id, 'A failed assessment still names its assessor — that is what makes it assessed-and-failed rather than not-yet-assessed.');
        $this->assertLessThan($curriculum->pass_mark, $record->score);
    }

    #[Test]
    public function b3_a_pass_at_exactly_the_pass_mark_is_recorded_as_competent(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-CHAMPION')->firstOrFail();
        $assessor = $this->user('Assessor', 'assessor@khb.test', []);
        $person = $this->user('Champion', 'champion@khb.test', []);

        $record = app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $person->getKey(),
            'completed_at' => now(),
            'score' => $curriculum->pass_mark,
        ], $assessor->getKey());

        $this->assertTrue($record->competency_assessed, 'A score exactly at the pass mark passes.');
    }

    /**
     * R5 (code review #2): `complianceRows()` picked the "assessed" record
     * via a bare `->first()` over a collection built from a query with NO
     * `ORDER BY` — B4 made a re-sit (fail, then a later pass) the normal
     * path, so which record won was whatever order the database happened
     * to return, not the most recent one. Fixed to `completed_at DESC, id
     * DESC` — the fixture below pins the SAME instant on both records
     * specifically so the fix cannot pass by accident on `completed_at`
     * alone; only the `id` tiebreaker distinguishes them.
     */
    #[Test]
    public function a_fail_then_a_pass_on_the_same_day_shows_passed_not_failed(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-CHAMPION')->firstOrFail();
        $assessor = $this->user('Assessor', 'r5-assessor@khb.test', []);
        $person = $this->user('Champion', 'r5-champion@khb.test', ['department-champion']);

        $sameInstant = now();

        $fail = app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $person->getKey(),
            'completed_at' => $sameInstant, 'score' => 40,
        ], $assessor->getKey());

        $pass = app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $person->getKey(),
            'completed_at' => $sameInstant, 'score' => 90,
        ], $assessor->getKey());

        $this->assertGreaterThan($fail->getKey(), $pass->getKey(), 'Sanity check: the pass is the later row, same instant.');

        $rows = app(TrainingComplianceService::class)->complianceRows(viewer: $assessor);
        $row = collect($rows)->first(fn ($r) => $r['curriculum_id'] === $curriculum->getKey() && $r['user_id'] === $person->getKey());

        $this->assertNotNull($row);
        $this->assertTrue($row['competency']['passed'], 'The later (higher-id) record on the same instant must win, not whichever the database returns first.');
        $this->assertSame(90.0, $row['competency']['score']);
    }

    #[Test]
    public function b5_next_due_date_is_computed_from_completed_at_not_today(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();
        $this->assertSame(12, $curriculum->frequency_months);
        $person = $this->user('Attendee', 'attendee2@khb.test', []);

        $completedAt = now()->subMonths(11);

        $record = app(TrainingComplianceService::class)->recordOutcome([
            'curriculum_id' => $curriculum->getKey(),
            'user_id' => $person->getKey(),
            'completed_at' => $completedAt,
        ]);

        // Completed 11 months ago on a 12-month cycle is due in 1 month,
        // not 12 months from today.
        $this->assertSame(
            $completedAt->copy()->addMonths(12)->toDateString(),
            $record->next_due_date->toDateString(),
        );
        $this->assertTrue(
            $record->next_due_date->lessThan(now()->addMonths(2)),
            'The due date must be computed from completed_at, not from today.',
        );
    }

    /**
     * A10: a retired curriculum accepts no new record, and a future
     * `completed_at` is refused — a training outcome describes something
     * that already happened.
     */
    #[Test]
    public function a10_the_store_record_request_refuses_a_retired_curriculum_and_a_future_completion_date(): void
    {
        $manager = $this->user('Manager', 'a10-manager@khb.test', []);
        $manager->givePermissionTo(Permission::findOrCreate('bcms.training.manage', 'web'));
        // Whole-estate (R2 does not interfere with what THIS test targets —
        // the curriculum/date validation, not the subject's own unit scope).
        $manager->givePermissionTo(Permission::findOrCreate('rcsa_scope.all_units', 'web'));
        $active = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();
        $retired = TrainingCurriculum::query()->create([
            'organization_id' => $this->organization->id, 'code' => 'A10-RETIRED', 'name' => 'Retired curriculum',
            'target_roles' => ['*'], 'modules' => [], 'frequency_months' => 12,
            'is_mandatory' => false, 'requires_assessment' => false,
            'is_system_default' => false, 'is_active' => false,
        ]);
        $subject = $this->user('Subject', 'a10-subject@khb.test', []);

        $this->actingAs($manager)->post(route('bcms.training-records.store'), [
            'curriculum_id' => $retired->getKey(), 'user_id' => $subject->getKey(),
            'completed_at' => now()->toDateString(),
        ])->assertSessionHasErrors('curriculum_id');

        $this->actingAs($manager)->post(route('bcms.training-records.store'), [
            'curriculum_id' => $active->getKey(), 'user_id' => $subject->getKey(),
            'completed_at' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors('completed_at');

        $this->actingAs($manager)->post(route('bcms.training-records.store'), [
            'curriculum_id' => $active->getKey(), 'user_id' => $subject->getKey(),
            'completed_at' => now()->toDateString(),
        ])->assertSessionDoesntHaveErrors();
    }

    /**
     * A10: `assess()` refuses a curriculum that does not require assessment
     * — a curriculum that only ever claimed attendance (7.3) has no pass
     * mark to score against.
     */
    #[Test]
    public function a10_assess_refuses_a_curriculum_that_does_not_require_assessment(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();
        $this->assertFalse($curriculum->requires_assessment);

        $subject = $this->user('Subject', 'a10-assess-subject@khb.test', []);
        $assessor = $this->user('Assessor', 'a10-assess-assessor@khb.test', []);

        $record = TrainingRecord::query()->create([
            'organization_id' => $this->organization->id, 'curriculum_id' => $curriculum->getKey(),
            'user_id' => $subject->getKey(), 'completed_at' => now(), 'competency_assessed' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(TrainingComplianceService::class)->assess($record, 90.0, $assessor->getKey());
    }

    /**
     * R2 (code review #2): the register's unit scope (B11) did not govern
     * its WRITE endpoints — a unit-scoped `bcms.training.manage` holder
     * (e.g. `risk-manager`, who lacks `rcsa_scope.all_units`) could record
     * or assess ANYONE in the bank, and the read side's assessor exception
     * would then keep that person in their register forever, which is
     * exactly what NDPA register §11.4 rule 3 rules out ("Nobody else is
     * added"). Both endpoints now refuse a subject outside
     * `RcsaScope::unitIdsFor($actor)`.
     */
    #[Test]
    public function r2_a_unit_scoped_manager_cannot_record_an_outcome_for_a_person_outside_their_units(): void
    {
        $kano = \App\Models\BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-K-'.Str::random(6), 'name' => 'Kano', 'is_active' => true,
        ]);
        $lagos = \App\Models\BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-L-'.Str::random(6), 'name' => 'Lagos', 'is_active' => true,
        ]);

        $kanoManager = $this->user('Kano Manager', 'r2-kano-manager@khb.test', []);
        $kanoManager->givePermissionTo(Permission::findOrCreate('bcms.training.manage', 'web'));
        $kanoManager->givePermissionTo(Permission::findOrCreate('bcms.training.view', 'web'));
        \Illuminate\Support\Facades\DB::table('business_unit_user')->insert([
            'organization_id' => $this->organization->id, 'user_id' => $kanoManager->getKey(),
            'business_unit_id' => $kano->getKey(), 'includes_descendants' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lagosPerson = User::create([
            'name' => 'Lagos Person', 'email' => 'r2-lagos-person@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
            'business_unit_id' => $lagos->getKey(),
        ]);
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();

        // Recording is refused.
        $this->actingAs($kanoManager)->post(route('bcms.training-records.store'), [
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $lagosPerson->getKey(),
            'completed_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertDatabaseMissing('bcms_training_records', ['user_id' => $lagosPerson->getKey()]);

        // The Lagos person does not appear in the Kano manager's own register.
        $rows = app(TrainingComplianceService::class)->complianceRows(viewer: $kanoManager);
        $this->assertNotContains($lagosPerson->getKey(), collect($rows)->pluck('user_id')->all());

        // A whole-estate manager can still record for anyone.
        $examiner = $this->user('Examiner', 'r2-examiner@khb.test', []);
        $examiner->givePermissionTo(Permission::findOrCreate('bcms.training.manage', 'web'));
        $examiner->givePermissionTo(Permission::findOrCreate('rcsa_scope.all_units', 'web'));

        $this->actingAs($examiner)->post(route('bcms.training-records.store'), [
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $lagosPerson->getKey(),
            'completed_at' => now()->toDateString(),
        ])->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('bcms_training_records', ['user_id' => $lagosPerson->getKey()]);
    }

    #[Test]
    public function r2_a_unit_scoped_manager_cannot_assess_a_record_belonging_to_a_person_outside_their_units(): void
    {
        $kano = \App\Models\BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-K-'.Str::random(6), 'name' => 'Kano', 'is_active' => true,
        ]);
        $lagos = \App\Models\BusinessUnit::create([
            'organization_id' => $this->organization->id, 'code' => 'BU-L-'.Str::random(6), 'name' => 'Lagos', 'is_active' => true,
        ]);

        $kanoManager = $this->user('Kano Manager', 'r2-assess-kano-manager@khb.test', []);
        $kanoManager->givePermissionTo(Permission::findOrCreate('bcms.training.manage', 'web'));
        $kanoManager->givePermissionTo(Permission::findOrCreate('bcms.training.view', 'web'));
        \Illuminate\Support\Facades\DB::table('business_unit_user')->insert([
            'organization_id' => $this->organization->id, 'user_id' => $kanoManager->getKey(),
            'business_unit_id' => $kano->getKey(), 'includes_descendants' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lagosPerson = User::create([
            'name' => 'Lagos Person Two', 'email' => 'r2-lagos-person-2@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
            'business_unit_id' => $lagos->getKey(),
        ]);
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-CHAMPION')->firstOrFail();
        $lagosPerson->assignRole(Role::findOrCreate('department-champion', 'web'));
        $record = TrainingRecord::query()->create([
            'organization_id' => $this->organization->id, 'curriculum_id' => $curriculum->getKey(),
            'user_id' => $lagosPerson->getKey(), 'completed_at' => now(), 'competency_assessed' => false,
        ]);

        $this->actingAs($kanoManager)->post(route('bcms.training-records.assess', $record), [
            'score' => 90,
        ])->assertForbidden();

        $record->refresh();
        $this->assertNull($record->score);
        $this->assertFalse((bool) $record->competency_assessed);
    }

    /**
     * B10 follow-up (coordinator, post-review): `attendedNotAssessedByCurriculum()`
     * must reflect the WHOLE viewer-scoped register, not whichever page
     * happens to be on screen — `Compliance.jsx`'s own client-side version
     * computed the ratio over `rows`, which B10 made one page. Thirty
     * synthetic rows for one mandatory curriculum — more than one page
     * (25/page) — are handed in at once: the first 25 alone would read 40%
     * attended-not-assessed (not flagged), but the full 30 read 56.7%
     * (flagged). The method takes the whole array, so it always reads the
     * true, full-register ratio regardless of how the caller later slices
     * it for pagination.
     */
    #[Test]
    public function the_heavy_amber_flag_reflects_the_whole_scoped_register_not_one_page(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $this->assertTrue((bool) $curriculum->is_mandatory);

        $rows = [];
        // First 25 (a page's worth): 12 attended-not-assessed, 13 assessed.
        // 12/25 = 48% — a page-scoped computation would NOT flag this.
        for ($i = 0; $i < 25; $i++) {
            $rows[] = [
                'curriculum_id' => $curriculum->getKey(),
                'competency' => ['assessed' => $i >= 12],
                'attendance' => ['completed_at' => '2026-01-01'],
            ];
        }
        // Five more (page 2): all attended-not-assessed.
        // Full set: (12 + 5) / 30 = 56.7% — the register-wide truth.
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'curriculum_id' => $curriculum->getKey(),
                'competency' => ['assessed' => false],
                'attendance' => ['completed_at' => '2026-01-01'],
            ];
        }

        $pageOnly = app(TrainingComplianceService::class)->attendedNotAssessedByCurriculum(array_slice($rows, 0, 25));
        $this->assertFalse($pageOnly[$curriculum->getKey()]['heavy_amber'], 'Sanity check: the first 25 rows alone are under the 50% threshold.');

        $whole = app(TrainingComplianceService::class)->attendedNotAssessedByCurriculum($rows);
        $this->assertSame(17, $whole[$curriculum->getKey()]['attended_not_assessed_count']);
        $this->assertSame(13, $whole[$curriculum->getKey()]['assessed_count']);
        $this->assertSame(30, $whole[$curriculum->getKey()]['total']);
        $this->assertTrue($whole[$curriculum->getKey()]['heavy_amber'], 'The full 30-row register reads 56.7% and must be flagged, even though the first page alone would not be.');
    }

    /**
     * The same proof at the HTTP layer: the flag the screen actually reads
     * is identical whichever page happens to be requested, because it is
     * computed once over the full scoped set before any slicing happens.
     */
    #[Test]
    public function the_heavy_amber_flag_is_identical_across_pages_of_the_same_register(): void
    {
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $manager = $this->user('Manager', 'heavy-amber-manager@khb.test', []);
        $manager->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('bcms.training.view', 'web'));
        // Whole-estate scope (B11) — otherwise a unit-scoped viewer would
        // not see these no-unit wardens at all (rule 2's inverted null
        // arm), and this test would be proving nothing.
        $manager->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('rcsa_scope.all_units', 'web'));
        $assessor = $this->user('Assessor', 'heavy-amber-assessor@khb.test', []);
        $warden = Role::findOrCreate('floor-warden', 'web');
        $service = app(TrainingComplianceService::class);

        for ($i = 0; $i < 30; $i++) {
            $subject = $this->user("Warden {$i}", "heavy-amber-warden-{$i}@khb.test", []);
            $subject->assignRole($warden);

            if ($i < 17) {
                // Attended, not assessed — no score submitted.
                $service->recordOutcome([
                    'curriculum_id' => $curriculum->getKey(), 'user_id' => $subject->getKey(),
                    'completed_at' => now(),
                ]);
            } else {
                $service->recordOutcome([
                    'curriculum_id' => $curriculum->getKey(), 'user_id' => $subject->getKey(),
                    'completed_at' => now(), 'score' => 90,
                ], $assessor->getKey());
            }
        }

        $page1 = $this->actingAs($manager)->get(route('bcms.training.compliance', ['page' => 1]))
            ->viewData('page')['props']['curricula'];
        $page2 = $this->actingAs($manager)->get(route('bcms.training.compliance', ['page' => 2]))
            ->viewData('page')['props']['curricula'];

        $onPage1 = collect($page1)->firstWhere('id', $curriculum->getKey());
        $onPage2 = collect($page2)->firstWhere('id', $curriculum->getKey());

        $this->assertSame($onPage1, $onPage2, 'The per-curriculum figures must not depend on which page was requested.');
        $this->assertTrue($onPage1['heavy_amber']);
        $this->assertSame(17, $onPage1['attended_not_assessed_count']);
        $this->assertSame(13, $onPage1['assessed_count']);
    }

    /* ================================================================== */
    /*  Criterion 6 — the occurrence links to the training record automatically */
    /* ================================================================== */

    #[Test]
    public function a_present_participant_gets_their_occurrence_linked_without_setting_competency(): void
    {
        $warden = $this->user('Warden', 'warden@khb.test', ['floor-warden']);

        $occurrence = ExerciseOccurrence::factory()->create([
            'status' => 'completed', 'scheduled_date' => now()->subDay()->toDateString(),
            'actual_end' => now()->subDay(),
        ]);

        ExerciseParticipant::factory()->create([
            'occurrence_id' => $occurrence->getKey(),
            'user_id' => $warden->getKey(),
            'attendance_status' => 'present',
        ]);

        $linked = app(TrainingComplianceService::class)->linkOccurrenceParticipants($occurrence);

        $this->assertSame(1, $linked);

        $record = TrainingRecord::query()->where('user_id', $warden->getKey())->where('occurrence_id', $occurrence->getKey())->firstOrFail();

        $this->assertFalse($record->competency_assessed, 'The link creates the record; it does not set competency_assessed.');
        $this->assertNull($record->assessor_id);

        // Idempotent: linking the same occurrence again creates nothing new.
        $again = app(TrainingComplianceService::class)->linkOccurrenceParticipants($occurrence);
        $this->assertSame(0, $again);
        $this->assertSame(1, TrainingRecord::query()->where('occurrence_id', $occurrence->getKey())->count());
    }

    #[Test]
    public function a_participant_with_no_matching_curriculum_role_is_not_linked(): void
    {
        $bystander = $this->user('Bystander', 'bystander@khb.test', []);

        $occurrence = ExerciseOccurrence::factory()->create(['status' => 'completed']);

        ExerciseParticipant::factory()->create([
            'occurrence_id' => $occurrence->getKey(),
            'user_id' => $bystander->getKey(),
            'attendance_status' => 'present',
        ]);

        $linked = app(TrainingComplianceService::class)->linkOccurrenceParticipants($occurrence);

        $this->assertSame(0, $linked);
    }

    /* ================================================================== */
    /*  Code review #3, A4 — StoreBcmsTrainingRecordRequest/ */
    /*  AssessBcmsTrainingRecordRequest::authorize(), a bad user_id */
    /* ================================================================== */

    #[Test]
    public function a4_an_array_user_id_on_store_is_refused_with_a_422_not_a_500(): void
    {
        $manager = $this->user('A4 Manager', 'a4-manager@khb.test', []);
        $manager->givePermissionTo(Permission::findOrCreate('bcms.training.manage', 'web'));
        $manager->givePermissionTo(Permission::findOrCreate('rcsa_scope.all_units', 'web'));
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();

        $response = $this->actingAs($manager)->post(route('bcms.training-records.store'), [
            'curriculum_id' => $curriculum->getKey(), 'user_id' => [1, 2],
            'completed_at' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('user_id');
        $this->assertNotSame(500, $response->getStatusCode(), 'An array user_id must never reach find() and 500 — it must 422 through validation.');
        $this->assertDatabaseCount('bcms_training_records', 0);
    }

    #[Test]
    public function a4_a_soft_deleted_subject_on_store_fails_closed_not_open(): void
    {
        $manager = $this->user('A4 Manager Two', 'a4-manager-two@khb.test', []);
        $manager->givePermissionTo(Permission::findOrCreate('bcms.training.manage', 'web'));
        $manager->givePermissionTo(Permission::findOrCreate('rcsa_scope.all_units', 'web'));
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-AWARE-ALL')->firstOrFail();

        $subject = $this->user('A4 Deleted Subject', 'a4-deleted-subject@khb.test', []);
        $subject->delete(); // soft delete — the row exists, User::find() will not.

        $response = $this->actingAs($manager)->post(route('bcms.training-records.store'), [
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $subject->getKey(),
            'completed_at' => now()->toDateString(),
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('bcms_training_records', 0);
    }

    #[Test]
    public function a4_a_soft_deleted_subject_on_assess_fails_closed_not_open(): void
    {
        $manager = $this->user('A4 Manager Three', 'a4-manager-three@khb.test', []);
        $manager->givePermissionTo(Permission::findOrCreate('bcms.training.manage', 'web'));
        $manager->givePermissionTo(Permission::findOrCreate('rcsa_scope.all_units', 'web'));

        $subject = $this->user('A4 Deleted Subject Two', 'a4-deleted-subject-two@khb.test', []);
        $curriculum = TrainingCurriculum::query()->where('code', 'BC-WARDEN')->firstOrFail();
        $record = TrainingRecord::query()->create([
            'organization_id' => $this->organization->id,
            'curriculum_id' => $curriculum->getKey(), 'user_id' => $subject->getKey(), 'completed_at' => now(),
        ]);
        $subject->delete();

        $response = $this->actingAs($manager)->post(route('bcms.training-records.assess', $record), [
            'score' => 90,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('bcms_training_records', ['id' => $record->getKey(), 'competency_assessed' => false]);
    }

    /* ================================================================== */

    /** @param  list<string>  $roles */
    private function user(string $name, string $email, array $roles): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'is_active' => true,
        ]);

        foreach ($roles as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $user->assignRole($role);
        }

        return $user;
    }
}
