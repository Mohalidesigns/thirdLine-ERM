<?php

namespace Tests\Feature\Console;

use App\Models\TreatmentPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * `treatments:check-overdue` — the nightly sweep.
 *
 * The command used to write its notification row by hand, reading
 * `responsible_user_id` off a treatment plan that has never had such a column.
 * The value was therefore always null, `notifications_log.user_id` is NOT NULL
 * with a foreign key, and the insert threw on the FIRST plan of the sweep —
 * after that plan's status update had already committed. Every night the job
 * marked exactly one plan overdue and died, and nobody was ever told.
 *
 * These tests pin the three things that made that possible: the sweep runs to
 * the end, the recipient is the plan's real owner column, and the day count in
 * the body is a positive number of days (Carbon 3 returns a signed diff).
 */
class OverdueSweepTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDomainFixtures();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    private function makePlan(array $attributes = []): TreatmentPlan
    {
        static $sequence = 0;
        $sequence++;

        return TreatmentPlan::create(array_merge([
            'organization_id' => $this->organization->id,
            'risk_id' => $this->makeRisk()->id,
            'treatment_code' => sprintf('TP-TEST-%04d', $sequence),
            'strategy' => 'mitigate',
            'action_title' => "Treatment plan {$sequence}",
            'action_description' => "Fixture treatment plan {$sequence}",
            'owner_id' => $this->actor->id,
            'target_date' => now()->subDays(14),
            'priority' => 'high',
            'status' => 'in_progress',
            'created_by' => $this->actor->id,
        ], $attributes));
    }

    private function makeUser(string $label): User
    {
        return User::create([
            'name' => $label,
            'email' => str($label)->slug().'-'.uniqid().'@example.test',
            'password' => Hash::make('password'),
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function the_sweep_marks_an_overdue_plan_and_notifies_its_owner(): void
    {
        $owner = $this->makeUser('Treatment Owner');
        $plan = $this->makePlan(['owner_id' => $owner->id, 'target_date' => now()->subDays(14)]);

        $this->artisan('treatments:check-overdue')->assertExitCode(0);

        $this->assertSame('overdue', $plan->fresh()->status);

        $notification = DB::table('notifications_log')
            ->where('type', 'treatment_overdue')
            ->first();

        $this->assertNotNull($notification, 'The sweep told nobody the plan was overdue.');
        $this->assertSame($owner->id, (int) $notification->user_id);
        $this->assertSame($this->organization->id, (int) $notification->organization_id);
    }

    #[Test]
    public function the_body_reports_a_positive_number_of_days_overdue(): void
    {
        // Carbon 3 flipped $absolute to false, so now()->diffInDays($past) is
        // NEGATIVE. The body used to read "is -14.3958333 days overdue".
        $this->makePlan(['target_date' => now()->subDays(14)]);

        $this->artisan('treatments:check-overdue')->assertExitCode(0);

        $notification = DB::table('notifications_log')->where('type', 'treatment_overdue')->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('14 days overdue', $notification->body);
        $this->assertStringNotContainsString('-', $notification->body);
    }

    #[Test]
    public function the_notification_carries_the_columns_the_bell_reads(): void
    {
        // NotificationController reads notification_category, priority and
        // action_url as COLUMNS. The hand-rolled insert put them in the
        // metadata JSON and left the columns null, so the bell entry was
        // unclickable and uncategorised.
        $plan = $this->makePlan(['target_date' => now()->subDays(45)]);

        $this->artisan('treatments:check-overdue')->assertExitCode(0);

        $notification = DB::table('notifications_log')->where('type', 'treatment_overdue')->first();

        $this->assertNotNull($notification);
        $this->assertNotNull($notification->action_url, 'The notification is unclickable.');
        $this->assertSame("/risk/treatments/{$plan->id}", $notification->action_url);
        $this->assertSame('treatment', $notification->notification_category);
        // 45 days is past the 30-day line, so this one is critical — a branch
        // the signed diff made unreachable.
        $this->assertSame('critical', $notification->priority);
    }

    #[Test]
    public function one_plan_with_an_unresolvable_owner_does_not_stop_the_others(): void
    {
        // owner_id is nullable, and this is the row that used to kill the run.
        // It is created first so it is processed first.
        $orphan = $this->makePlan(['owner_id' => null, 'target_date' => now()->subDays(20)]);
        $second = $this->makePlan(['target_date' => now()->subDays(10)]);
        $third = $this->makePlan(['target_date' => now()->subDays(5)]);

        $this->artisan('treatments:check-overdue')->assertExitCode(0);

        $this->assertSame('overdue', $orphan->fresh()->status);
        $this->assertSame('overdue', $second->fresh()->status, 'The sweep stopped at the orphan.');
        $this->assertSame('overdue', $third->fresh()->status, 'The sweep stopped at the orphan.');

        // Two notifications, not three: the orphan has nobody to tell, and a
        // null user_id is not insertable.
        $this->assertSame(2, DB::table('notifications_log')->where('type', 'treatment_overdue')->count());
    }

    #[Test]
    public function a_completed_plan_past_its_date_is_left_alone(): void
    {
        $plan = $this->makePlan(['status' => 'completed', 'target_date' => now()->subDays(30)]);

        $this->artisan('treatments:check-overdue')->assertExitCode(0);

        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame(0, DB::table('notifications_log')->where('type', 'treatment_overdue')->count());
    }

    #[Test]
    public function a_plan_already_marked_overdue_is_not_notified_again(): void
    {
        $this->makePlan(['status' => 'overdue', 'target_date' => now()->subDays(30)]);

        $this->artisan('treatments:check-overdue')->assertExitCode(0);

        $this->assertSame(0, DB::table('notifications_log')->where('type', 'treatment_overdue')->count());
    }
}
