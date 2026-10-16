<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EnsuresDatabaseExists;
use Tests\TestCase;

/**
 * ADR 0029 section 3b: a UTC session without the conversion ledger still fails.
 *
 * Not RefreshDatabase: renaming a table is DDL and commits implicitly. The
 * rename is undone in finally, so the migrated schema is left as found.
 */
class PreflightDatabaseTimeZoneLedgerTest extends TestCase
{
    use EnsuresDatabaseExists;

    #[Test]
    public function a_missing_ledger_fails_preflight(): void
    {
        $this->ensureDatabaseExists();

        // Migrate if nothing has: a bare database must fail this test, not skip it.
        if (! Schema::hasTable('db_timezone_conversions')) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->assertTrue(Schema::hasTable('db_timezone_conversions'));

        DB::statement("SET time_zone = '+00:00'");
        DB::statement('rename table db_timezone_conversions to db_timezone_conversions_qa');

        try {
            $exit = Artisan::call('app:preflight', ['--allow-local' => true]);
            $row = collect(explode("\n", Artisan::output()))->first(fn ($l) => str_contains($l, 'Database time zone'));
        } finally {
            DB::statement('rename table db_timezone_conversions_qa to db_timezone_conversions');
        }

        $this->assertNotNull($row);
        $this->assertStringContainsString('FAIL', $row);
        $this->assertStringContainsString('conversion migration has not run', $row);
        $this->assertNotSame(0, $exit);
    }
}
