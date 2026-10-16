<?php

namespace Tests\Feature\Database;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsOffsetZoneWorld;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0029 section 2i: down() puts the stored instants back as they were.
 *
 * down() drops the ledger table, which is DDL and commits implicitly, so this
 * cannot run inside RefreshDatabase's transaction. The schema is rebuilt
 * around it so later tests are unaffected.
 *
 * This does the rebuild by hand instead of using DatabaseMigrations, whose
 * teardown runs `migrate:rollback`: that runs every migration's down(), and
 * 2026_09_07_100003's down() re-adds columns that its up() never dropped, so
 * the rollback dies half-way and leaves the schema in pieces for the next test.
 */
class TimestampZoneConversionDownTest extends TestCase
{
    use BuildsOffsetZoneWorld, CreatesDomainFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        $this->useUtcSession();
        Carbon::setTestNow();
        TenantContext::clear();

        // Committed rows and a dropped ledger: start the next test from a clean schema.
        $this->artisan('migrate:fresh');
        RefreshDatabaseState::$migrated = true;

        parent::tearDown();
    }

    #[Test]
    public function down_restores_the_old_zone_values_and_drops_the_ledger(): void
    {
        $this->buildOffsetZoneWorld();
        $asWritten = $this->rawSnapshot();                    // under +02:00

        $m = $this->zoneMigration();
        $this->useUtcSession();
        $m->convertFrom('+02:00');
        $this->assertSame($asWritten, $this->rawSnapshot());

        $m->down();

        DB::statement("SET time_zone = '+02:00'");
        $this->assertSame($asWritten, $this->rawSnapshot());
        $this->assertFalse(Schema::hasTable('db_timezone_conversions'));
    }
}
