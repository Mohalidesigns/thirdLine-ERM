<?php

namespace Tests\Feature\Console;

use App\Models\Bcms\AuditLog;
use App\Models\Bcms\SavedGroup;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

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

        // The module is off by default — `features.bcms` is false unless set,
        // and every bcms: command now returns early when it is. These tests are
        // about what the watchdog does when it RUNS, so switch it on.
        Config::set('features.bcms', true);

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

    #[Test]
    public function a_real_audit_write_failure_increments_the_counter_without_failing_the_business_write(): void
    {
        // THE WIRE, not the signal. Every other test here presets the cache key,
        // which proves the watchdog reads a counter but says nothing about
        // whether anything ever writes one. Delete the increment from
        // BcmsAuditable::writeBcmsAuditRow() and those tests all still pass —
        // which is the exact shape of the defect ADR 0014 exists to fix, one
        // level down. A watcher whose input wire is untested is not a watcher.
        //
        // Dropping the table is the bluntest honest way to make the audit
        // INSERT fail for a reason that is not contrived: it exercises the real
        // catch, on the real driver, through a real model save.
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs a server that will actually reject the audit insert.');
        }

        $organization = Organization::query()->sole();
        TenantContext::set($organization->id);

        Cache::forget(AuditLog::AUDIT_FAILURE_CACHE_KEY);
        Schema::drop('bcms_audit_logs');

        try {
            $group = SavedGroup::query()->create([
                'organization_id' => $organization->id,
                'name' => 'Crisis team',
                'is_dynamic' => false,
            ]);

            // Both halves of the design contract, in one assertion each.
            //
            // The business write MUST have succeeded: the whole reason the
            // catch exists is that a plan activation must not roll back because
            // its audit row would not save.
            $this->assertTrue(
                $group->exists,
                'The business write was lost when its audit row failed — the catch is no longer protecting the write.'
            );

            // And the failure MUST have been counted, or it is silent again.
            $this->assertSame(
                1,
                (int) Cache::get(AuditLog::AUDIT_FAILURE_CACHE_KEY, 0),
                'The audit insert failed and nothing counted it. This is the silence ADR 0014 was written about.'
            );
        } finally {
            TenantContext::clear();
        }
    }
}
