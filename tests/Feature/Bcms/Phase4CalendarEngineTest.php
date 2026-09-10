<?php

namespace Tests\Feature\Bcms;

use App\Enums\Bcms\DistributionMode;
use App\Enums\Bcms\ExerciseOutcome;
use App\Enums\Bcms\LadderLevel;
use App\Enums\Bcms\OccurrenceStatus;
use App\Events\Bcms\ExerciseOccurrenceScheduled;
use App\Models\ApprovalRequest;
use App\Models\Bcms\BlackoutPeriod;
use App\Models\Bcms\Contact;
use App\Models\Bcms\ExerciseDefinition;
use App\Models\Bcms\ExerciseOccurrence;
use App\Models\Bcms\ExerciseProgramme;
use App\Models\Bcms\ExerciseType;
use App\Models\Bcms\Process;
use App\Models\BusinessUnit;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\Bcms\Exercises\BlackoutResolver;
use App\Services\Bcms\Exercises\CalendarService;
use App\Services\Bcms\Exercises\ExerciseDefinitionService;
use App\Services\Bcms\Exercises\ExerciseProgrammeService;
use App\Services\Bcms\Exercises\IcsFeedBuilder;
use App\Services\Bcms\Exercises\LadderAdvisor;
use App\Services\Bcms\Exercises\OccurrenceGenerator;
use App\Services\Bcms\Exercises\RescheduleService;
use App\Services\Bcms\Exercises\WorkingCalendar;
use App\Support\Bcms\AudienceRule;
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
 * The Phase 4 acceptance criteria — the resilience calendar and the generation
 * engine.
 *
 * Criterion 3's third conflict source and criterion 3(d)/(e) are worth reading
 * before the tests that answer them. The phase prompt lists five conflict
 * sources; three exist here. There is **no audit engagement register in this
 * product** — thirdLine is this product, and what it holds is an audit's
 * findings rather than its diary — and **change-freeze windows are blackout
 * periods**, which the working calendar removes before the conflict detector is
 * asked anything. ADR 0012 argues both, and
 * `a_change_freeze_is_a_blackout_period_and_blocks_the_month()` is the second
 * one demonstrated rather than asserted.
 *
 * Criterion 9 — "subscribes successfully in Outlook and Google" — is not
 * something a PHP test can claim. What is tested is that the feed is valid RFC
 * 5545, that its UIDs are stable across a reschedule and its SEQUENCE
 * increments, which is what makes those clients MOVE an appointment rather than
 * duplicate it. Actually subscribing is **[verify at integration]**.
 */
class Phase4CalendarEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private BusinessUnit $unit;

    private User $author;

    private User $approver;

    private ExerciseProgramme $programme;

    private int $year;

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

        $this->author = $this->user('author@khb.test');
        $this->approver = $this->user('approver@khb.test');

        $this->year = (int) now()->addYear()->year;

        $this->programme = app(ExerciseProgrammeService::class)->create(
            $this->year,
            'Exercise programme '.$this->year,
            [],
            $this->author->id,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::clear();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 1 — 4 conflict-free occurrences, avoiding month-end and
    /*  Nigerian public holidays.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function four_times_a_year_generates_four_occurrences_clear_of_every_blackout(): void
    {
        $definition = $this->definition('DRFAILOVER', ['frequency_per_year' => 4]);

        $log = app(OccurrenceGenerator::class)->generate($definition);

        $this->assertSame(4, $log['placed']);
        $this->assertSame(0, $log['needs_scheduling']);

        $dates = $definition->occurrences()->orderBy('sequence_no')->pluck('scheduled_date');

        $this->assertCount(4, $dates);

        $calendar = app(WorkingCalendar::class)->build(
            $this->year,
            BlackoutPeriod::query()->where('is_active', true)->get(),
        );

        foreach ($dates as $date) {
            $day = $date->toDateString();

            $this->assertTrue(
                $calendar->isWorkingDay($day),
                "{$day} is not a working day: ".($calendar->reasonBlocked($day) ?? 'unknown'),
            );
        }

        // Spread across the year, not clustered. Four occurrences inside one
        // quarter would satisfy "four a year" and fail the point of it.
        $months = $dates->map(fn (Carbon $d) => (int) $d->format('n'))->all();
        $this->assertGreaterThanOrEqual(6, max($months) - min($months));
    }

    #[Test]
    public function month_end_salary_week_and_public_holidays_are_out_of_the_working_year(): void
    {
        $calendar = app(WorkingCalendar::class)->build(
            $this->year,
            BlackoutPeriod::query()->where('is_active', true)->get(),
        );

        // 1 October and 1 May are fixed Nigerian public holidays; the 27th is
        // salary week; the last working day of a month is month-end close.
        foreach ([$this->year.'-10-01', $this->year.'-05-01', $this->year.'-01-27'] as $blocked) {
            $this->assertFalse($calendar->isWorkingDay($blocked), $blocked.' should be blacked out.');
        }

        $this->assertGreaterThan(100, $calendar->count(), 'A year with almost no working days would be a bad calendar.');
        $this->assertLessThan(261, $calendar->count());
    }

    #[Test]
    public function easter_is_computed_rather_than_looked_up(): void
    {
        // ext-calendar is not guaranteed on a customer's on-premise PHP build,
        // so `easter_date()` is not used. These are the published dates.
        $resolver = app(BlackoutResolver::class);

        $this->assertSame('2027-03-28', $resolver->easterSunday(2027)->toDateString());
        $this->assertSame('2026-04-05', $resolver->easterSunday(2026)->toDateString());
        $this->assertSame('2030-04-21', $resolver->easterSunday(2030)->toDateString());
    }

    #[Test]
    public function the_year_end_window_blocks_both_ends_of_the_same_year(): void
    {
        // "15 December to 5 January" crosses the year boundary. A naive
        // `addYear()` on the end would block NEXT January and leave THIS one
        // open — and this January is the half a Q1 exercise collides with.
        $calendar = app(WorkingCalendar::class)->build(
            $this->year,
            BlackoutPeriod::query()->where('is_active', true)->get(),
        );

        $this->assertFalse($calendar->isWorkingDay($this->year.'-12-20'));
        $this->assertFalse($calendar->isWorkingDay($this->year.'-01-04'));
    }

    #[Test]
    public function the_blackouts_that_cannot_be_computed_are_reported_rather_than_guessed(): void
    {
        $calendar = app(WorkingCalendar::class)->build(
            $this->year,
            BlackoutPeriod::query()->where('is_active', true)->get(),
        );

        $names = array_column($calendar->unresolved, 'name');

        // Eid moves with the lunar calendar and is declared days ahead; the
        // election timetable is announced. Shipping a computed date would be
        // shipping a wrong date most years, so they resolve to nothing and say
        // so.
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_contains($n, 'Eid al-Fitr')));
        $this->assertTrue(collect($names)->contains(fn (string $n) => str_contains($n, 'election')));

        foreach ($calendar->unresolved as $entry) {
            $this->assertNotEmpty($entry['reason']);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 2 — dragging records a justified audit entry, and inside
    /*  min_notice_days creates an approval instead.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function moving_an_exercise_outside_its_notice_period_moves_it_and_records_why(): void
    {
        $definition = $this->definition('TABLETOP', ['frequency_per_year' => 1, 'min_notice_days' => 3]);
        app(OccurrenceGenerator::class)->generate($definition);

        $occurrence = $definition->occurrences()->sole();
        $from = $occurrence->scheduled_date->toDateString();
        $to = $occurrence->scheduled_date->copy()->addDays(7)->toDateString();

        $result = app(RescheduleService::class)->reschedule(
            $occurrence, $to, 'The facilitator is on leave that week.', $this->author,
        );

        $this->assertTrue($result['moved']);
        $this->assertNull($result['approval']);

        $occurrence->refresh();

        $this->assertSame($to, $occurrence->scheduled_date->toDateString());
        $this->assertSame(1, (int) $occurrence->reschedule_count);
        // Never changes again: "how much did you move it" is asked against this.
        $this->assertSame($from, $occurrence->originally_scheduled_date->toDateString());

        $audit = \App\Models\Bcms\AuditLog::query()
            ->where('auditable_id', $occurrence->getKey())
            ->where('event', 'rescheduled')
            ->sole();

        $this->assertSame('The facilitator is on leave that week.', $audit->after['justification']);
    }

    #[Test]
    public function moving_an_exercise_inside_its_notice_period_asks_permission_instead(): void
    {
        $definition = $this->definition('TABLETOP', ['frequency_per_year' => 1, 'min_notice_days' => 10]);
        app(OccurrenceGenerator::class)->generate($definition);

        $occurrence = $definition->occurrences()->sole();
        // Close enough that moving it looks like avoidance, which is the whole
        // reason `min_notice_days` exists.
        $occurrence->update(['scheduled_date' => now()->addDays(2)->toDateString()]);
        $before = $occurrence->refresh()->scheduled_date->toDateString();

        $result = app(RescheduleService::class)->reschedule(
            $occurrence, now()->addDays(60)->toDateString(), 'We would rather do it later.', $this->author,
        );

        $this->assertFalse($result['moved']);
        $this->assertInstanceOf(ApprovalRequest::class, $result['approval']);
        $this->assertSame($before, $occurrence->refresh()->scheduled_date->toDateString());
        $this->assertSame($before, $result['approval']->payload['_from']);
        $this->assertSame(0, (int) $occurrence->reschedule_count);

        // Approving applies the move AND the counter together, because they are
        // one payload rather than two things that can disagree.
        app(ApprovalService::class)->approve($result['approval'], $this->approver->id, 'Agreed.');

        $occurrence->refresh();

        $this->assertSame(now()->addDays(60)->toDateString(), $occurrence->scheduled_date->toDateString());
        $this->assertSame(1, (int) $occurrence->reschedule_count);
    }

    #[Test]
    public function a_move_with_no_reason_is_refused(): void
    {
        $definition = $this->definition('TABLETOP', ['frequency_per_year' => 1]);
        app(OccurrenceGenerator::class)->generate($definition);

        $this->expectExceptionMessageMatches('/requires a reason/');
        app(RescheduleService::class)->reschedule(
            $definition->occurrences()->sole(), now()->addDays(40)->toDateString(), '   ', $this->author,
        );
    }

    #[Test]
    public function a_mandatory_exercise_cannot_be_cancelled_without_a_waiver(): void
    {
        $definition = $this->definition('DRTEST', ['frequency_per_year' => 1, 'mandatory' => true]);
        app(OccurrenceGenerator::class)->generate($definition);

        $occurrence = $definition->occurrences()->sole();

        try {
            app(RescheduleService::class)->cancel($occurrence, 'We are too busy this year.', $this->author);
            $this->fail('A mandatory exercise was cancelled without a waiver.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('executive waiver', $e->getMessage());
        }

        $cancelled = app(RescheduleService::class)->cancel(
            $occurrence, 'Deferred to next year by the board.', $this->approver, waiverGranted: true,
        );

        $this->assertSame(OccurrenceStatus::Cancelled, $cancelled->status);
        $this->assertSame('Deferred to next year by the board.', $cancelled->cancellation_reason);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 3 — a shared-participant clash shifts the second forward,
    /*  visibly.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function two_exercises_needing_the_same_people_do_not_land_on_the_same_day(): void
    {
        $audience = AudienceRule::make('org_node', ['id' => $this->unit->id, 'include_descendants' => true])->toArray();

        foreach (range(1, 15) as $i) {
            $this->contact('Person '.$i);
        }

        $first = $this->definition('CYBER', [
            'frequency_per_year' => 1, 'default_audience_rule' => $audience,
            'preferred_window' => ['weekdays' => ['wed'], 'start_time' => '09:00'],
        ]);
        $second = $this->definition('CRISISSIM', [
            'frequency_per_year' => 1, 'default_audience_rule' => $audience,
            'preferred_window' => ['weekdays' => ['wed'], 'start_time' => '09:00'],
        ], 'Crisis simulation');

        app(OccurrenceGenerator::class)->generate($first);
        $log = app(OccurrenceGenerator::class)->generate($second);

        $firstDate = $first->occurrences()->sole()->scheduled_date->toDateString();
        $secondDate = $second->occurrences()->sole()->scheduled_date->toDateString();

        $this->assertNotSame($firstDate, $secondDate, 'Fifteen people cannot be in two exercises at once.');
        $this->assertSame(1, $log['shifted']);

        // Criterion 3's actual words: the shift is visible in the generation log.
        $entry = $log['occurrences'][0];

        $this->assertGreaterThan(1, $entry['attempts']);
        $this->assertNotEmpty($entry['shifted_from']);
        $this->assertStringContainsString('already committed', $entry['shifted_from'][0]['because']);
        $this->assertSame($firstDate, $entry['shifted_from'][0]['date']);
    }

    #[Test]
    public function exercises_at_different_times_of_the_same_day_do_not_clash(): void
    {
        // A 45-minute fire drill at 09:00 and a six-hour DR test at 14:00 share
        // their people and do not collide. Treating a day as atomic would shift
        // half the calendar for conflicts that are not real.
        $audience = AudienceRule::make('org_node', ['id' => $this->unit->id])->toArray();
        $this->contact('Shared person');

        $morning = $this->definition('FIREDRILL', [
            'frequency_per_year' => 1, 'duration_minutes' => 45, 'default_audience_rule' => $audience,
            'preferred_window' => ['weekdays' => ['wed'], 'start_time' => '09:00'],
        ], 'Morning drill');
        $afternoon = $this->definition('BACKUP', [
            'frequency_per_year' => 1, 'duration_minutes' => 120, 'default_audience_rule' => $audience,
            'preferred_window' => ['weekdays' => ['wed'], 'start_time' => '14:00'],
        ], 'Afternoon restore');

        app(OccurrenceGenerator::class)->generate($morning);
        $log = app(OccurrenceGenerator::class)->generate($afternoon);

        $this->assertSame(0, $log['shifted']);
        $this->assertSame(
            $morning->occurrences()->sole()->scheduled_date->toDateString(),
            $afternoon->occurrences()->sole()->scheduled_date->toDateString(),
        );
    }

    #[Test]
    public function a_definition_with_no_audience_conflicts_with_nothing(): void
    {
        // Fail closed (ADR 0003): a definition nobody has finished configuring
        // resolves to nobody, which conflicts with nothing rather than with
        // everything.
        $first = $this->definition('CYBER', ['frequency_per_year' => 1,
            'preferred_window' => ['weekdays' => ['wed']]], 'One');
        $second = $this->definition('CRISISSIM', ['frequency_per_year' => 1,
            'preferred_window' => ['weekdays' => ['wed']]], 'Two');

        app(OccurrenceGenerator::class)->generate($first);
        $log = app(OccurrenceGenerator::class)->generate($second);

        $this->assertSame(0, $log['shifted']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 4 — a blacked-out segment produces needs_scheduling.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_change_freeze_is_a_blackout_period_and_blocks_the_month(): void
    {
        // ADR 0012: the phase prompt lists "system change-freeze windows" as a
        // conflict source of its own. They are expressible exactly as this, and
        // a separate table would be the blackout calendar under another name.
        BlackoutPeriod::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Core banking migration freeze',
            'category' => 'custom',
            'is_hard_block' => true,
            'starts_on' => $this->year.'-07-01',
            'ends_on' => $this->year.'-07-31',
            'is_active' => true,
        ]);

        $definition = $this->definition('WALKTHRU', [
            'frequency_per_year' => 1,
            'distribution_mode' => DistributionMode::MonthSpecific->value,
            'preferred_window' => ['months' => [7]],
        ]);

        $log = app(OccurrenceGenerator::class)->generate($definition);

        $this->assertSame(0, $log['placed']);
        $this->assertSame(1, $log['needs_scheduling']);

        // NOTHING IS SILENTLY DROPPED. The occurrence exists, with no date, and
        // is visible as an unplaced obligation.
        $occurrence = $definition->occurrences()->sole();

        $this->assertSame(OccurrenceStatus::NeedsScheduling, $occurrence->status);
        $this->assertNull($occurrence->scheduled_date);
        $this->assertStringContainsString('blacked out', $log['occurrences'][0]['reason']);
    }

    #[Test]
    public function unplaced_occurrences_have_a_view_of_their_own(): void
    {
        $definition = $this->definition('WALKTHRU', [
            'frequency_per_year' => 1,
            'distribution_mode' => DistributionMode::Manual->value,
        ]);

        app(OccurrenceGenerator::class)->generate($definition);

        // Invisible on every date-ranged view by definition, so it needs one of
        // its own — an obligation nobody can see is one nobody places.
        $this->assertCount(1, app(CalendarService::class)->unscheduled(null));
        $this->assertSame(1, app(CalendarService::class)->year($this->year, null)['unscheduled']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 5 — idempotency.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function regenerating_twice_produces_no_duplicates_and_leaves_history_alone(): void
    {
        $definition = $this->definition('BACKUP', ['frequency_per_year' => 4]);

        app(OccurrenceGenerator::class)->generate($definition);

        $completed = $definition->occurrences()->orderBy('sequence_no')->first();
        $completed->update([
            'status' => OccurrenceStatus::Completed,
            'actual_start' => now(),
            'outcome' => ExerciseOutcome::Pass->value,
        ]);
        $frozenDate = $completed->refresh()->scheduled_date->toDateString();

        app(OccurrenceGenerator::class)->generate($definition->refresh());
        app(OccurrenceGenerator::class)->generate($definition->refresh());

        $this->assertSame(4, $definition->occurrences()->count(), 'A regeneration must not duplicate.');
        $this->assertSame($frozenDate, $completed->refresh()->scheduled_date->toDateString());
        $this->assertSame(OccurrenceStatus::Completed, $completed->status);
    }

    #[Test]
    public function reducing_the_frequency_removes_the_unrun_surplus_and_keeps_the_run(): void
    {
        $definition = $this->definition('BACKUP', ['frequency_per_year' => 4]);
        app(OccurrenceGenerator::class)->generate($definition);

        $definition->occurrences()->where('sequence_no', 4)
            ->update(['status' => OccurrenceStatus::Completed->value, 'actual_start' => now()]);

        $definition->update(['frequency_per_year' => 2]);
        app(OccurrenceGenerator::class)->generate($definition->refresh());

        // Three: the two the new frequency asks for, and the fourth — which
        // happened, and is therefore evidence rather than a plan.
        $this->assertSame(3, $definition->occurrences()->count());
        $this->assertTrue($definition->occurrences()->where('sequence_no', 4)->exists());
        $this->assertFalse($definition->occurrences()->where('sequence_no', 3)->exists());
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 6 — the ladder warnings.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function a_full_scale_exercise_on_a_process_with_no_tabletop_is_flagged(): void
    {
        $process = $this->process('BCP-PY', 1);

        $definition = $this->definition('FULLSCALE', [
            'frequency_per_year' => 1,
            'process_ids' => [$process->getKey()],
        ]);

        $rules = array_column(app(LadderAdvisor::class)->adviseDefinition($definition), 'rule');

        $this->assertContains('ladder_skipped', $rules);
        $this->assertContains('tier1_never_drilled', $rules);

        // WARNS, NEVER BLOCKS. The exercise is booked, the regulator is
        // watching, and a system that refused to record it is one people work
        // around.
        $this->assertSame(1, app(OccurrenceGenerator::class)->generate($definition)['placed']);
    }

    #[Test]
    public function a_successful_lower_rung_clears_the_ladder_warning(): void
    {
        $process = $this->process('BCP-CORE', 1);

        $tabletop = $this->definition('DRFAILOVER', [
            'frequency_per_year' => 1, 'process_ids' => [$process->getKey()],
        ], 'Prior functional test');

        app(OccurrenceGenerator::class)->generate($tabletop);
        $tabletop->occurrences()->sole()->update([
            'status' => OccurrenceStatus::Completed->value,
            'outcome' => ExerciseOutcome::PassWithFindings->value,
            'actual_end' => now(),
        ]);

        $fullScale = $this->definition('FULLSCALE', [
            'frequency_per_year' => 1, 'process_ids' => [$process->getKey()],
        ], 'The full-scale one');

        $rules = array_column(app(LadderAdvisor::class)->adviseDefinition($fullScale), 'rule');

        // A pass WITH FINDINGS still counts as having run the level —
        // `ExerciseOutcome::satisfiesCadence()` decides that, not this class.
        $this->assertNotContains('ladder_skipped', $rules);
        $this->assertNotContains('tier1_never_drilled', $rules);
    }

    #[Test]
    public function an_exercise_bound_to_no_process_says_so(): void
    {
        $definition = $this->definition('FULLSCALE', ['frequency_per_year' => 1]);

        $this->assertSame(['no_scope'], array_column(app(LadderAdvisor::class)->adviseDefinition($definition), 'rule'));
    }

    #[Test]
    public function the_coverage_matrix_shows_what_has_never_been_exercised(): void
    {
        $tested = $this->process('BCP-CORE', 1);
        $untested = $this->process('BCP-CASH', 1);

        $definition = $this->definition('TABLETOP', [
            'frequency_per_year' => 1, 'process_ids' => [$tested->getKey()],
        ]);
        app(OccurrenceGenerator::class)->generate($definition);
        $definition->occurrences()->sole()->update([
            'status' => OccurrenceStatus::Completed->value,
            'outcome' => ExerciseOutcome::Pass->value,
            'actual_end' => now(),
        ]);

        $matrix = collect(app(LadderAdvisor::class)->coverageMatrix(Process::query()->get()))->keyBy('code');

        $this->assertSame(LadderLevel::Tabletop->value, $matrix['BCP-CORE']['highest_proven']);
        $this->assertFalse($matrix['BCP-CORE']['never_exercised']);
        $this->assertTrue($matrix['BCP-CASH']['never_exercised']);
        $this->assertNull($matrix['BCP-CASH']['highest_proven']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 7 — the year view under load. Measured, not asserted.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_year_view_renders_five_hundred_occurrences_inside_the_nfr(): void
    {
        $definition = $this->definition('ORIENT', ['frequency_per_year' => 1]);

        $rows = [];

        for ($i = 1; $i <= 520; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $this->organization->id,
                'definition_id' => $definition->getKey(),
                'sequence_no' => $i,
                'scheduled_date' => Carbon::create($this->year, ($i % 12) + 1, ($i % 27) + 1)->toDateString(),
                'status' => OccurrenceStatus::Planned->value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ExerciseOccurrence::query()->insert($rows);

        $started = microtime(true);
        $grid = app(CalendarService::class)->year($this->year, null);
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThanOrEqual(520, $grid['count']);
        $this->assertCount(12, $grid['months']);

        // Blueprint §14. Measured on this machine, which is not the customer's
        // — so the margin is what matters, not the number.
        $this->assertLessThan(
            1.5,
            $elapsed,
            sprintf('The year grid took %.3fs for %d occurrences; the NFR is 1.5s.', $elapsed, $grid['count']),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 8 — the compliance view against real occurrences.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_compliance_view_counts_a_cbn_cadence_against_what_was_run(): void
    {
        $definition = $this->definition('DRFAILOVER', ['frequency_per_year' => 4]);
        app(OccurrenceGenerator::class)->generate($definition);

        $definition->occurrences()->orderBy('sequence_no')->limit(3)->get()
            ->each(fn (ExerciseOccurrence $o) => $o->update([
                'status' => OccurrenceStatus::Completed->value,
                'outcome' => ExerciseOutcome::Pass->value,
            ]));

        $row = collect(app(CalendarService::class)->compliance($this->year, null))
            ->firstWhere('driver', 'cbn.open_banking.failover');

        $this->assertNotNull($row, 'The driver comes off the exercise type when the definition names none.');
        $this->assertSame(4, $row['required']);
        $this->assertSame(3, $row['completed']);
        $this->assertSame(1, $row['planned']);
        $this->assertSame(75.0, $row['percentage']);
    }

    #[Test]
    public function asking_for_less_testing_than_a_regulator_does_is_allowed_and_named(): void
    {
        $definition = $this->definition('DRFAILOVER', ['frequency_per_year' => 2]);

        $warnings = app(ExerciseDefinitionService::class)->cadenceWarnings($definition);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('cbn.open_banking.failover', $warnings[0]['message']);
        $this->assertStringContainsString('outside it', $warnings[0]['message']);
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 9 — the ICS feed.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_ics_feed_is_valid_and_its_uids_survive_a_reschedule(): void
    {
        $definition = $this->definition('TABLETOP', [
            'frequency_per_year' => 1, 'facilitator_id' => $this->author->id, 'min_notice_days' => 0,
        ]);
        app(OccurrenceGenerator::class)->generate($definition);

        $occurrence = $definition->occurrences()->sole();
        $occurrence->update(['facilitator_id' => $this->author->id]);

        $feed = app(IcsFeedBuilder::class)->build($this->author);

        $this->assertStringStartsWith('BEGIN:VCALENDAR', $feed);
        $this->assertStringContainsString('END:VCALENDAR', $feed);
        $this->assertStringContainsString('UID:'.$occurrence->uuid.'@nexusrisk', $feed);
        $this->assertStringContainsString('SEQUENCE:0', $feed);
        // RFC 5545 §3.2: lines are CRLF-terminated. Clients are strict about it.
        $this->assertStringContainsString("\r\n", $feed);

        app(RescheduleService::class)->reschedule(
            $occurrence, $occurrence->scheduled_date->copy()->addDays(14)->toDateString(),
            'Moved for the auditors.', $this->author,
        );

        $moved = app(IcsFeedBuilder::class)->build($this->author);

        // THE UID IS UNCHANGED AND THE SEQUENCE HAS INCREMENTED. That pair is
        // what makes Outlook and Google move the appointment instead of
        // creating a second one — regenerating UIDs is the commonest way a feed
        // fills somebody's calendar with duplicates.
        $this->assertStringContainsString('UID:'.$occurrence->uuid.'@nexusrisk', $moved);
        $this->assertStringContainsString('SEQUENCE:1', $moved);
    }

    #[Test]
    public function an_unannounced_exercise_is_not_published_to_anybodys_outlook(): void
    {
        $definition = $this->definition('CALLTREE', [
            'frequency_per_year' => 1, 'unannounced' => true, 'daily_reminder_enabled' => false,
            'facilitator_id' => $this->author->id,
        ]);
        app(OccurrenceGenerator::class)->generate($definition);
        $definition->occurrences()->sole()->update(['facilitator_id' => $this->author->id]);

        // The point of an unannounced call tree test is that nobody knows.
        $this->assertStringNotContainsString('Group call tree', app(IcsFeedBuilder::class)->build($this->author));
    }

    #[Test]
    public function long_lines_are_folded_on_a_character_boundary(): void
    {
        // A Nigerian bank's exercise names carry ₦ and Yoruba diacritics often
        // enough that folding on an octet boundary would split a character in
        // half — which some clients reject outright and others render as
        // mojibake.
        $definition = $this->definition('TABLETOP', [
            'frequency_per_year' => 1, 'facilitator_id' => $this->author->id,
        ], 'Tabletop exercise for the ₦42,000,000 settlement contingency in Ìbàdàn and Ògùn State operations');

        app(OccurrenceGenerator::class)->generate($definition);
        $definition->occurrences()->sole()->update(['facilitator_id' => $this->author->id]);

        $feed = app(IcsFeedBuilder::class)->build($this->author);

        foreach (explode("\r\n", $feed) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'RFC 5545 folds at 75 octets.');
        }

        // Unfolding restores the name intact — no half characters. The commas
        // are backslash-escaped by RFC 5545 §3.3.11, which is why the assertion
        // is against the escaped form rather than the typed one.
        $this->assertStringContainsString('₦42\,000\,000', str_replace("\r\n ", '', $feed));
    }

    /* ------------------------------------------------------------------ */
    /*  Criterion 10 — the event Phase 5 arms from.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function scheduling_an_occurrence_fires_the_event_phase_five_listens_for(): void
    {
        Event::fake([ExerciseOccurrenceScheduled::class]);

        $definition = $this->definition('DRFAILOVER', ['frequency_per_year' => 2]);
        app(OccurrenceGenerator::class)->generate($definition);

        Event::assertDispatched(ExerciseOccurrenceScheduled::class, 2);

        Event::assertDispatched(
            ExerciseOccurrenceScheduled::class,
            function (ExerciseOccurrenceScheduled $event) use ($definition) {
                // It carries the DEFINITION as well as the occurrence: every
                // field the ladder needs to materialise itself lives there, and
                // a listener that re-loaded it would be one lazy-load away from
                // an N+1 across a whole year's generation.
                return $event->definition->is($definition)
                    && $event->occurrence->scheduled_date !== null
                    && $event->definition->lead_time_days !== null;
            },
        );
    }

    #[Test]
    public function regenerating_onto_the_same_dates_re_arms_nothing(): void
    {
        $definition = $this->definition('DRFAILOVER', ['frequency_per_year' => 2]);
        app(OccurrenceGenerator::class)->generate($definition);

        Event::fake([ExerciseOccurrenceScheduled::class]);

        app(OccurrenceGenerator::class)->generate($definition->refresh());

        // Gate G1 requires that a re-run sends nothing twice. This is that
        // property at the source rather than at the dispatcher.
        Event::assertNotDispatched(ExerciseOccurrenceScheduled::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Programme governance.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function approving_a_programme_freezes_what_was_committed_to(): void
    {
        $definition = $this->definition('BACKUP', ['frequency_per_year' => 4]);
        app(OccurrenceGenerator::class)->generate($definition);

        $programme = app(ExerciseProgrammeService::class)->approve($this->programme->refresh(), $this->approver);

        $this->assertSame('approved', $programme->status);
        $this->assertSame(4, (int) $programme->total_planned);

        // The plan is the commitment. Adding an exercise afterwards does not
        // quietly enlarge what the year was measured against — otherwise "we
        // said twelve and ran nine" is unanswerable.
        $second = $this->definition('CALLTREE', ['frequency_per_year' => 4], 'Added later');
        app(OccurrenceGenerator::class)->generate($second);
        app(ExerciseProgrammeService::class)->refreshCounts($programme->refresh());

        $this->assertSame(4, (int) $programme->refresh()->total_planned);
    }

    #[Test]
    public function a_programme_cannot_be_approved_by_its_author_or_while_empty(): void
    {
        $this->definition('BACKUP', ['frequency_per_year' => 1]);

        try {
            app(ExerciseProgrammeService::class)->approve($this->programme, $this->author);
            $this->fail('An author approved their own programme.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('somebody other than its author', $e->getMessage());
        }

        $empty = app(ExerciseProgrammeService::class)->create($this->year + 1, 'Empty', [], $this->author->id);

        $this->expectExceptionMessageMatches('/no active exercise definitions/');
        app(ExerciseProgrammeService::class)->approve($empty, $this->approver);
    }

    #[Test]
    public function completion_rate_is_null_and_not_a_hundred_percent_when_nothing_is_planned(): void
    {
        $summary = app(ExerciseProgrammeService::class)->summary($this->programme);

        $this->assertSame(0, $summary['planned']);
        $this->assertNull($summary['completion_rate']);
    }

    /* ------------------------------------------------------------------ */
    /*  The preview the wizard promises against.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_preview_runs_the_real_generator_and_writes_nothing(): void
    {
        $this->contact('Somebody');

        $definition = $this->definition('DRFAILOVER', [
            'frequency_per_year' => 4,
            'lead_time_days' => 10,
            'default_audience_rule' => AudienceRule::make('org_node', ['id' => $this->unit->id])->toArray(),
        ]);

        $preview = app(ExerciseDefinitionService::class)->preview($definition);

        $this->assertSame(4, $preview['log']['placed']);
        $this->assertSame(1, $preview['audience_size']);
        // 11 daily reminders (T-10 through the day) × 1 person × 4 occurrences.
        $this->assertSame(44, $preview['notification_estimate']['total']);
        $this->assertSame(0, $definition->occurrences()->count(), 'A preview must not write.');

        $generated = app(OccurrenceGenerator::class)->generate($definition);

        // The promise the preview made is the one the generator keeps.
        $this->assertSame(
            array_column($preview['occurrences'], 'date'),
            array_map(fn (array $o) => $o['date'] ?? null, $generated['occurrences']),
        );
    }

    #[Test]
    public function an_unannounced_exercise_counts_only_the_facilitator(): void
    {
        $this->contact('Somebody');

        $definition = $this->definition('CALLTREE', [
            'frequency_per_year' => 4, 'unannounced' => true, 'daily_reminder_enabled' => false,
            'default_audience_rule' => AudienceRule::make('org_node', ['id' => $this->unit->id])->toArray(),
        ]);

        $preview = app(ExerciseDefinitionService::class)->preview($definition);

        $this->assertSame(4, $preview['notification_estimate']['total']);
        $this->assertStringContainsString('Unannounced', $preview['notification_estimate']['basis']);
    }

    /* ------------------------------------------------------------------ */
    /*  Tenancy.
    /* ------------------------------------------------------------------ */

    #[Test]
    public function another_tenants_calendar_is_invisible(): void
    {
        $definition = $this->definition('BACKUP', ['frequency_per_year' => 2]);
        app(OccurrenceGenerator::class)->generate($definition);

        $other = Organization::create([
            'name' => 'Other Bank', 'short_name' => 'OB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        TenantContext::set($other->id);

        $this->assertSame(0, ExerciseOccurrence::query()->count());
        $this->assertSame(0, ExerciseDefinition::query()->count());
        $this->assertSame(0, app(CalendarService::class)->year($this->year, null)['count']);

        TenantContext::set($this->organization->id);

        $this->assertSame(2, ExerciseOccurrence::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function user(string $email): User
    {
        return User::create([
            'name' => Str::title(Str::before($email, '@')), 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->organization->id,
            'business_unit_id' => $this->unit->id ?? null, 'is_active' => true,
        ]);
    }

    private function contact(string $name): Contact
    {
        return Contact::query()->create([
            'organization_id' => $this->organization->id,
            'full_name' => $name,
            'employee_id' => 'E-'.Str::random(8),
            'business_unit_id' => $this->unit->id,
            'source' => 'manual',
            'is_active' => true,
        ]);
    }

    private function process(string $code, int $tier): Process
    {
        return Process::query()->create([
            'code' => $code, 'name' => 'Process '.$code, 'status' => 'active',
            'criticality_tier' => $tier, 'business_unit_id' => $this->unit->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function definition(string $typeCode, array $attributes = [], ?string $name = null): ExerciseDefinition
    {
        $type = ExerciseType::query()->where('code', $typeCode)->sole();

        return app(ExerciseDefinitionService::class)->create(
            $this->programme,
            $type,
            $name ?? $type->name,
            array_merge(['status' => 'active', 'business_unit_id' => $this->unit->id], $attributes),
            $this->author->id,
        );
    }
}
