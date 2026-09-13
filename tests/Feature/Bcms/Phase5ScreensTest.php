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
