<?php

namespace Tests\Feature\Kri;

use App\Jobs\DispatchKriReturnNotice;
use App\Jobs\SendKriReturnNoticeMail;
use App\Models\KriCycleRun;
use App\Models\KriReturnNotice;
use App\Models\KriReturnNoticeRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR 0026 section 12: `kri:cycle-watchdog`. A scheduled command nothing runs is
 * code that has never executed, so every branch is invoked here.
 *
 * Clock: Monday 2 Nov 2026, 07:00 Lagos - the first working day, reporting October.
 */
class KriCycleWatchdogTest extends KriCycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deptKri();
    }

    private function holder(): \App\Models\User
    {
        return $this->makeStaff('Reminder Holder', ['kri.send_reminder']);
    }

    private function completedRun(): KriCycleRun
    {
        return KriCycleRun::create([
            'organization_id' => $this->organization->id, 'period_id' => $this->reportingMonth()->id, 'run_on' => '2026-11-02',
            'trigger' => 'schedule', 'forced' => false, 'status' => 'completed', 'started_at' => now(),
        ]);
    }

    private function notice(string $status, array $overrides = []): KriReturnNotice
    {
        static $n = 0;
        $return = $this->makeReturn(null, [], false);
        $n++;

        return KriReturnNotice::create(array_merge([
            'organization_id' => $this->organization->id, 'kri_return_id' => $return->id, 'business_unit_id' => $this->unit->id,
            'kind' => 'request', 'sequence' => 0, 'tier' => 1, 'idempotency_key' => "request:wd{$n}",
            'sent_on' => '2026-11-02', 'status' => $status, 'queued_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function an_organisation_with_no_departmental_kri_has_nothing_to_watch(): void
    {
        \App\Models\KeyRiskIndicator::query()->update(['business_unit_id' => null]);

        $this->artisan('kri:cycle-watchdog')->expectsOutputToContain('healthy')->assertExitCode(0);
    }

    #[Test]
    public function it_fails_loudly_when_the_cycle_did_not_open_on_or_after_the_first_working_day(): void
    {
        Log::spy();
        $holder = $this->holder();

        $this->artisan('kri:cycle-watchdog')
            ->expectsOutputToContain('has not completed')
            ->expectsOutputToContain('kri:open-cycle')
            ->assertExitCode(1);

        // The in-app alert reaches the holders of kri.send_reminder, one row each, not through the queue.
        $this->assertSame(1, DB::table('notifications_log')->where('type', 'kri_cycle_watchdog')->where('user_id', $holder->id)->count());
        Log::shouldHaveReceived('critical')->withArgs(fn ($message, $context) => ($context['support_alert'] ?? false) === true);
    }

    #[Test]
    public function it_is_quiet_once_the_cycle_has_completed(): void
    {
        $this->completedRun();

        $this->artisan('kri:cycle-watchdog')->expectsOutputToContain('healthy')->assertExitCode(0);
        $this->assertSame(0, DB::table('notifications_log')->where('type', 'kri_cycle_watchdog')->count());
    }

    #[Test]
    public function a_failed_run_is_not_a_completed_run(): void
    {
        $this->completedRun()->forceFill(['status' => 'failed'])->save();

        $this->artisan('kri:cycle-watchdog')->assertExitCode(1);
    }

    #[Test]
    public function the_real_command_makes_the_watchdog_go_green(): void
    {
        Mail::fake();
        $this->artisan('kri:cycle-watchdog')->assertExitCode(1);

        $this->artisan('kri:open-cycle')->assertExitCode(0);

        $this->artisan('kri:cycle-watchdog')->assertExitCode(0);
    }

    #[Test]
    public function before_the_first_working_day_a_missing_run_is_not_yet_a_failure(): void
    {
        $this->freezeAt('2026-11-01 12:00:00');   // Sunday 1 Nov in Lagos

        $this->artisan('kri:cycle-watchdog')->assertExitCode(0);
    }

    #[Test]
    public function a_run_stuck_running_for_over_an_hour_is_a_failure(): void
    {
        $run = $this->completedRun();
        $run->forceFill(['status' => 'running', 'started_at' => now()->subMinutes(90)])->save();

        $this->artisan('kri:cycle-watchdog')->expectsOutputToContain('running for more than 60 minutes')->assertExitCode(1);

        $run->forceFill(['started_at' => now()->subMinutes(10)])->save();
        $this->artisan('kri:cycle-watchdog')->assertExitCode(1);   // still no COMPLETED run for the period
    }

    #[Test]
    public function a_notice_queued_for_over_an_hour_with_no_recipients_resolved_is_a_failure_and_redispatch_repairs_it(): void
    {
        $this->completedRun();
        $stuck = $this->notice('queued', ['queued_at' => now()->subHours(2)]);

        $this->artisan('kri:cycle-watchdog')->expectsOutputToContain('queued for more than 60 minutes')->assertExitCode(1);

        Queue::fake();
        $this->artisan('kri:cycle-watchdog', ['--redispatch' => true])->assertExitCode(1);   // reports, then repairs
        Queue::assertPushed(DispatchKriReturnNotice::class, fn ($job) => $job->noticeId === $stuck->id);
    }

    #[Test]
    public function a_notice_queued_only_minutes_ago_is_not_stuck(): void
    {
        $this->completedRun();
        $this->notice('queued', ['queued_at' => now()->subMinutes(5)]);

        $this->artisan('kri:cycle-watchdog')->assertExitCode(0);
    }

    #[Test]
    public function a_dispatching_notice_with_recipients_still_pending_after_an_hour_is_a_failure_and_redispatch_re_queues_the_sends(): void
    {
        $this->completedRun();
        $notice = $this->notice('dispatching', ['recipients_resolved_at' => now()->subHours(2)]);
        $recipient = KriReturnNoticeRecipient::create([
            'organization_id' => $this->organization->id, 'kri_return_notice_id' => $notice->id, 'user_id' => $this->actor->id,
            'email' => 'a@example.test', 'name' => 'A', 'contact_role' => 'risk_champion', 'status' => 'pending',
        ]);

        $this->artisan('kri:cycle-watchdog')->expectsOutputToContain('still have recipients waiting')->assertExitCode(1);

        Queue::fake();
        $this->artisan('kri:cycle-watchdog', ['--redispatch' => true])->assertExitCode(1);
        Queue::assertPushed(SendKriReturnNoticeMail::class, fn ($job) => $job->recipientId === $recipient->id);
    }

    #[Test]
    public function recently_failed_recipients_warn_but_do_not_fail(): void
    {
        $this->completedRun();
        $notice = $this->notice('partially_failed');
        KriReturnNoticeRecipient::create([
            'organization_id' => $this->organization->id, 'kri_return_notice_id' => $notice->id, 'user_id' => $this->actor->id,
            'email' => 'a@example.test', 'name' => 'A', 'contact_role' => 'risk_champion', 'status' => 'failed', 'failed_at' => now()->subHours(3),
        ]);

        $this->artisan('kri:cycle-watchdog')->expectsOutputToContain('failed in the last 24 hours')->assertExitCode(0);
    }

    #[Test]
    public function one_tenants_problem_is_not_another_tenants_failure(): void
    {
        // This tenant is healthy; the other has a KRI but never opened.
        $this->completedRun();
        $theirUnit = $this->foreignDepartment();
        $this->deptKri($theirUnit, ['organization_id' => $this->otherOrg->id, 'risk_id' => $this->foreignRisk->id]);
        \Illuminate\Support\Facades\DB::table('organizations')->where('id', $this->otherOrg->id)->update(['is_active' => true]);

        $this->artisan('kri:cycle-watchdog', ['--organization' => (string) $this->organization->id])->assertExitCode(0);
        $this->artisan('kri:cycle-watchdog', ['--organization' => (string) $this->otherOrg->id])->assertExitCode(1);
        $this->assertSame(0, DB::table('notifications_log')->where('type', 'kri_cycle_watchdog')->where('organization_id', $this->organization->id)->count());
    }

    #[Test]
    public function the_organization_option_is_validated(): void
    {
        $this->artisan('kri:cycle-watchdog', ['--organization' => 'abc'])->assertExitCode(2);
        $this->artisan('kri:cycle-watchdog', ['--organization' => '9999999'])->assertExitCode(1);
    }
}
