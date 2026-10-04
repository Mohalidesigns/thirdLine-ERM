<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\AuditLog;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\ReadinessTask;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use Database\Seeders\Bcms\BcmsDemoSeeder;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * QA gate coverage for demo fix 4 (readiness task completion audit) beyond
 * what `Phase5ScreensTest` already pins: the facilitator path (not just the
 * `my.view`-owner path), a refused completion writing nothing, the
 * `materialise()` audit surface it inherits for free now `ReadinessTask`
 * carries `BcmsAuditable`, and the `markOverdue()` sweep's own, deliberately
 * unaudited, shape.
 */
class ReadinessTaskAuditGateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private ExerciseProgramme $programme;

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

        $this->programme = app(ExerciseProgrammeService::class)->create((int) now()->year, 'Programme');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /**
     * `Phase5ScreensTest::completing_a_readiness_task_writes_an_audit_row`
     * only exercises the `my.view`-owner path. A facilitator closing
     * somebody else's task is the other, more common, path through the
     * exact same controller action and must carry the FACILITATOR as the
     * actor, not the task's owner.
     */
    #[Test]
    public function a_facilitator_completing_a_colleagues_task_is_recorded_as_the_actor(): void
    {
        $occurrence = $this->occurrence();
        $owner = $this->userWith([], 'owner@khb.test');
        $facilitator = $this->userWith(['bcms.exercise.facilitate'], 'facilitator@khb.test');

        $task = ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => "Confirm owner's attendance", 'owner_id' => $owner->id,
            'is_blocking' => false, 'status' => 'open',
        ]);

        $this->actingAs($facilitator)
            ->post(route('bcms.readiness-tasks.complete', $task))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ReadinessTask::class,
            'auditable_id' => $task->getKey(),
            'event' => 'readiness_task_completed',
            'actor_id' => $facilitator->getKey(),
            'organization_id' => $this->organization->id,
        ]);

        $this->assertDatabaseMissing('bcms_audit_logs', [
            'auditable_type' => ReadinessTask::class,
            'auditable_id' => $task->getKey(),
            'event' => 'readiness_task_completed',
            'actor_id' => $owner->getKey(),
        ]);
    }

    /**
     * A refused completion — `my.view`, but not this task's owner — must not
     * write ANY audit row. The request never reaches `ReadinessService::
     * complete()`; this pins that `CompleteReadinessTaskRequest::
     * failedAuthorization()` throwing before any model write leaves no trace
     * behind, not even a stray `updated`.
     */
    #[Test]
    public function a_refused_completion_writes_no_audit_row(): void
    {
        $occurrence = $this->occurrence();
        $employee = $this->userWith(['my.view'], 'oluwaseun@khb.test');
        $colleague = $this->userWith(['my.view'], 'colleague@khb.test');

        $theirs = ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => "Confirm colleague's attendance", 'owner_id' => $colleague->id,
            'is_blocking' => false, 'status' => 'open',
        ]);

        $before = AuditLog::query()->where('auditable_type', ReadinessTask::class)
            ->where('auditable_id', $theirs->getKey())->count();

        $this->actingAs($employee)
            ->post(route('bcms.readiness-tasks.complete', $theirs))
            ->assertForbidden();

        $after = AuditLog::query()->where('auditable_type', ReadinessTask::class)
            ->where('auditable_id', $theirs->getKey())->count();

        $this->assertSame($before, $after, 'A 403 completion attempt still wrote an audit row.');
        $this->assertSame('open', $theirs->refresh()->status);
    }

    /**
     * The `before`/`after` payload on the automatic `updated` row must be
     * exactly the columns that changed — no `organization_id`, no unrelated
     * column, nothing beyond `status`/`completed_at`/`completed_by` for a
     * plain completion. `BcmsAuditable::auditExcluded()` carries no
     * `ReadinessTask`-specific override, so this also confirms the base
     * exclusion list (`updated_at`, `push_token`, `raw_response`) is
     * sufficient here rather than assumed.
     */
    #[Test]
    public function the_completion_audit_rows_carry_only_the_changed_columns_and_nothing_sensitive(): void
    {
        $occurrence = $this->occurrence();
        $employee = $this->userWith(['my.view'], 'oluwaseun@khb.test');

        $task = ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => 'Confirm my own attendance', 'owner_id' => $employee->id,
            'is_blocking' => false, 'status' => 'open',
        ]);

        $this->actingAs($employee)
            ->post(route('bcms.readiness-tasks.complete', $task))
            ->assertRedirect();

        $updated = AuditLog::query()
            ->where('auditable_type', ReadinessTask::class)
            ->where('auditable_id', $task->getKey())
            ->where('event', 'updated')
            ->sole();

        $this->assertEqualsCanonicalizing(['status', 'completed_at', 'completed_by'], array_keys($updated->after));
        $this->assertArrayNotHasKey('updated_at', $updated->after);
        $this->assertSame('open', $updated->before['status']);
        $this->assertSame('complete', $updated->after['status']);

        $named = AuditLog::query()
            ->where('auditable_type', ReadinessTask::class)
            ->where('auditable_id', $task->getKey())
            ->where('event', 'readiness_task_completed')
            ->sole();

        $this->assertSame($employee->name, $named->after['by']);
        $this->assertNull($named->after['evidence_id']);
    }

    /**
     * `materialise()` is idempotent by `template_task_id` (`ReadinessService`'s
     * own docblock), and an unchanged re-run must not spam the log: re-running
     * it against an already-materialised, unrescheduled occurrence touches
     * `due_date` with the SAME value, which Eloquent's dirty-tracking must
     * skip entirely — no `updated` row, because nothing actually changed.
     */
    #[Test]
    public function rematerialising_an_unrescheduled_occurrence_writes_no_further_audit_rows(): void
    {
        $occurrence = $this->occurrence();

        $taskIds = ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())->pluck('id');
        $afterFirst = AuditLog::query()
            ->where('auditable_type', ReadinessTask::class)
            ->whereIn('auditable_id', $taskIds)
            ->count();

        $this->assertSame(
            $taskIds->count(),
            $afterFirst,
            'Materialising once should write exactly one "created" row per task.'
        );

        app(ReadinessService::class)->materialise($occurrence->refresh());

        $afterSecond = AuditLog::query()
            ->where('auditable_type', ReadinessTask::class)
            ->whereIn('auditable_id', $taskIds)
            ->count();

        $this->assertSame(
            $afterFirst,
            $afterSecond,
            'Re-materialising an unrescheduled occurrence wrote extra audit rows — the ladder was rebuilt for no reason.'
        );
    }

    /**
     * A genuine reschedule DOES move `due_date`, and that is a real column
     * change worth its own `updated` row — the guard above is about NOISE,
     * not about suppressing a legitimate change.
     */
    #[Test]
    public function rescheduling_and_rematerialising_does_write_an_audit_row_per_moved_task(): void
    {
        $occurrence = $this->occurrence();

        $taskIds = ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())->pluck('id');

        $occurrence->update(['scheduled_date' => $occurrence->scheduled_date->copy()->addDays(5)]);
        app(ReadinessService::class)->materialise($occurrence->refresh());

        $moved = AuditLog::query()
            ->where('auditable_type', ReadinessTask::class)
            ->whereIn('auditable_id', $taskIds)
            ->where('event', 'updated')
            ->count();

        $this->assertGreaterThan(0, $moved, 'Rescheduling the occurrence moved due dates but wrote no audit trail of it.');
    }

    /**
     * A query-count budget on materialising a typical ladder: bounded
     * roughly by 2 queries per task (the insert and its own audit insert)
     * plus a small, fixed overhead — not proportional to anything else on
     * the occurrence. Written as a ceiling rather than an exact figure so it
     * does not need retuning every time an unrelated column is added.
     */
    #[Test]
    public function materialising_a_typical_ladder_stays_within_a_bounded_query_budget(): void
    {
        $type = ExerciseType::query()->where('code', 'TABLETOP')->sole();

        $definition = app(ExerciseDefinitionService::class)->create(
            $this->programme, $type, 'Budget exercise',
            ['frequency_per_year' => 1, 'business_unit_id' => $this->unit->id, 'lead_time_days' => 10, 'status' => 'active'],
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id, 'definition_id' => $definition->getKey(),
            'sequence_no' => 1, 'scheduled_date' => now()->addDays(10)->toDateString(),
            'scheduled_start' => now()->addDays(10)->setTime(9, 0), 'status' => OccurrenceStatus::Planned,
        ]);

        DB::enableQueryLog();
        app(ReadinessService::class)->materialise($occurrence);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $taskCount = ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())->count();

        $this->assertGreaterThan(0, $taskCount, 'The template produced no tasks — this budget proves nothing.');
        $this->assertLessThanOrEqual(
            ($taskCount * 3) + 10,
            $queries,
            "Materialising {$taskCount} tasks issued {$queries} queries — check for an N+1 in the loop."
        );
    }

    /**
     * `ReadinessService::markOverdue()` is a bulk query-builder `update()`,
     * which never fires Eloquent's `updated` event and therefore never
     * reaches `BcmsAuditable` — pinned here as a KNOWN, DELIBERATE gap
     * (QA ruling: the status flip is system-derived and the sweep runs
     * hourly across every tenant; a per-row audit write for every task the
     * sweep touches was judged, by the engineer, disproportionate to a
     * status the readiness screen already renders live). This test exists so
     * the gap is visible and intentional rather than rediscovered as a
     * surprise — if `markOverdue()` is ever changed to audit, this assertion
     * is the one to flip.
     */
    #[Test]
    public function marking_a_task_overdue_writes_no_audit_row(): void
    {
        $occurrence = $this->occurrence();
        $task = ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())->first();
        $task->update(['due_date' => now()->subDay()->toDateString(), 'status' => 'open']);

        // The direct model `update()` above legitimately wrote its own
        // `updated` row (a genuine due_date/status change) — cleared so the
        // count below is only about `markOverdue()`'s own effect.
        AuditLog::query()->where('auditable_type', ReadinessTask::class)
            ->where('auditable_id', $task->getKey())->delete();

        app(ReadinessService::class)->markOverdue();

        $this->assertSame('overdue', $task->refresh()->status);
        $this->assertSame(
            0,
            AuditLog::query()->where('auditable_type', ReadinessTask::class)
                ->where('auditable_id', $task->getKey())->count(),
            'markOverdue() wrote an audit row — this pin needs updating, not silently ignoring.'
        );
    }

    /**
     * `BcmsDemoSeeder::seedCountdownDemo()` closes most of the live
     * occurrence's checklist with a plain `$task->update()`, not through
     * `ReadinessService::complete()` — so `BcmsAuditable` still fires (it is
     * on the model, not the service) but the actor is null because nothing
     * is authenticated in a seeder/console context. This pins that reported
     * behaviour, confirms the seeder's own idempotency guard
     * (`ExerciseParticipant::query()->exists()`) means a second run adds no
     * further audit rows, and demonstrates the resulting audit row for a
     * demo "completed" task reads as an unattributed system change rather
     * than as the named owner's own act — worth a follow-up, not a blocker
     * for this cycle.
     */
    #[Test]
    public function seeding_the_countdown_demo_is_idempotent_and_leaves_a_null_actor_on_seeded_completions(): void
    {
        $owner = User::create([
            'name' => 'Seeded Owner', 'email' => 'seeded-owner@khb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id, 'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        Contact::query()->create([
            'organization_id' => $this->organization->id, 'employee_id' => 'E-'.$owner->id,
            'user_id' => $owner->id, 'full_name' => $owner->name, 'email' => $owner->email,
            'mobile_primary' => '+2348000011111', 'business_unit_id' => $this->unit->id,
            'source' => 'manual', 'is_active' => true,
        ]);

        $occurrence = $this->occurrence();

        $this->assertNull(\Illuminate\Support\Facades\Auth::user(), 'Precondition: nobody authenticated before the seeder runs.');

        $seeder = new BcmsDemoSeeder;
        $method = new ReflectionMethod($seeder, 'seedCountdownDemo');
        $method->setAccessible(true);
        $method->invoke($seeder);

        $completedTask = ReadinessTask::query()
            ->where('occurrence_id', ReadinessTask::query()->where('status', 'complete')->value('occurrence_id') ?? $occurrence->getKey())
            ->where('status', 'complete')
            ->first();

        $this->assertNotNull($completedTask, 'The seeder produced no completed readiness task to audit.');

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ReadinessTask::class,
            'auditable_id' => $completedTask->getKey(),
            'event' => 'updated',
            'actor_id' => null,
        ]);

        $this->assertNull(\Illuminate\Support\Facades\Auth::user(), 'The seeder must not leave anybody authenticated afterwards.');

        $countBefore = AuditLog::query()->where('auditable_type', ReadinessTask::class)->count();

        // Idempotent: the seeder's own guard (`ExerciseParticipant::exists()`)
        // means a second call is a no-op and writes nothing further.
        $method->invoke($seeder);

        $countAfter = AuditLog::query()->where('auditable_type', ReadinessTask::class)->count();

        $this->assertSame($countBefore, $countAfter, 'Running the countdown-demo seeder twice was not idempotent for audit rows.');
    }

    /* ------------------------------------------------------------------ */

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $email = 'bc@khb.test'): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => Str::title(Str::before($email, '@')),
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id, 'is_active' => true,
        ]);

        if ($permissions !== []) {
            $role = \Spatie\Permission\Models\Role::findOrCreate('readiness-audit-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($name, 'web'));
            }

            $user->assignRole($role);
        }

        DB::table('business_unit_user')->updateOrInsert(
            ['user_id' => $user->id, 'business_unit_id' => $this->unit->id],
            ['organization_id' => $this->organization->id, 'includes_descendants' => true,
                'created_at' => now(), 'updated_at' => now()],
        );

        return $user->refresh();
    }

    private function occurrence(bool $gating = false): ExerciseOccurrence
    {
        $type = ExerciseType::query()->where('code', 'TABLETOP')->sole();

        $definition = app(ExerciseDefinitionService::class)->create(
            $this->programme, $type, 'Exercise '.Str::random(4),
            [
                'frequency_per_year' => 1, 'business_unit_id' => $this->unit->id,
                'lead_time_days' => 10, 'readiness_gating' => $gating, 'status' => 'active',
            ],
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id, 'definition_id' => $definition->getKey(),
            'sequence_no' => 1, 'scheduled_date' => now()->addDays(10)->toDateString(),
            'scheduled_start' => now()->addDays(10)->setTime(9, 0), 'status' => OccurrenceStatus::Planned,
        ]);

        app(ReadinessService::class)->materialise($occurrence);
        app(ReminderScheduleBuilder::class)->build($occurrence->refresh(), $definition);

        return $occurrence->refresh();
    }
}
