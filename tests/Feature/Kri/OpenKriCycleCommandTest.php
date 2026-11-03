<?php

namespace Tests\Feature\Kri;

use App\Mail\KriReturnRequested;
use App\Models\KriCycleRun;
use App\Models\KriReturn;
use App\Models\KriReturnLine;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use App\Models\OrganizationHoliday;
use App\Models\Period;
use App\Services\Kri\KriCycleService;
use App\Services\PeriodService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0026 section 12: `kri:open-cycle`.
 *
 * IDEMPOTENCY COMES FROM ROWS, NOT FROM THE CRON: a re-run, a forced run or two
 * servers cannot open a second return (unique key) or send a second request
 * (unique idempotency_key). `--force` never means "send again".
 *
 * The queue is `sync` in the suite and mail is faked, so the real
 * DispatchKriReturnNotice -> SendKriReturnNoticeMail chain runs end to end and
 * what leaves is counted on the fake mailer.
 */
class OpenKriCycleCommandTest extends KriCycleTestCase
{
    /** @return array{0: \App\Models\User, 1: \App\Models\KriDepartmentContact} */
    private function champion(string $name, ?\App\Models\BusinessUnit $unit = null): array
    {
        $user = $this->makeStaff($name, ['kri.submit_return']);

        return [$user, $this->makeContact($unit ?? $this->unit, $user)];
    }

    private function contactFor(\App\Models\User $user, ?\App\Models\BusinessUnit $unit = null): \App\Models\KriDepartmentContact
    {
        return $this->makeContact($unit ?? $this->unit, $user);
    }

    private function counts(): array
    {
        return [
            'runs' => KriCycleRun::query()->count(),
            'returns' => KriReturn::query()->count(),
            'lines' => KriReturnLine::query()->count(),
            'notices' => KriReturnNotice::query()->count(),
            'recipients' => KriReturnNoticeRecipient::query()->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Idempotency */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function three_runs_one_of_them_forced_open_one_return_and_send_one_request_per_recipient(): void
    {
        Mail::fake();

        $this->deptKri();
        $alice = $this->makeStaff('Alice Champion', ['kri.submit_return']);
        $bola = $this->makeStaff('Bola Champion', ['kri.submit_return']);
        $this->contactFor($alice);
        $this->contactFor($bola);

        $this->artisan('kri:open-cycle')->assertExitCode(0);
        $this->artisan('kri:open-cycle')->assertExitCode(0);
        $this->artisan('kri:open-cycle', ['--force' => true])->assertExitCode(0);

        $this->assertSame(1, KriReturn::query()->count(), 'one return per department per period');
        $this->assertSame(1, KriCycleRun::query()->count(), 'a re-run reuses the run row');

        $notices = KriReturnNotice::query()->get();
        $this->assertCount(1, $notices, 'exactly one request notice, ever');
        $this->assertSame('request', $notices[0]->kind);
        $this->assertSame('request:r'.$this->theReturn()->id, $notices[0]->idempotency_key);
        $this->assertSame(0, (int) $notices[0]->sequence);
        $this->assertSame(1, (int) $notices[0]->tier);

        // One row and one email per recipient, however many times the cycle ran.
        $this->assertSame(2, KriReturnNoticeRecipient::query()->count());
        $this->assertSame(2, KriReturnNoticeRecipient::query()->where('status', 'sent')->count());
        Mail::assertSent(KriReturnRequested::class, 2);
        Mail::assertSent(KriReturnRequested::class, fn ($mail) => $mail->hasTo($alice->email));
        Mail::assertSent(KriReturnRequested::class, fn ($mail) => $mail->hasTo($bola->email));

        $this->assertTrue(KriCycleRun::query()->firstOrFail()->forced, 'the forced run is on the record');
        $this->assertSame('completed', KriCycleRun::query()->firstOrFail()->status);
    }

    #[Test]
    public function a_forced_re_run_does_not_overwrite_the_counts_of_the_run_that_did_the_work(): void
    {
        Mail::fake();
        $this->deptKri();
        $this->champion('Alice Champion');

        $this->openCycle();
        $this->openCycle(['--force' => true]);

        $run = KriCycleRun::query()->firstOrFail();
        $this->assertSame(1, (int) $run->returns_opened, 'the second run opened nothing new and must not zero this');
        $this->assertSame(1, (int) $run->requests_queued);
        $this->assertSame(1, (int) $run->departments_due);
    }

    #[Test]
    public function a_forced_re_run_picks_up_a_kri_added_mid_month_without_a_second_return_or_request(): void
    {
        Mail::fake();
        $this->deptKri();
        $this->champion('Alice Champion');

        $this->openCycle();
        $return = $this->theReturn();
        $this->assertSame(1, KriReturnLine::query()->where('kri_return_id', $return->id)->count());
        $this->assertSame(0, (int) $return->lock_version);

        $this->deptKri(null, ['name' => 'Added mid-month']);
        $this->openCycle(['--force' => true]);

        $this->assertSame(1, KriReturn::query()->count());
        $this->assertSame(2, KriReturnLine::query()->where('kri_return_id', $return->id)->count());
        $this->assertSame(1, KriReturnNotice::query()->count(), '--force never means send again');
        Mail::assertSent(KriReturnRequested::class, 1);
        $this->assertSame(1, (int) $return->fresh()->lock_version, 'a sync that changes lines is a write another editor must notice (A1.8)');
    }

    #[Test]
    public function a_request_stuck_queued_is_re_queued_by_a_re_run_and_still_sent_once(): void
    {
        // The first run committed the notice and then lost the queue.
        Queue::fake();
        $this->deptKri();
        [$user] = $this->champion('Alice Champion');

        $this->openCycle();
        $notice = KriReturnNotice::query()->firstOrFail();
        $this->assertSame('queued', $notice->status);
        Queue::assertPushed(\App\Jobs\DispatchKriReturnNotice::class, 1);

        // The queue is back. A re-run hands the SAME row back; it does not write a second.
        Queue::fake();
        $this->openCycle(['--force' => true]);

        Queue::assertPushed(\App\Jobs\DispatchKriReturnNotice::class, fn ($job) => $job->noticeId === $notice->id);
        $this->assertSame(1, KriReturnNotice::query()->count());
    }

    #[Test]
    public function the_notice_is_persisted_before_it_is_handed_to_the_queue(): void
    {
        // Write-ahead: with the queue swallowing everything, the row must already exist.
        Queue::fake();
        $this->deptKri();
        $this->champion('Alice Champion');

        $this->openCycle();

        $notice = KriReturnNotice::query()->firstOrFail();
        $this->assertSame('queued', $notice->status);
        $this->assertNull($notice->recipients_resolved_at, 'recipients are resolved at send time, not at request time');
        $this->assertSame(0, KriReturnNoticeRecipient::query()->count());
        Queue::assertPushed(\App\Jobs\DispatchKriReturnNotice::class, fn ($job) => $job->noticeId === $notice->id && $job->queue === 'notifications');
    }

    /* ------------------------------------------------------------------ */
    /*  What the return collects */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_return_collects_the_month_just_ended_and_falls_due_five_working_days_later(): void
    {
        Mail::fake();
        $this->deptKri();

        $this->openCycle();

        $return = $this->theReturn();
        $this->assertSame('2026-10-01', $return->period->start_date->toDateString());
        $this->assertSame('2026-10-31', $return->period->end_date->toDateString(), 'opened in November, collects October');
        $this->assertSame('2026-11-02', $return->requested_on->toDateString());
        $this->assertSame('2026-11-09', $return->due_on->toDateString(), 'Monday 2 Nov + 5 working days = Monday 9 Nov');
        $this->assertSame('draft', $return->status->value);
        $this->assertNotNull($return->opened_at);
        $this->assertNull($return->submitted_at);
        $this->assertSame(0, (int) $return->lock_version);
    }

    #[Test]
    public function a_holiday_in_the_window_moves_the_due_date(): void
    {
        Mail::fake();
        $this->deptKri();
        TenantContext::bypass(fn () => OrganizationHoliday::create([
            'organization_id' => $this->organization->id, 'holiday_date' => '2026-11-04', 'name' => 'Mid-week holiday',
        ]), 'test fixture');

        $this->openCycle();

        $this->assertSame('2026-11-10', $this->theReturn()->due_on->toDateString());
    }

    #[Test]
    public function only_the_kris_that_are_due_become_lines(): void
    {
        Mail::fake();

        $monthly = $this->deptKri(null, ['name' => 'Monthly', 'measurement_frequency' => 'monthly']);
        $daily = $this->deptKri(null, ['name' => 'Daily', 'measurement_frequency' => 'daily']);
        $weekly = $this->deptKri(null, ['name' => 'Weekly', 'measurement_frequency' => 'weekly']);
        $quarterly = $this->deptKri(null, ['name' => 'Quarterly', 'measurement_frequency' => 'quarterly']);
        $automated = $this->deptKri(null, ['name' => 'Published by a module', 'is_automated' => true]);
        $inactive = $this->deptKri(null, ['name' => 'Retired', 'is_active' => false]);
        $deleted = $this->deptKri(null, ['name' => 'Deleted']);
        $deleted->delete();
        $unassigned = $this->deptKri(null, ['name' => 'No department', 'business_unit_id' => null]);

        $child = $this->makeUnit('Treasury Desk', 'TRS-D', null, $this->unit->id);
        $childKri = $this->deptKri($child, ['name' => 'Desk indicator']);

        $this->openCycle();

        $parentLines = KriReturnLine::query()->where('kri_return_id', $this->theReturn()->id)->pluck('key_risk_indicator_id')->all();
        sort($parentLines);
        $expected = [$monthly->id, $daily->id, $weekly->id];
        sort($expected);

        $this->assertSame($expected, $parentLines, 'monthly, daily and weekly are due every month');
        $this->assertNotContains($quarterly->id, $parentLines, 'a quarterly KRI is not due in October (its quarter ends in December)');
        $this->assertNotContains($automated->id, $parentLines, 'an automated KRI has a publisher as its writer');
        $this->assertNotContains($inactive->id, $parentLines);
        $this->assertNotContains($deleted->id, $parentLines);
        $this->assertNotContains($unassigned->id, $parentLines);
        $this->assertNotContains($childKri->id, $parentLines, 'no roll-up of a child unit into its parent');

        $childLines = KriReturnLine::query()->where('kri_return_id', $this->theReturn($child)->id)->pluck('key_risk_indicator_id')->all();
        $this->assertSame([$childKri->id], $childLines);
        $this->assertSame(2, KriReturn::query()->count(), 'a department with no due KRI gets no return');
    }

    #[Test]
    public function a_department_with_no_due_kri_gets_no_return_and_is_not_in_the_denominator(): void
    {
        Mail::fake();
        $this->makeUnit('Empty Unit', 'EMP');
        $this->deptKri();

        $this->openCycle();

        $this->assertSame(1, KriReturn::query()->count());
        $this->assertSame(1, (int) KriCycleRun::query()->firstOrFail()->departments_due);
    }

    #[Test]
    public function a_quarterly_kri_is_due_only_when_its_quarter_ends_in_the_reporting_month(): void
    {
        $quarterly = $this->deptKri(null, ['measurement_frequency' => 'quarterly']);
        $service = app(KriCycleService::class);
        $periods = app(PeriodService::class);

        $september = $periods->resolve('2026-09-30', 'month', $this->organization->id);
        $october = $periods->resolve('2026-10-31', 'month', $this->organization->id);

        $inSeptember = $service->dueKrisFor($this->unit, $september);
        $this->assertSame([$quarterly->id], $inSeptember->pluck('kri.id')->all(), 'Q3 ends 30 September');
        $this->assertSame('quarter', $inSeptember->first()['period']->type, 'its line carries the QUARTER, not the month');
        $this->assertSame('2026-09-30', $inSeptember->first()['period']->end_date->toDateString());

        $this->assertSame([], $service->dueKrisFor($this->unit, $october)->pluck('kri.id')->all());
    }

    #[Test]
    public function the_cycle_on_the_first_working_day_of_october_collects_september_with_the_quarterly_kri(): void
    {
        Mail::fake();
        $quarterly = $this->deptKri(null, ['measurement_frequency' => 'quarterly']);
        $monthly = $this->deptKri();

        // Thursday 1 October 2026 is the first working day.
        $this->artisan('kri:open-cycle', ['--date' => '2026-10-01'])->assertExitCode(0);

        $return = KriReturn::query()->firstOrFail();
        $this->assertSame('2026-09-30', $return->period->end_date->toDateString());
        $this->assertSame('2026-10-08', $return->due_on->toDateString());
        $lines = KriReturnLine::query()->where('kri_return_id', $return->id)->get();
        $this->assertEqualsCanonicalizing([$quarterly->id, $monthly->id], $lines->pluck('key_risk_indicator_id')->all());
        $this->assertSame('quarter', $lines->firstWhere('key_risk_indicator_id', $quarterly->id)->period->type);
        $this->assertSame('month', $lines->firstWhere('key_risk_indicator_id', $monthly->id)->period->type);

        // The next month is a different return under the same unit.
        $this->artisan('kri:open-cycle', ['--date' => '2026-11-02'])->assertExitCode(0);
        $this->assertSame(2, KriReturn::query()->count());
        $this->assertSame(2, KriCycleRun::query()->count(), 'one run row per organisation per period');
    }

    #[Test]
    public function a_department_without_a_champion_is_counted_and_its_request_ends_with_no_recipients(): void
    {
        Mail::fake();
        $this->deptKri();

        $this->openCycle();

        $this->assertSame(1, (int) KriCycleRun::query()->firstOrFail()->departments_without_champion);
        $this->assertSame('no_recipients', KriReturnNotice::query()->firstOrFail()->status);
        Mail::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /*  First working day, in Lagos */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function without_force_it_does_nothing_on_a_day_that_is_not_the_first_working_day(): void
    {
        Mail::fake();
        $this->deptKri();
        $before = $this->counts();

        // Tuesday 3 Nov: the first working day was Monday 2 Nov.
        Artisan::call('kri:open-cycle', ['--date' => '2026-11-03']);

        $this->assertStringContainsString('not the first working day (2026-11-02)', Artisan::output());
        $this->assertSame($before, $this->counts());
        Mail::assertNothingSent();
    }

    #[Test]
    public function a_sunday_the_first_is_not_the_first_working_day(): void
    {
        Mail::fake();
        $this->deptKri();

        Artisan::call('kri:open-cycle', ['--date' => '2026-11-01']);

        $this->assertStringContainsString('not the first working day (2026-11-02)', Artisan::output());
        $this->assertSame(0, KriReturn::query()->count());
    }

    #[Test]
    public function when_the_first_is_a_holiday_the_next_working_day_opens_the_cycle(): void
    {
        Mail::fake();
        $this->deptKri();
        // Mon 2 Nov is a holiday, so Tue 3 Nov is the first working day.
        TenantContext::bypass(fn () => OrganizationHoliday::create([
            'organization_id' => $this->organization->id, 'holiday_date' => '2026-11-02', 'name' => 'Holiday',
        ]), 'test fixture');

        Artisan::call('kri:open-cycle', ['--date' => '2026-11-02']);
        $this->assertStringContainsString('not the first working day (2026-11-03)', Artisan::output());
        $this->assertSame(0, KriReturn::query()->count());

        $this->artisan('kri:open-cycle', ['--date' => '2026-11-03'])->assertExitCode(0);
        $this->assertSame(1, KriReturn::query()->count());
        $this->assertSame('2026-11-03', $this->theReturn()->requested_on->toDateString());
        $this->assertSame('2026-11-10', $this->theReturn()->due_on->toDateString());
    }

    #[Test]
    public function a_holiday_on_a_monday_the_first_moves_the_cycle_to_tuesday(): void
    {
        Mail::fake();
        $this->deptKri();
        // 1 June 2026 is a Monday.
        TenantContext::bypass(fn () => OrganizationHoliday::create([
            'organization_id' => $this->organization->id, 'holiday_date' => '2026-06-01', 'name' => 'Public holiday',
        ]), 'test fixture');

        Artisan::call('kri:open-cycle', ['--date' => '2026-06-01']);
        $this->assertStringContainsString('not the first working day (2026-06-02)', Artisan::output());
        $this->assertSame(0, KriReturn::query()->count());

        $this->artisan('kri:open-cycle', ['--date' => '2026-06-02'])->assertExitCode(0);

        $return = KriReturn::query()->firstOrFail();
        $this->assertSame('2026-05-31', $return->period->end_date->toDateString(), 'June opens a May return');
    }

    #[Test]
    public function force_opens_on_any_day_and_the_due_date_counts_from_that_day(): void
    {
        Mail::fake();
        $this->deptKri();

        $this->artisan('kri:open-cycle', ['--date' => '2026-11-04', '--force' => true])->assertExitCode(0);

        $return = $this->theReturn();
        $this->assertSame('2026-11-04', $return->requested_on->toDateString());
        $this->assertSame('2026-11-11', $return->due_on->toDateString());
        $this->assertSame('2026-10-31', $return->period->end_date->toDateString(), 'still the month just ended');
        $this->assertSame('manual', KriCycleRun::query()->firstOrFail()->trigger);
    }

    #[Test]
    public function with_no_date_it_acts_on_the_lagos_day_not_the_utc_day(): void
    {
        Mail::fake();
        $this->deptKri();

        // 23:30 UTC on Sunday 1 Nov is 00:30 on Monday 2 Nov in Lagos: the first working day.
        $this->freezeAt('2026-11-01 23:30:00');
        $this->artisan('kri:open-cycle')->assertExitCode(0);

        $this->assertSame(1, KriReturn::query()->count(), 'Lagos is already on the first working day');
        $this->assertSame('2026-11-02', $this->theReturn()->requested_on->toDateString());
        $this->assertSame('schedule', KriCycleRun::query()->firstOrFail()->trigger);
    }

    #[Test]
    public function with_no_date_it_waits_while_it_is_still_the_previous_day_in_lagos(): void
    {
        Mail::fake();
        $this->deptKri();

        // 22:30 UTC on Sunday 1 Nov is 23:30 on Sunday in Lagos.
        $this->freezeAt('2026-11-01 22:30:00');
        $this->artisan('kri:open-cycle')->assertExitCode(0);

        $this->assertSame(0, KriReturn::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /*  --dry-run, --date, --organization */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function dry_run_reports_and_writes_nothing_and_sends_nothing(): void
    {
        Mail::fake();
        Queue::fake();
        $this->deptKri();
        $this->deptKri(null, ['name' => 'Second']);
        $this->champion('Alice Champion');
        $withoutChampion = $this->makeUnit('Operations', 'OPS');
        $this->deptKri($withoutChampion);

        $before = $this->counts();
        $periodsBefore = Period::withoutGlobalScopes()->count();

        $this->assertSame(0, Artisan::call('kri:open-cycle', ['--dry-run' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('Treasury: 2 KRIs due; champions: Alice Champion', $output);
        $this->assertStringContainsString('Operations: 1 KRIs due; champions: NO CHAMPION', $output);

        $this->assertSame($before, $this->counts(), 'no run row, no return, no line, no notice');
        $this->assertSame($periodsBefore, Period::withoutGlobalScopes()->count(), 'not even the lazily generated calendar survives');
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function dry_run_on_a_day_that_is_not_the_first_working_day_still_skips(): void
    {
        $this->deptKri();

        Artisan::call('kri:open-cycle', ['--dry-run' => true, '--date' => '2026-11-03']);

        $this->assertStringContainsString('not the first working day', Artisan::output());
    }

    /** @return array<string, array{0: string}> */
    public static function badDates(): array
    {
        return [
            'impossible day' => ['2026-02-30'],
            'trailing junk' => ['2026-11-02xyz'],
            'words' => ['tomorrow'],
            'day first' => ['02/11/2026'],
            'timestamp' => ['2026-11-02 07:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('badDates')]
    public function date_is_strict_and_a_bad_value_writes_nothing(string $date): void
    {
        Mail::fake();
        $this->deptKri();
        $before = $this->counts();

        // Symfony's Command::INVALID.
        $this->artisan('kri:open-cycle', ['--date' => $date, '--force' => true])->assertExitCode(2);

        $this->assertSame($before, $this->counts());
        Mail::assertNothingSent();
    }

    #[Test]
    public function organization_must_be_an_id_and_must_exist(): void
    {
        $this->deptKri();

        $this->artisan('kri:open-cycle', ['--organization' => 'abc'])->assertExitCode(2);
        $this->artisan('kri:open-cycle', ['--organization' => '0'])->assertExitCode(2);
        $this->artisan('kri:open-cycle', ['--organization' => '9999999'])->assertExitCode(1);

        $this->assertSame(0, KriReturn::query()->count());
    }

    #[Test]
    public function the_cycle_opens_for_each_tenant_inside_that_tenant_and_never_across(): void
    {
        Mail::fake();

        $this->deptKri();
        $theirUnit = $this->foreignDepartment();
        $theirKri = $this->deptKri($theirUnit, ['organization_id' => $this->otherOrg->id, 'risk_id' => $this->foreignRisk->id, 'name' => 'Theirs']);
        app(PeriodService::class)->ensureCalendar($this->otherOrg->id);

        $this->openCycle();

        $mine = KriReturn::withoutGlobalScopes()->where('organization_id', $this->organization->id)->get();
        $theirs = KriReturn::withoutGlobalScopes()->where('organization_id', $this->otherOrg->id)->get();

        $this->assertCount(1, $mine);
        $this->assertCount(1, $theirs);
        $this->assertSame($this->unit->id, (int) $mine[0]->business_unit_id);
        $this->assertSame($theirUnit->id, (int) $theirs[0]->business_unit_id);

        $theirLines = KriReturnLine::withoutGlobalScopes()->where('kri_return_id', $theirs[0]->id)->pluck('key_risk_indicator_id')->all();
        $this->assertSame([$theirKri->id], $theirLines, "another tenant's return holds only that tenant's KRIs");
        $this->assertSame(2, KriCycleRun::withoutGlobalScopes()->whereIn('organization_id', [$this->organization->id, $this->otherOrg->id])->count(), 'one run per organisation');
        $this->assertSame($this->otherOrg->id, (int) $theirs[0]->period()->withoutGlobalScopes()->first()->organization_id, "their return is on THEIR calendar's period");
    }

    #[Test]
    public function organization_limits_the_run_to_one_tenant(): void
    {
        Mail::fake();

        $this->deptKri();
        $theirUnit = $this->foreignDepartment();
        $this->deptKri($theirUnit, ['organization_id' => $this->otherOrg->id, 'risk_id' => $this->foreignRisk->id]);

        $this->artisan('kri:open-cycle', ['--organization' => (string) $this->organization->id])->assertExitCode(0);

        $this->assertSame(1, KriReturn::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, KriReturn::withoutGlobalScopes()->where('organization_id', $this->otherOrg->id)->count());
    }

    #[Test]
    public function the_command_leaves_no_tenant_resolved_behind_it(): void
    {
        Mail::fake();
        $this->deptKri();
        TenantContext::clear();

        $this->artisan('kri:open-cycle')->assertExitCode(0);

        $this->assertNull(TenantContext::organizationIdOrNull(), 'a command that wraps each tenant in actingAs() must restore what it found');
        $this->assertSame(1, KriReturn::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Wiring */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function both_commands_are_scheduled_daily_never_monthly_with_overlap_and_server_guards(): void
    {
        Artisan::call('list');   // booting the console kernel loads routes/console.php

        $events = collect(app(Schedule::class)->events())->mapWithKeys(function (Event $e) {
            $command = trim((string) preg_replace('/^.*?artisan[\'"]?\s+/', '', (string) $e->command));

            return [$command => $e];
        });

        foreach ([
            'kri:open-cycle' => '0 7 * * *',
            'kri:cycle-watchdog' => '0 10 * * *',
        ] as $command => $cron) {
            $this->assertTrue($events->has($command), "{$command} is not scheduled");

            /** @var Event $event */
            $event = $events[$command];

            $this->assertSame($cron, $event->expression, "{$command} must run DAILY: the 1st is often not a working day, so monthlyOn(1) is wrong");
            $this->assertSame('Africa/Lagos', (string) $event->timezone, "{$command} is a Lagos wall-clock time");
            $this->assertTrue($event->withoutOverlapping, "{$command} withoutOverlapping");
            $this->assertTrue($event->onOneServer, "{$command} onOneServer");
            $this->assertSame(60, $event->expiresAt, "{$command}: a once-a-day command must not keep a 24-hour mutex");
        }
    }

    #[Test]
    public function the_command_warns_when_the_mailer_is_the_log_driver(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->deptKri();

        $this->artisan('kri:open-cycle')
            ->expectsOutputToContain('MAIL_MAILER is `log`')
            ->assertExitCode(0);
    }
}
