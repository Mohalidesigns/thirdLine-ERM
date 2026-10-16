<?php

namespace Tests\Feature\Database;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsOffsetZoneWorld;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0029 QA gate: the two guards the other conversion tests leave to prose.
 * The old zone is the GLOBAL zone (the migrating session is no witness), and a
 * conversion that would create a NULL rolls the table back.
 */
class TimestampZoneConversionGuardsTest extends TestCase
{
    use BuildsOffsetZoneWorld, CreatesDomainFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        DB::statement("SET time_zone = '+00:00'");
        DB::statement("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function the_old_zone_is_the_global_zone_not_the_migrating_sessions_zone(): void
    {
        $global = (string) DB::selectOne('select @@global.time_zone as z')->z;

        // The ledger holds only the skip row, so there is no recorded zone to resume from.
        $this->assertSame(0, DB::table('db_timezone_conversions')->where('outcome', 'converted')->count());

        foreach (['+05:00', '+00:00', '-03:30'] as $session) {
            DB::statement("SET time_zone = '{$session}'");

            $this->assertSame($global, $this->zoneMigration()->resolveOldZone(), "Session {$session} leaked into resolveOldZone().");
        }
    }

    #[Test]
    public function a_conversion_that_would_create_a_null_rolls_the_table_back(): void
    {
        $this->buildOffsetZoneWorld();
        DB::statement("SET time_zone = '+00:00'");

        // A zero date in a nullable TIMESTAMP: CONVERT_TZ maps it to NULL.
        DB::statement("SET sql_mode = ''");
        DB::table('approval_requests')->update(['reviewed_at' => '0000-00-00 00:00:00']);
        DB::statement("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        $requested = DB::table('approval_requests')->value('requested_at');

        try {
            $this->zoneMigration()->convertFrom('+02:00');
            $this->fail('A conversion that changes the NULL count must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('approval_requests', $e->getMessage());
            $this->assertStringContainsString('NULL', $e->getMessage());
        }

        $this->assertSame($requested, DB::table('approval_requests')->value('requested_at'), 'The table was not rolled back.');
        $this->assertFalse(DB::table('db_timezone_conversions')->where('table_name', 'approval_requests')->exists());
    }
}
