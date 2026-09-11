<?php

namespace Tests\Feature\Tprm;

use App\Enums\Tprm\EngagementStatus;
use App\Mail\Tprm\ScheduledReport;
use App\Models\Organization;
use App\Models\RiskCategory;
use App\Models\Tprm\Engagement;
use App\Models\Tprm\ReportSchedule;
use App\Models\Tprm\ThirdParty;
use App\Models\User;
use App\Services\Tprm\Reporting\ScheduledReportDispatcher;
use Carbon\CarbonImmutable;
use Database\Seeders\Tprm\TprmReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * FR-RPT-09 — schedulable on a cron with a recipient list.
 *
 * THE TESTS THAT MATTER MOST ARE THE REFUSALS. A standing instruction to email
 * data out of the institution outlives the person who set it up, and the two
 * ways it goes wrong are both silent: it keeps sending after its owner has
 * left or lost the permission, or it stops sending and nobody notices. Both
 * are pinned here, and both are recorded on the row rather than in a log so
 * the screen can show them.
 *
 * The second theme is idempotence. A dispatcher re-run by hand after a deploy
 * must not put a second copy of a screening log in twelve external inboxes.
 */
class ReportSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $bank;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.tprm', true);

        $this->bank = Organization::create([
            'name' => 'Owerri Savings Bank', 'short_name' => 'OSB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);

        RiskCategory::create([
            'organization_id' => $this->bank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($this->bank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($this->bank->id);

        $this->owner = $this->user('Schedule Owner', 'owner@osb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.report.export',
            'tprm.assessment.view', 'tprm.contract.view', 'tprm.screening.view',
        ]);

        $this->engagement('ENG-1');

        Mail::fake();
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /* ================================================================== */
    /*  When a schedule is due */
    /* ================================================================== */

    #[Test]
    public function a_weekly_schedule_fires_on_its_day_and_not_on_others(): void
    {
        $schedule = $this->schedule(['frequency' => 'weekly', 'day_of_week' => 3]);

        $this->assertTrue($schedule->isDueOn(CarbonImmutable::parse('2027-01-06')));  // Wednesday
        $this->assertFalse($schedule->isDueOn(CarbonImmutable::parse('2027-01-07'))); // Thursday
    }

    #[Test]
    public function a_monthly_schedule_cannot_be_set_past_day_28(): void
    {
        // A schedule set for the 31st silently skips four months a year, and
        // nobody notices an email that did not arrive.
        $this->actingAs($this->owner)
            ->post(route('tprm.reports.schedules.store'), $this->payload([
                'frequency' => 'monthly', 'day_of_month' => 31,
            ]))
            ->assertSessionHasErrors('day_of_month');

        $this->actingAs($this->owner)
            ->post(route('tprm.reports.schedules.store'), $this->payload([
                'frequency' => 'monthly', 'day_of_month' => 28,
            ]))
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_schedule_already_sent_today_does_not_send_again(): void
    {
        $schedule = $this->schedule(['frequency' => 'daily']);

        app(ScheduledReportDispatcher::class)->run($schedule, CarbonImmutable::parse('2027-01-06 08:00'));

        // Re-running the dispatcher after a deploy must not put a second copy
        // in twelve external inboxes.
        $this->assertFalse($schedule->refresh()->isDueOn(CarbonImmutable::parse('2027-01-06 12:00')));
        $this->assertTrue($schedule->isDueOn(CarbonImmutable::parse('2027-01-07 08:00')));
    }

    #[Test]
    public function a_paused_schedule_is_never_due(): void
    {
        $schedule = $this->schedule(['frequency' => 'daily', 'is_active' => false]);

        $this->assertFalse($schedule->isDueOn(CarbonImmutable::now()));
    }

    /* ================================================================== */
    /*  Sending */
    /* ================================================================== */

    #[Test]
    public function a_due_schedule_emails_the_report_to_its_recipients(): void
    {
        $schedule = $this->schedule([
            'frequency' => 'daily',
            'recipients' => ['procurement@osb.example', 'cosec@outsourced.example'],
        ]);

        $totals = app(ScheduledReportDispatcher::class)->dispatchDue(CarbonImmutable::parse('2027-01-06'));

        $this->assertSame(1, $totals['sent']);

        Mail::assertSent(ScheduledReport::class, function (ScheduledReport $mail) {
            return $mail->hasTo('procurement@osb.example')
                && $mail->hasTo('cosec@outsourced.example')
                // The owner is named so a recipient who does not recognise the
                // report knows who to ask.
                && $mail->ownerName === 'Schedule Owner';
        });

        $schedule->refresh();
        $this->assertSame(ReportSchedule::STATUS_SUCCEEDED, $schedule->last_run_status);
        $this->assertSame(0, $schedule->consecutive_failures);
    }

    /**
     * Gate 1 (TPRM Phase 10), defect 1. `dispatchDue()` already iterates
     * `TenantContext::actingAs()` per organization, but nothing tested that
     * a SECOND tenant's own schedule and engagements produce a report about
     * only that tenant — a report emailed to one bank's procurement mailbox
     * with a row count that grew because another bank's engagements were
     * counted in the same run would be silent to every existing test here,
     * which builds one tenant only.
     */
    #[Test]
    public function each_tenants_scheduled_report_counts_only_its_own_rows(): void
    {
        // setUp() already gave this bank one engagement (ENG-1) and this
        // schedule now makes it due.
        $this->schedule(['frequency' => 'daily']);

        $otherBank = Organization::create([
            'name' => 'Gombe Frontier Bank', 'short_name' => 'GFB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
        RiskCategory::create([
            'organization_id' => $otherBank->id,
            'code' => 'OR', 'name' => 'Operational Risk', 'level' => 1, 'is_active' => true,
        ]);

        TenantContext::set($otherBank->id);
        $this->seed(TprmReferenceSeeder::class);
        TenantContext::set($otherBank->id);

        $otherOwner = User::create([
            'name' => 'Their Schedule Owner', 'email' => 'owner@gfb.test',
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $otherBank->id, 'is_active' => true,
        ]);
        $role = Role::findOrCreate('gfb-scheduler', 'web');
        foreach (['tprm.view', 'tprm.report.view', 'tprm.report.export', 'tprm.assessment.view'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $otherOwner->assignRole($role);

        // Two engagements for the other bank — if a leak folded them into
        // this bank's count, ENG-1's report would show 3 rows, not 1.
        foreach (['ENG-OTHER-1', 'ENG-OTHER-2'] as $reference) {
            $vendor = ThirdParty::create([
                'organization_id' => $otherBank->id,
                'legal_name' => $reference.' provider',
                'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
            ]);
            $engagement = Engagement::create([
                'organization_id' => $otherBank->id,
                'third_party_id' => $vendor->id,
                'reference' => $reference,
                'name' => 'Service for '.$reference,
                'engagement_type' => 'ict_service',
            ]);
            $engagement->forceFill(['status' => EngagementStatus::Active->value])->save();
        }

        ReportSchedule::create([
            'organization_id' => $otherBank->id,
            'report_key' => 'assessment-status',
            'name' => 'Their schedule',
            'frequency' => 'daily',
            'send_at' => '07:00',
            'format' => 'xlsx',
            'recipients' => ['risk@gfb.example'],
            'owner_id' => $otherOwner->id,
            'is_active' => true,
        ]);

        // Back to the tenant under test before dispatching — dispatchDue()
        // itself switches tenant per organization, but the schedules must
        // exist under their own organization_id regardless of which context
        // is active when dispatchDue() is called.
        TenantContext::set($this->bank->id);

        $totals = app(ScheduledReportDispatcher::class)->dispatchDue(CarbonImmutable::parse('2027-01-06'));

        $this->assertSame(2, $totals['sent'], 'Both tenants\' due schedules should have run.');

        $rowCounts = [];
        Mail::assertSent(ScheduledReport::class, 2);
        Mail::assertSent(ScheduledReport::class, function (ScheduledReport $mail) use (&$rowCounts) {
            $rowCounts[$mail->organizationName] = $mail->rowCount;

            return true;
        });

        $this->assertSame(1, $rowCounts['Owerri Savings Bank'] ?? null, 'This bank\'s report picked up rows from another tenant.');
        $this->assertSame(2, $rowCounts['Gombe Frontier Bank'] ?? null);
    }

    #[Test]
    public function a_schedule_whose_owner_lost_the_permission_stops_and_says_why(): void
    {
        $schedule = $this->schedule(['frequency' => 'daily', 'report_key' => 'screening-log']);

        // The owner's access is withdrawn. The schedule must not keep emailing
        // a sanctions log they can no longer open themselves.
        $this->owner->roles()->first()->revokePermissionTo('tprm.screening.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $outcome = app(ScheduledReportDispatcher::class)->run($schedule->refresh());

        $this->assertSame('skipped', $outcome);
        Mail::assertNothingSent();

        $schedule->refresh();
        $this->assertSame(ReportSchedule::STATUS_SKIPPED, $schedule->last_run_status);
        $this->assertStringContainsString('no longer holds tprm.screening.view', $schedule->last_run_message);
    }

    #[Test]
    public function a_schedule_whose_owner_left_stops_rather_than_sending_with_nobodys_permissions(): void
    {
        $schedule = $this->schedule(['frequency' => 'daily']);

        $schedule->forceFill(['owner_id' => null])->save();

        $outcome = app(ScheduledReportDispatcher::class)->run($schedule->refresh());

        $this->assertSame('skipped', $outcome);
        Mail::assertNothingSent();
        $this->assertStringContainsString('no longer has an account', $schedule->refresh()->last_run_message);
    }

    #[Test]
    public function a_skip_does_not_read_as_a_successful_run(): void
    {
        $schedule = $this->schedule(['frequency' => 'daily']);
        $schedule->forceFill(['owner_id' => null])->save();

        app(ScheduledReportDispatcher::class)->run($schedule->refresh());

        // A schedule that never delivers must not show a green tick.
        $this->assertStringContainsString('Skipped', $schedule->refresh()->describeLastRun());
        $this->assertStringNotContainsString('Sent', $schedule->describeLastRun());
    }

    #[Test]
    public function a_schedule_that_has_never_run_says_so_rather_than_showing_a_tick(): void
    {
        $schedule = $this->schedule(['frequency' => 'weekly', 'day_of_week' => 1]);

        $this->assertSame('Never run', $schedule->describeLastRun());
        $this->assertNull($schedule->last_run_status);
    }

    /* ================================================================== */
    /*  Permissions */
    /* ================================================================== */

    #[Test]
    public function you_cannot_schedule_a_report_you_cannot_read(): void
    {
        $limited = $this->user('No Screening', 'noscreen@osb.test', [
            'tprm.view', 'tprm.report.view', 'tprm.report.export',
        ]);

        // Scheduling it to yourself would be a permission bypass with a
        // one-day delay.
        $this->actingAs($limited)
            ->post(route('tprm.reports.schedules.store'), $this->payload(['report_key' => 'screening-log']))
            ->assertSessionHasErrors('report_key');

        $this->assertSame(0, ReportSchedule::query()->count());
    }

    #[Test]
    public function the_list_shows_schedules_reading_data_the_viewer_cannot_open(): void
    {
        $this->schedule(['report_key' => 'screening-log', 'name' => 'Weekly sanctions log']);

        $viewer = $this->user('Viewer', 'viewer@osb.test', ['tprm.view', 'tprm.report.view']);

        // A schedule is a standing instruction to email data OUT of the
        // institution. Somebody reviewing that estate has to see it exists,
        // even though they cannot open its contents.
        $this->actingAs($viewer)
            ->get(route('tprm.reports.schedules'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tprm/Reports/Schedules')
                ->has('schedules', 1)
                ->where('schedules.0.name', 'Weekly sanctions log')
                // ...but cannot create one.
                ->where('can.manage', false)
            );
    }

    #[Test]
    public function the_screen_warns_when_the_installation_cannot_send_mail(): void
    {
        config()->set('mail.default', 'log');

        $this->actingAs($this->owner)
            ->get(route('tprm.reports.schedules'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('mailerIsLog', true));
    }

    /* ================================================================== */
    /*  The command */
    /* ================================================================== */

    #[Test]
    public function the_command_reports_what_it_did_and_fails_loudly(): void
    {
        $this->schedule(['frequency' => 'daily']);

        $this->artisan('tprm:send-scheduled-reports', ['--date' => '2027-01-06'])
            ->expectsOutputToContain('1 due: 1 sent, 0 skipped, 0 failed.')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_command_does_nothing_when_the_module_is_off(): void
    {
        config()->set('features.tprm', false);
        $this->schedule(['frequency' => 'daily']);

        $this->artisan('tprm:send-scheduled-reports')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    /* ================================================================== */

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'report_key' => 'assessment-status',
            'name' => 'Test schedule',
            'frequency' => 'daily',
            'send_at' => '07:00',
            'format' => 'xlsx',
            'recipients' => ['procurement@osb.example'],
            'is_active' => true,
        ], $overrides);
    }

    /** @param  array<string, mixed>  $attributes */
    private function schedule(array $attributes = []): ReportSchedule
    {
        return ReportSchedule::create(array_merge([
            'organization_id' => $this->bank->id,
            'report_key' => 'assessment-status',
            'name' => 'Test schedule',
            'frequency' => 'daily',
            'send_at' => '07:00',
            'format' => 'xlsx',
            'recipients' => ['procurement@osb.example'],
            'owner_id' => $this->owner->id,
            'is_active' => true,
        ], $attributes));
    }

    /** @param  list<string>  $permissions */
    private function user(string $name, string $email, array $permissions): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make(Str::random(32)), 'email_verified_at' => now(),
            'organization_id' => $this->bank->id, 'is_active' => true,
        ]);

        $role = Role::findOrCreate('role-'.Str::slug($email), 'web');
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole($role);

        return $user;
    }

    private function engagement(string $reference): Engagement
    {
        $vendor = ThirdParty::create([
            'organization_id' => $this->bank->id,
            'legal_name' => $reference.' provider',
            'slug' => Str::random(12), 'entity_type' => 'company', 'status' => 'active',
        ]);

        $engagement = Engagement::create([
            'organization_id' => $this->bank->id,
            'third_party_id' => $vendor->id,
            'reference' => $reference,
            'name' => 'Service for '.$reference,
            'engagement_type' => 'ict_service',
        ]);

        $engagement->forceFill(['status' => EngagementStatus::Active->value])->save();

        return $engagement->refresh();
    }
}
