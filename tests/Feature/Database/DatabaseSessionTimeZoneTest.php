<?php

namespace Tests\Feature\Database;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EnsuresDatabaseExists;
use Tests\TestCase;

/**
 * ADR 0029: every database session runs in UTC.
 *
 * Without the pin a session runs on the server's SYSTEM zone (CEST in
 * production), `NOW()` runs two hours ahead of the UTC the application writes,
 * and every UTC write inside CEST's spring-forward hour is rejected with 1292.
 *
 * Deliberately NOT RefreshDatabase. The conversion migration leaves the
 * migrating connection on '+00:00' itself, so a test that reused that
 * connection would pass with the pin deleted. The reconnect below is what makes
 * this assertion real, and it would also discard a wrapping transaction.
 */
class DatabaseSessionTimeZoneTest extends TestCase
{
    use EnsuresDatabaseExists;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureDatabaseExists();
    }

    #[Test]
    public function both_mysql_family_connections_pin_utc_by_numeric_offset(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));
    }

    #[Test]
    public function a_fresh_connection_runs_in_utc(): void
    {
        DB::purge();
        DB::reconnect();

        $this->assertSame('+00:00', DB::selectOne('select @@session.time_zone as z')->z);
        $this->assertSame(1, (int) DB::selectOne('select now() = utc_timestamp() as same')->same);

        $database = Carbon::parse(DB::selectOne('select now() as n')->n, 'UTC');

        $this->assertLessThanOrEqual(5, abs($database->diffInSeconds(Carbon::now('UTC'))));
    }
}
