<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\OccurrenceStatus;
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
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The Phase 5 screens.
 *
 * THE LADDER PANEL IS THE DEMO MOMENT and is tested as one: the plan has to be
 * complete and accurate before a single alert has gone out, because that is
 * what a customer is shown. Everything else here is gating.
 */
class Phase5ScreensTest extends TestCase
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

    #[Test]
    public function the_readiness_screen_carries_the_checklist_the_gate_and_the_alert_plan(): void
    {
        $occurrence = $this->occurrence(gating: true);

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.occurrences.readiness', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Readiness')
                ->where('occurrence.readiness_gating', true)
                ->has('tasks')
                ->where('gate.allowed', false)
                ->has('gate.blocking')
                // The demo moment: the full plan, before anything has gone out.
                ->has('ladder.entries')
                ->where('ladder.sent', 0)
                ->has('ladder.total_recipients')
                ->has('attendance')
                ->where('can.override', false)
            );
    }

    /**
     * Gap 1 — no UI could start an occurrence because no screen carried what
     * a Start button needs. The readiness screen is where a facilitator
     * decides to go, so it carries `can.start`, the existing readiness gate,
     * and the date guard's own confirmation flag.
     */
    #[Test]
    public function the_readiness_screen_carries_what_a_start_button_needs(): void
    {
        $occurrence = $this->occurrence(gating: false);

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.occurrences.readiness', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Readiness')
                // A viewer without `facilitate` may never start it.
                ->where('can.start', false)
                ->where('start.already_started', false)
                ->where('start.requires_override', false)
                // The helper schedules ten days out — the date guard's flag
                // must say so, unprompted, before the facilitator ever POSTs.
                ->where('start.requires_early_confirmation', true)
                ->has('start.url')
                ->where('start.scheduled_date', $occurrence->scheduled_date->toDateString())
            );

        $this->actingAs($this->userWith(['bcms.exercise.facilitate', 'bcms.exercise.view'], 'facilitator@khb.test'))
            ->get(route('bcms.occurrences.readiness', $occurrence))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/Readiness')
                ->where('can.start', true)
            );
    }

    #[Test]
    public function my_readiness_tasks_lists_what_i_owe_across_every_exercise(): void
    {
        $user = $this->userWith(['bcms.exercise.view'], 'owner@khb.test');

        $first = $this->occurrence(name: 'First');
        $second = $this->occurrence(name: 'Second');

        ReadinessTask::query()
            ->whereIn('occurrence_id', [$first->getKey(), $second->getKey()])
            ->update(['owner_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('bcms.readiness.mine'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/MyReadiness')
                ->has('tasks', ReadinessTask::query()->whereIn('status', ['open', 'in_progress', 'overdue'])->count())
            );
    }

    /**
     * Gap 5 — Oluwaseun's case: a `my.view`-only employee sees his own
     * readiness task on this page (he lacked `bcms.exercise.view` and 403'd
     * before this fix) and may complete it (he lacked `bcms.exercise.
     * facilitate` and 403'd on that too), but only his own — never a
     * colleague's, and never with the broad `bcms.exercise.view`/`.
     * facilitate` grants.
     */
    #[Test]
    public function a_my_view_only_employee_sees_and_completes_only_their_own_readiness_task(): void
    {
        $occurrence = $this->occurrence();
        $employee = $this->userWith(['my.view'], 'oluwaseun@khb.test');
        $colleague = $this->userWith(['my.view'], 'colleague@khb.test');

        $this->assertFalse($employee->can('bcms.exercise.view'));
        $this->assertFalse($employee->can('bcms.exercise.facilitate'));

        // Created directly, non-blocking and evidence-free, so completing
        // exercises only the ownership check this test is about — the
        // template's own requirements are `overriding_a_blocking_task_…`'s
        // concern, not this one's.
        $mine = ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => 'Confirm my own attendance', 'owner_id' => $employee->id,
            'is_blocking' => false, 'status' => 'open',
        ]);
        $theirs = ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => "Confirm colleague's attendance", 'owner_id' => $colleague->id,
            'is_blocking' => false, 'status' => 'open',
        ]);

        $this->actingAs($employee)
            ->get(route('bcms.readiness.mine'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/MyReadiness')
                ->has('tasks', 1)
                ->where('tasks.0.id', $mine->getKey())
                ->has('tasks.0.complete_url')
                // D3: `my.view` alone does not carry `bcms.exercise.view`,
                // which the full occurrence readiness screen needs — the
                // key is ABSENT, not null, so a `my.view`-only owner never
                // sees a link that would 403 them.
                ->missing('tasks.0.readiness_url')
            );

        // D3's other half: a user who DOES hold `bcms.exercise.view` gets
        // the key, pointing at the same occurrence readiness screen.
        $viewer = $this->userWith(['my.view', 'bcms.exercise.view'], 'viewer@khb.test');
        ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => "Confirm viewer's attendance", 'owner_id' => $viewer->id,
            'is_blocking' => false, 'status' => 'open',
        ]);
        $this->actingAs($viewer)
            ->get(route('bcms.readiness.mine'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('tasks', 1)
                ->where('tasks.0.readiness_url', route('bcms.occurrences.readiness', $occurrence))
            );

        // Completes their own.
        $this->actingAs($employee)
            ->post(route('bcms.readiness-tasks.complete', $mine))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('complete', $mine->refresh()->status);

        // Refused on a colleague's.
        $this->actingAs($employee)
            ->post(route('bcms.readiness-tasks.complete', $theirs))
            ->assertForbidden();
        $this->assertNotSame('complete', $theirs->refresh()->status);

        // Refused outright with neither permission.
        $noGrant = $this->userWith([], 'no-grant@khb.test');
        $this->actingAs($noGrant)
            ->get(route('bcms.readiness.mine'))
            ->assertForbidden();
    }

    /**
     * Demo fix 3 — the My Readiness page shows a "Calendar" header button to
     * every user, but `bcms.calendar.index` needs `bcms.exercise.view`, and a
     * `my.view`-only employee (Oluwaseun's case again) got a live 403. The
     * key is ABSENT for him, not null — the same presence rule D3 already
     * settled for `tasks.*.readiness_url` — and PRESENT, pointing at the real
     * route, for a user who actually holds the calendar's own grant.
     */
    #[Test]
    public function calendar_url_is_present_only_for_a_user_who_may_open_the_calendar(): void
    {
        $occurrence = $this->occurrence();
        $employee = $this->userWith(['my.view'], 'oluwaseun@khb.test');

        ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => 'Confirm my own attendance', 'owner_id' => $employee->id,
            'is_blocking' => false, 'status' => 'open',
        ]);

        $this->actingAs($employee)
            ->get(route('bcms.readiness.mine'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bcms/Exercises/MyReadiness')
                ->missing('calendar_url')
            );

        $viewer = $this->userWith(['my.view', 'bcms.exercise.view'], 'viewer@khb.test');
        ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => "Confirm viewer's attendance", 'owner_id' => $viewer->id,
            'is_blocking' => false, 'status' => 'open',
        ]);

        $this->actingAs($viewer)
            ->get(route('bcms.readiness.mine'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('calendar_url', route('bcms.calendar.index'))
            );
    }

    /**
     * QA gate coverage — A8 (code review, non-EMNS demo-gap set): moving the
     * inline `Gate::authorize()`/`abort(403, …)` calls into
     * `CompleteReadinessTaskRequest` must preserve BOTH distinct 403
     * messages, not collapse them into one generic denial —
     * `failedAuthorization()`'s own docblock names this as the point of the
     * refactor.
     */
    #[Test]
    public function completing_a_readiness_task_preserves_both_403_messages(): void
    {
        $occurrence = $this->occurrence();
        $employee = $this->userWith(['my.view'], 'oluwaseun@khb.test');
        $colleague = $this->userWith(['my.view'], 'colleague@khb.test');
        $noGrant = $this->userWith([], 'no-grant@khb.test');

        $theirs = ReadinessTask::query()->create([
            'organization_id' => $this->organization->id, 'occurrence_id' => $occurrence->getKey(),
            'title' => "Confirm colleague's attendance", 'owner_id' => $colleague->id,
            'is_blocking' => false, 'status' => 'open',
        ]);

        // my.view, but not the owner of this task: the specific message.
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($employee)->post(route('bcms.readiness-tasks.complete', $theirs));
            $this->fail('Expected an AuthorizationException.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertSame('This readiness task is not assigned to you.', $e->getMessage());
        }

        // Neither grant at all: refused by the route's own permission
        // middleware, before `CompleteReadinessTaskRequest` is ever reached
        // — a generic denial, never the ownership message, which would
        // wrongly imply the task might otherwise have been theirs to
        // complete.
        try {
            $this->actingAs($noGrant)->post(route('bcms.readiness-tasks.complete', $theirs));
            $this->fail('Expected an authorization failure.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertNotSame('This readiness task is not assigned to you.', $e->getMessage());
        }
    }

    #[Test]
    public function overriding_a_blocking_task_needs_its_own_permission_and_a_reason(): void
    {
        $occurrence = $this->occurrence(gating: true);

        $task = ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('is_blocking', true)
            ->first();

        // Facilitating an exercise and deciding to run it unprepared are
        // different acts. `bcms.readiness.override` is held by fewer people.
        $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.exercise.facilitate'], 'facilitator@khb.test'))
            ->post(route('bcms.readiness-tasks.override', $task), ['reason' => 'The vendor confirmed by telephone.'])
            ->assertForbidden();

        $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.readiness.override'], 'chief@khb.test'))
            ->post(route('bcms.readiness-tasks.override', $task), ['reason' => 'short'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.readiness.override'], 'chief@khb.test'))
            ->post(route('bcms.readiness-tasks.override', $task), [
                'reason' => 'The vendor confirmed by telephone and the written note follows.',
            ])
            ->assertRedirect();

        $this->assertSame('waived', $task->refresh()->status);
    }

    /**
     * Demo fix 4 — verified live: completing task 225 set `completed_by`/
     * `completed_at` but wrote no `bcms_audit_logs` row at all. `ReadinessTask`
     * now carries `BcmsAuditable`, the same way `ExerciseInject` does, and
     * `ReadinessService::complete()` also records the named
     * `readiness_task_completed` event on top of the automatic column diff.
     */
    #[Test]
    public function completing_a_readiness_task_writes_an_audit_row(): void
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
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ReadinessTask::class,
            'auditable_id' => $task->getKey(),
            'event' => 'readiness_task_completed',
            'actor_id' => $employee->getKey(),
        ]);

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ReadinessTask::class,
            'auditable_id' => $task->getKey(),
            'event' => 'updated',
            'actor_id' => $employee->getKey(),
        ]);
    }

    /** Demo fix 4's other half — an override is audited on the task too. */
    #[Test]
    public function overriding_a_readiness_task_writes_an_audit_row(): void
    {
        $occurrence = $this->occurrence(gating: true);

        $task = ReadinessTask::query()
            ->where('occurrence_id', $occurrence->getKey())
            ->where('is_blocking', true)
            ->first();

        $chief = $this->userWith(['bcms.exercise.view', 'bcms.readiness.override'], 'chief@khb.test');

        $this->actingAs($chief)
            ->post(route('bcms.readiness-tasks.override', $task), [
                'reason' => 'The vendor confirmed by telephone and the written note follows.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('bcms_audit_logs', [
            'auditable_type' => ReadinessTask::class,
            'auditable_id' => $task->getKey(),
            'event' => 'readiness_task_overridden',
            'actor_id' => $chief->getKey(),
        ]);
    }

    #[Test]
    public function declining_attendance_through_the_screen_requires_a_deputy(): void
    {
        $occurrence = $this->occurrence();
        $user = $this->userWith(['bcms.exercise.view'], 'participant@khb.test');

        $this->actingAs($user)
            ->post(route('bcms.occurrences.confirm-attendance', $occurrence), ['response' => 'decline'])
            ->assertSessionHasErrors('deputy_user_id');

        $deputy = $this->userWith([], 'deputy@khb.test');

        $this->actingAs($user)
            ->post(route('bcms.occurrences.confirm-attendance', $occurrence), [
                'response' => 'decline', 'deputy_user_id' => $deputy->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function the_delivery_evidence_export_is_behind_the_report_grant(): void
    {
        $occurrence = $this->occurrence();

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.occurrences.deliveries.export', $occurrence))
            ->assertForbidden();

        $response = $this->actingAs($this->userWith(['bcms.exercise.view', 'bcms.report.export'], 'auditor@khb.test'))
            ->get(route('bcms.occurrences.deliveries.export', $occurrence));

        $response->assertOk();
        $this->assertStringContainsString('Consolidated into', $response->streamedContent());
    }

    #[Test]
    public function every_phase_five_route_is_behind_a_permission(): void
    {
        $occurrence = $this->occurrence();
        $nobody = $this->userWith([], 'nobody@khb.test');

        foreach ([
            route('bcms.readiness.mine'),
            route('bcms.occurrences.readiness', $occurrence),
        ] as $url) {
            $this->actingAs($nobody)->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function the_whole_phase_is_behind_the_feature_flag(): void
    {
        $occurrence = $this->occurrence();

        config()->set('features.bcms', false);

        $this->actingAs($this->userWith(['bcms.exercise.view']))
            ->get(route('bcms.occurrences.readiness', $occurrence))
            ->assertNotFound();
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
            $role = Role::findOrCreate('bcms-'.md5($email), 'web');

            foreach ($permissions as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
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

    private function occurrence(bool $gating = false, ?string $name = null): ExerciseOccurrence
    {
        $type = ExerciseType::query()->where('code', 'TABLETOP')->sole();

        $definition = app(ExerciseDefinitionService::class)->create(
            $this->programme,
            $type,
            $name ?? 'Exercise '.Str::random(4),
            [
                'frequency_per_year' => 1,
                'business_unit_id' => $this->unit->id,
                'lead_time_days' => 10,
                'readiness_gating' => $gating,
                'status' => 'active',
            ],
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $this->organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDays(10)->toDateString(),
            'scheduled_start' => now()->addDays(10)->setTime(9, 0),
            'status' => OccurrenceStatus::Planned,
        ]);

        app(ReadinessService::class)->materialise($occurrence);
        app(ReminderScheduleBuilder::class)->build($occurrence->refresh(), $definition);

        return $occurrence->refresh();
    }
}
