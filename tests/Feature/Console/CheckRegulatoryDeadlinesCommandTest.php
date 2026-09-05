<?php

namespace Tests\Feature\Console;

use App\Models\RegulatoryDeadline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;

/**
 * `regulatory:check-deadlines` (migration Phase 5.3).
 *
 * THE COMMAND HAD NEVER RUN. Scheduled twice daily, it queried four columns
 * that do not exist on `loss_events` — `cbn_report_deadline`, `cbn_reported`,
 * `nfiu_report_deadline`, `nfiu_reported` — and died on the first query with
 * "Unknown column 'cbn_report_deadline' in 'where clause'". Not one alert had
 * ever been sent by the alarm that tells a bank its mandatory CBN loss report
 * is hours from being late.
 *
 * It had no test anywhere, which is the gap Phase 4's criterion 5 turned up
 * for `measures:rebaseline-thresholds`.
 *
 * WHY A TEST THAT ONLY CHECKS SUCCESS WOULD HAVE MISSED IT:
 * `$this->artisan(...)->run()` returns **0 even when the command throws** —
 * verified against this very command before the fix. The console kernel
 * reports the exception and the pending command still reports success, so
 * `assertSuccessful()` proves nothing. Every test below asserts the ALERTS.
 *
 * WHAT IT DOES NOT DO, recorded because the name promises otherwise: it never
 * reads `regulatory_deadlines`. The filing calendar this module maintains has
 * no reminder mechanism at all. And there is NO NFIU DEADLINE COLUMN on
 * `loss_events`, so the NFIU countdown the old code pretended to compute
 * cannot be computed from what is stored. Both gaps are in the module notes.
 *
 * The command runs with no tenant resolved, which is what a scheduled job
 * wants: OrganizationScope is inert without one, so a single run covers every
 * institution on the installation.
 */
class CheckRegulatoryDeadlinesCommandTest extends TestCase
{
    use CreatesDomainFixtures, RefreshDatabase;

    #[Test]
    public function it_alerts_on_a_cbn_clock_inside_the_window(): void
    {
        $this->bootDomainFixtures();

        $event = $this->makeLossEvent([
            'cbn_reporting_deadline' => now()->addDay()->toDateString(),
            'cbn_notification_sent' => false,
        ]);

        $this->artisan('regulatory:check-deadlines')->assertSuccessful();

        $alerts = DB::table('notifications_log')->where('type', 'regulatory_deadline_urgent')->get();

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString($event->event_reference, $alerts->first()->subject);
        $this->assertStringContainsString('CBN', $alerts->first()->subject);
        $this->assertSame($this->organization->id, (int) $alerts->first()->organization_id);
    }

    /** A clock already reported against is not chased. */
    #[Test]
    public function an_already_reported_event_raises_nothing(): void
    {
        $this->bootDomainFixtures();

        $this->makeLossEvent([
            'cbn_reporting_deadline' => now()->addDay()->toDateString(),
            'cbn_notification_sent' => true,
        ]);

        $this->artisan('regulatory:check-deadlines')->assertSuccessful();

        $this->assertSame(0, DB::table('notifications_log')->count());
    }

    /** Nor is one outside the 48-hour window, in either direction. */
    #[Test]
    public function only_the_next_forty_eight_hours_are_chased(): void
    {
        $this->bootDomainFixtures();

        $this->makeLossEvent(['cbn_reporting_deadline' => now()->addDays(5)->toDateString(), 'cbn_notification_sent' => false]);
        $this->makeLossEvent(['cbn_reporting_deadline' => now()->subDay()->toDateString(), 'cbn_notification_sent' => false]);

        $this->artisan('regulatory:check-deadlines')->assertSuccessful();

        $this->assertSame(0, DB::table('notifications_log')->count(), 'A passed deadline is not "approaching".');
    }

    /**
     * There is no NFIU deadline to count down to, and the schema says so.
     *
     * The old code queried `nfiu_report_deadline`. `loss_events` carries
     * `nfiu_reportable`, `nfiu_report_type`, `nfiu_str_reference` and
     * `nfiu_report_filed` — and no date. An NFIU countdown therefore cannot be
     * computed from stored data, and inventing one would be fabricating a
     * regulatory clock. This test is the record of that, so a future NFIU
     * deadline column arrives with somewhere obvious to start.
     */
    #[Test]
    public function the_schema_carries_no_nfiu_deadline_to_chase(): void
    {
        $this->assertFalse(Schema::hasColumn('loss_events', 'nfiu_report_deadline'));
        $this->assertFalse(Schema::hasColumn('loss_events', 'nfiu_reporting_deadline'));

        // What it does carry, so the absence is a statement about dates only.
        $this->assertTrue(Schema::hasColumn('loss_events', 'nfiu_reportable'));
        $this->assertTrue(Schema::hasColumn('loss_events', 'nfiu_report_filed'));
    }

    /**
     * And the columns the CBN half reads are the ones that exist.
     *
     * Pinned directly, because a rename would otherwise put this command back
     * where it started: silently querying a column that is not there.
     */
    #[Test]
    public function the_cbn_columns_the_command_reads_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('loss_events', 'cbn_reporting_deadline'));
        $this->assertTrue(Schema::hasColumn('loss_events', 'cbn_notification_sent'));

        $this->assertFalse(Schema::hasColumn('loss_events', 'cbn_report_deadline'), 'The column the command used to query.');
        $this->assertFalse(Schema::hasColumn('loss_events', 'cbn_reported'), 'The column the command used to query.');
    }

    /**
     * A scheduled run covers every institution.
     *
     * There is no tenant in a console context, so OrganizationScope is inert
     * and the query spans the installation. If that ever changed, one
     * organisation's overdue CBN filings would stop being chased with no
     * error anywhere.
     */
    #[Test]
    public function a_scheduled_run_covers_every_organisation(): void
    {
        $this->bootDomainFixtures('First Bank PLC');
        $first = $this->makeLossEvent(['cbn_reporting_deadline' => now()->addDay()->toDateString(), 'cbn_notification_sent' => false]);

        $this->bootDomainFixtures('Second Bank PLC');
        $second = $this->makeLossEvent(['cbn_reporting_deadline' => now()->addDay()->toDateString(), 'cbn_notification_sent' => false]);

        // A scheduled run has no tenant. Leaving one set would make this test
        // pass for the wrong reason — and would hide the very thing it exists
        // to check, since OrganizationScope would then narrow the query to one
        // institution and quietly stop chasing everybody else's CBN filings.
        TenantContext::clear();

        $this->artisan('regulatory:check-deadlines')->assertSuccessful();

        $subjects = DB::table('notifications_log')->pluck('subject')->implode(' | ');

        $this->assertStringContainsString($first->event_reference, $subjects);
        $this->assertStringContainsString($second->event_reference, $subjects);
        $this->assertSame(2, DB::table('notifications_log')->count());
    }

    /**
     * The register this command is NAMED for is not read by it.
     *
     * Pinned deliberately: an overdue entry in `regulatory_deadlines` produces
     * no alert. This is not an assertion that the behaviour is right — it is a
     * record that the filing calendar has no reminder mechanism, so that
     * whoever adds one can delete this test knowingly rather than discover the
     * gap from a missed CBN return.
     */
    #[Test]
    public function the_filing_calendar_itself_raises_no_alerts(): void
    {
        $this->bootDomainFixtures();

        RegulatoryDeadline::create([
            'organization_id' => $this->organization->id,
            'regulator' => 'CBN',
            'report_type' => 'ORMS return',
            'title' => 'Due tomorrow',
            'deadline_date' => now()->addDay(),
            'frequency' => 'quarterly',
            'status' => 'upcoming',
        ]);

        $this->artisan('regulatory:check-deadlines')->assertSuccessful();

        $this->assertSame(0, DB::table('notifications_log')->count());
    }
}
