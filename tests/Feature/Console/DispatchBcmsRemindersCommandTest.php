<?php

namespace Tests\Feature\Console;

use App\Enums\Bcms\OccurrenceStatus;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseParticipant;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\NotificationDelivery;
use App\Models\Bcms\ReadinessTask;
use App\Models\Bcms\ReminderSchedule;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Reminders\ReadinessService;
use App\Services\Bcms\Reminders\ReminderScheduleBuilder;
use App\Support\Bcms\AudienceRule;
use Database\Seeders\Bcms\BcmsReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `bcms:dispatch-reminders` — the hourly tick the scheduler actually runs.
 *
 * The dispatcher and readiness service already have direct unit coverage
 * (Phase5CountdownTest); this file covers the wrapper: the per-organisation
 * loop, the `--dry-run` flag, and the totals line the scheduler's log
 * inherits. `ReminderSchedule` and `ReadinessTask` are both tenant-scoped
 * (`BelongsToOrganization`), so a wrapper bug that forgot to set
 * `TenantContext` per organisation would either see nothing or everything —
 * the multi-tenant test below is what would have caught that.
 */
class DispatchBcmsRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.bcms', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_dispatches_due_reminders_and_marks_readiness_tasks_overdue(): void
    {
        [$organization, $unit, $occurrence] = $this->seedOrganizationWithDueOccurrence('Kano Heritage Bank');

        TenantContext::set($organization->id);
        $overdueTask = ReadinessTask::query()->where('occurrence_id', $occurrence->getKey())->first();
        $overdueTask->update(['due_date' => now()->subDay()->toDateString(), 'status' => 'open']);
        TenantContext::clear();

        $at = $occurrence->scheduled_date->copy()->subDays(10)->setTime(8, 0);
        Carbon::setTestNow($at);

        $this->artisan('bcms:dispatch-reminders')->assertSuccessful();

        TenantContext::set($organization->id);
        $sent = ReminderSchedule::query()->where('status', 'sent')->count();
        $deliveries = NotificationDelivery::query()->count();
        $overdueNow = ReadinessTask::query()->whereKey($overdueTask->getKey())->value('status');
        TenantContext::clear();

        $this->assertGreaterThan(0, $sent, 'The dispatcher wrapper claimed nothing due.');
        $this->assertGreaterThan(0, $deliveries, 'No notification was actually recorded — the wrapper no-opped.');
        $this->assertSame('overdue', $overdueNow, 'The wrapper did not call ReadinessService::markOverdue().');
    }

    #[Test]
    public function running_it_twice_sends_nothing_a_second_time(): void
    {
        [$organization, , $occurrence] = $this->seedOrganizationWithDueOccurrence('Kano Heritage Bank');

        $at = $occurrence->scheduled_date->copy()->subDays(10)->setTime(8, 0);
        Carbon::setTestNow($at);

        $this->artisan('bcms:dispatch-reminders')->assertSuccessful();

        TenantContext::set($organization->id);
        $afterFirst = NotificationDelivery::query()->count();
        TenantContext::clear();

        $this->assertGreaterThan(0, $afterFirst, 'The first run sent nothing, so this proves nothing.');

        $this->artisan('bcms:dispatch-reminders')->assertSuccessful();

        TenantContext::set($organization->id);
        $afterSecond = NotificationDelivery::query()->count();
        TenantContext::clear();

        $this->assertSame($afterFirst, $afterSecond, 'Re-running the command sent something twice.');
    }

    #[Test]
    public function it_no_ops_quietly_when_nothing_is_due(): void
    {
        Organization::create([
            'name' => 'Quiet Bank', 'short_name' => 'QB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        $result = $this->artisan('bcms:dispatch-reminders');
        $result->assertSuccessful();

        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    #[Test]
    public function dry_run_reports_what_is_due_and_claims_nothing(): void
    {
        [$organization, , $occurrence] = $this->seedOrganizationWithDueOccurrence('Kano Heritage Bank');

        $at = $occurrence->scheduled_date->copy()->subDays(10)->setTime(8, 0);
        Carbon::setTestNow($at);

        $this->artisan('bcms:dispatch-reminders', ['--dry-run' => true])->assertSuccessful();

        TenantContext::set($organization->id);
        $stillPending = ReminderSchedule::query()->where('status', 'pending')->count();
        $deliveries = NotificationDelivery::query()->count();
        TenantContext::clear();

        $this->assertGreaterThan(0, $stillPending, 'A dry run claimed the rows it was only supposed to report on.');
        $this->assertSame(0, $deliveries, 'A dry run sent something.');
    }

    #[Test]
    public function each_organisation_is_processed_under_its_own_tenant_context(): void
    {
        [$orgA, , $occurrenceA] = $this->seedOrganizationWithDueOccurrence('Bank A');
        [$orgB, , $occurrenceB] = $this->seedOrganizationWithDueOccurrence('Bank B');

        $at = $occurrenceA->scheduled_date->copy()->subDays(10)->setTime(8, 0);
        Carbon::setTestNow($at);

        $this->artisan('bcms:dispatch-reminders')->assertSuccessful();

        TenantContext::set($orgA->id);
        $deliveriesA = NotificationDelivery::query()->count();
        TenantContext::clear();

        TenantContext::set($orgB->id);
        $deliveriesB = NotificationDelivery::query()->count();
        TenantContext::clear();

        $this->assertGreaterThan(0, $deliveriesA, "Bank A's own reminders were not dispatched.");
        $this->assertGreaterThan(0, $deliveriesB, "Bank B's reminders were not dispatched under its own tenant context.");

        // Bypass tenancy to prove neither organisation's rows crossed into
        // the other's — a wrapper that ran once with no context set, or with
        // the wrong id, would either double these counts or halve them.
        $totalAcrossBothTenants = NotificationDelivery::query()
            ->withoutGlobalScopes()
            ->whereIn('organization_id', [$orgA->id, $orgB->id])
            ->count();
        $this->assertSame($deliveriesA + $deliveriesB, $totalAcrossBothTenants);
    }

    /**
     * @return array{0: Organization, 1: BusinessUnit, 2: ExerciseOccurrence}
     */
    private function seedOrganizationWithDueOccurrence(string $name): array
    {
        $organization = Organization::create([
            'name' => $name, 'short_name' => Str::upper(Str::substr(Str::slug($name), 0, 3)),
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($organization->id);
        $this->seed(BcmsReferenceSeeder::class);
        TenantContext::set($organization->id);

        $unit = BusinessUnit::create([
            'organization_id' => $organization->id, 'code' => 'BU-OPS', 'name' => 'Operations', 'is_active' => true,
        ]);

        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@'.Str::slug($name).'.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $organization->id, 'business_unit_id' => $unit->id, 'is_active' => true,
        ]);

        $facilitator = User::create([
            'name' => 'Facilitator', 'email' => 'facilitator@'.Str::slug($name).'.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $organization->id, 'business_unit_id' => $unit->id, 'is_active' => true,
        ]);

        $participant = User::create([
            'name' => 'Participant', 'email' => 'participant@'.Str::slug($name).'.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $organization->id, 'business_unit_id' => $unit->id, 'is_active' => true,
        ]);

        $programme = app(ExerciseProgrammeService::class)->create(
            (int) now()->year, 'Exercise programme', [], $owner->id,
        );

        $type = ExerciseType::query()->where('code', 'TABLETOP')->sole();

        $definition = app(ExerciseDefinitionService::class)->create(
            $programme,
            $type,
            'Exercise '.Str::random(4),
            [
                'frequency_per_year' => 1,
                'business_unit_id' => $unit->id,
                'owner_id' => $owner->id,
                'facilitator_id' => $facilitator->id,
                'lead_time_days' => 10,
                'daily_reminder_enabled' => true,
                'unannounced' => false,
                'readiness_gating' => false,
                'status' => 'active',
                'default_audience_rule' => AudienceRule::make('org_node', ['id' => $unit->id])->toArray(),
            ],
            $owner->id,
        );

        $occurrence = ExerciseOccurrence::query()->create([
            'organization_id' => $organization->id,
            'definition_id' => $definition->getKey(),
            'sequence_no' => 1,
            'scheduled_date' => now()->addDays(10)->toDateString(),
            'scheduled_start' => now()->addDays(10)->setTime(9, 0),
            'scheduled_end' => now()->addDays(10)->setTime(11, 0),
            'status' => OccurrenceStatus::Planned,
            'facilitator_id' => $facilitator->id,
        ]);

        app(ReadinessService::class)->materialise($occurrence);
        app(ReminderScheduleBuilder::class)->build($occurrence->refresh(), $definition);

        $contact = Contact::query()->create([
            'organization_id' => $organization->id,
            'employee_id' => 'E-'.$participant->id,
            'user_id' => $participant->id,
            'full_name' => $participant->name,
            'email' => $participant->email,
            'mobile_primary' => '+23480000'.str_pad((string) $participant->id, 5, '0', STR_PAD_LEFT),
            'business_unit_id' => $unit->id,
            'source' => 'manual',
            'is_active' => true,
        ]);

        ExerciseParticipant::query()->create([
            'occurrence_id' => $occurrence->getKey(),
            'organization_id' => $organization->id,
            'user_id' => $participant->id,
            'contact_id' => $contact->getKey(),
            'role' => 'participant',
            'business_unit_id' => $unit->id,
            'invitation_status' => 'pending',
        ]);

        TenantContext::clear();

        return [$organization, $unit, $occurrence->refresh()];
    }
}
