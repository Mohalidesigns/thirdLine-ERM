<?php

namespace Tests\Feature\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use App\Models\Rcsa\RcsaCycle;
use App\Services\Rcsa\RcsaCycleService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * §11's cycle due-date notifications — the half of the schedule P5 did not
 * build.
 *
 * Same milestone shape as the action-plan sweep, and the tests are the same
 * shape too: the milestones fire exactly, a unit that has finished is not
 * chased, and the sweep is idempotent because a milestone is a date rather
 * than a flag.
 */
class CycleDeadlineTest extends CycleTestCase
{
    private RcsaCycle $cycle;

    private RcsaAssessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishedRisk(['risk_no' => 'RETAIL-R1']);

        $this->cycle = $this->makeCycle();
        app(RcsaCycleService::class)->open($this->cycle, $this->actor);
        $this->cycle->refresh();

        $this->assessment = RcsaAssessment::query()->where('business_unit_id', $this->retail->id)->sole();

        // Somebody to chase.
        $this->retail->forceFill(['head_id' => $this->actor->id])->save();
    }

    private function dueIn(int $days): void
    {
        $this->cycle->forceFill(['due_date' => now()->addDays($days)->toDateString()])->save();
    }

    private function notices(): int
    {
        return DB::table('notifications_log')->where('type', 'rcsa.cycle.due')->count();
    }

    #[Test]
    public function a_unit_is_reminded_at_t_minus_14_7_and_0(): void
    {
        foreach ([14, 7, 0] as $days) {
            DB::table('notifications_log')->delete();

            $this->dueIn($days);

            $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();

            $this->assertSame(1, $this->notices(), "No reminder at T-{$days}.");
        }
    }

    #[Test]
    public function a_day_that_is_not_a_milestone_sends_nothing(): void
    {
        foreach ([13, 9, 3, 1] as $days) {
            $this->dueIn($days);
            $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();
        }

        $this->assertSame(0, $this->notices());
    }

    /**
     * Once late, weekly rather than daily — a daily mail about a fortnight-old
     * deadline is how a unit learns to filter the sender.
     */
    #[Test]
    public function an_overdue_cycle_is_chased_weekly_not_daily(): void
    {
        $this->dueIn(-7);
        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();
        $this->assertSame(1, $this->notices());

        DB::table('notifications_log')->delete();

        $this->dueIn(-8);
        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();
        $this->assertSame(0, $this->notices());

        $this->dueIn(-14);
        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();
        $this->assertSame(1, $this->notices());
    }

    #[Test]
    public function a_unit_that_has_already_submitted_is_not_chased(): void
    {
        $this->assessment->forceFill(['status' => RcsaAssessment::SUBMITTED])->save();

        $this->dueIn(7);
        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();

        $this->assertSame(0, $this->notices());
    }

    /**
     * A returned assessment is owed again, and by the person who filed it.
     */
    #[Test]
    public function a_returned_assessment_is_chased_again(): void
    {
        $champion = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete']);

        $this->assessment->forceFill([
            'status' => RcsaAssessment::RETURNED,
            'submitted_by' => $champion->id,
            'assigned_to' => null,
        ])->save();

        $this->retail->forceFill(['head_id' => null])->save();

        $this->dueIn(7);
        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('notifications_log')
                ->where('type', 'rcsa.cycle.due')
                ->where('user_id', $champion->id)
                ->count(),
        );
    }

    /**
     * With nobody named, the P7 assignment table is what says who to chase.
     */
    #[Test]
    public function an_unnamed_unit_falls_back_to_the_users_assigned_to_it(): void
    {
        $this->retail->forceFill(['head_id' => null])->save();
        $this->assessment->forceFill(['assigned_to' => null, 'submitted_by' => null])->save();

        $assignee = $this->userWith(['rcsa_assessment.view', 'rcsa_assessment.complete'], units: [$this->retail]);

        $this->dueIn(0);
        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('notifications_log')
                ->where('type', 'rcsa.cycle.due')
                ->where('user_id', $assignee->id)
                ->count(),
        );
    }

    #[Test]
    public function a_closed_cycle_is_not_chased(): void
    {
        $this->dueIn(0);
        app(RcsaCycleService::class)->close($this->cycle->refresh(), $this->actor);

        $this->artisan('rcsa:check-cycle-deadlines')->assertSuccessful();

        $this->assertSame(0, $this->notices());
    }

    #[Test]
    public function a_dry_run_sends_nothing(): void
    {
        $this->dueIn(0);

        $this->artisan('rcsa:check-cycle-deadlines --dry-run')->assertSuccessful();

        $this->assertSame(0, $this->notices());
    }
}
