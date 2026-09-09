<?php

namespace Tests\Feature\Console;

use App\Models\Bcms\AuditLog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The watchdog must report audit rows that could not be written.
 *
 * `BcmsAuditable::writeBcmsAuditRow()` catches Throwable so that a failed audit
 * write never rolls back the business write it describes. That is the right
 * trade — but it is also precisely how a `varchar(20)` `event` column discarded
 * audit rows for the entire life of the module with nothing noticing, because
 * the only other signal was a `Log::error` that nothing read (ADR 0014).
 *
 * Widening the column fixed that one cause. This is what covers the rest: a
 * lock timeout, a full disk, a value that will not encode, a future migration
 * narrowing any column on the table. In a module whose deliverable is the log,
 * an audit path that has begun failing must page somebody.
 */
class BcmsWatchdogAuditSignalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(AuditLog::AUDIT_FAILURE_CACHE_KEY);

        Organization::create([
            'name' => 'Watchdog Bank', 'short_name' => 'WDB',
            'institution_type' => 'commercial_bank', 'sector' => 'banking', 'is_active' => true,
        ]);
    }

    #[Test]
    public function a_healthy_audit_path_is_silent_and_the_watchdog_succeeds(): void
    {
        $this->artisan('bcms:watchdog')
            ->doesntExpectOutputToContain('audit row(s) could not be written')
            ->assertSuccessful();
    }

    #[Test]
    public function audit_write_failures_are_reported_and_fail_the_run(): void
    {
        // Stand in for the catch block having fired three times. Asserting on
        // the counter rather than forcing a real write failure keeps this test
        // about the SIGNAL; that the database really rejects an oversized event
        // is proved separately by BcmsAuditEventWidthTest.
        Cache::put(AuditLog::AUDIT_FAILURE_CACHE_KEY, 3, now()->addDay());

        $this->artisan('bcms:watchdog')
            ->expectsOutputToContain('3 BCMS audit row(s) could not be written')
            ->assertFailed();
    }

    #[Test]
    public function the_counter_resets_so_the_next_run_reports_only_new_failures(): void
    {
        Cache::put(AuditLog::AUDIT_FAILURE_CACHE_KEY, 2, now()->addDay());

        $this->artisan('bcms:watchdog')->assertFailed();

        $this->assertSame(
            0,
            (int) Cache::get(AuditLog::AUDIT_FAILURE_CACHE_KEY, 0),
            'The counter was not cleared, so every later run would re-report failures already raised.'
        );

        // A count that never resets stops carrying information the day after it
        // first fires — the watchdog would then be permanently red and ignored,
        // which is the same silence in a louder costume.
        $this->artisan('bcms:watchdog')
            ->doesntExpectOutputToContain('audit row(s) could not be written')
            ->assertSuccessful();
    }
}
