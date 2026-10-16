<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EnsuresDatabaseExists;
use Tests\TestCase;

/**
 * ADR 0029 section 2f, on a probe table: retyping TIMESTAMP -> DATETIME under
 * the old zone keeps what the application reads, and does not fire (or drop) a
 * BEFORE UPDATE trigger, because an ALTER is not a row update.
 *
 * Deliberately NOT RefreshDatabase: DDL commits implicitly, and this touches
 * only its own probe table. It needs a server where the connection user may
 * create triggers (CI's root has SUPER).
 */
class ConvergeColumnToDatetimeProbeTest extends TestCase
{
    use EnsuresDatabaseExists;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureDatabaseExists();

        DB::statement('drop table if exists adr0029_probe');
    }

    protected function tearDown(): void
    {
        DB::statement("SET time_zone = '+00:00'");
        DB::statement('drop table if exists adr0029_probe');

        parent::tearDown();
    }

    #[Test]
    public function the_retype_keeps_the_value_as_written_and_leaves_the_trigger_alone(): void
    {
        DB::statement('create table adr0029_probe (id int primary key, changed_at timestamp not null default current_timestamp)');
        DB::statement("create trigger adr0029_probe_no_update before update on adr0029_probe for each row signal sqlstate '45000' set message_text = 'append-only'");

        DB::statement("SET time_zone = '+02:00'");
        DB::insert("insert into adr0029_probe (id, changed_at) values (1, '2026-07-15 10:00:00')");
        DB::statement("SET time_zone = '+00:00'");

        $this->assertSame('2026-07-15 08:00:00', DB::selectOne('select changed_at from adr0029_probe')->changed_at);

        $migration = require database_path('migrations/2026_10_16_100001_run_every_session_in_utc_and_convert_timestamp_values.php');
        $migration->convergeColumnToDatetime('adr0029_probe', 'changed_at', '+02:00');

        $this->assertSame('datetime', strtolower($this->dataType()));
        $this->assertSame('2026-07-15 10:00:00', DB::selectOne('select changed_at from adr0029_probe')->changed_at);
        $this->assertSame('+00:00', DB::selectOne('select @@session.time_zone as z')->z, 'The caller\'s session zone must be restored.');

        $triggers = DB::selectOne(
            "select count(*) as n from information_schema.TRIGGERS
              where TRIGGER_SCHEMA = database() and TRIGGER_NAME = 'adr0029_probe_no_update'"
        )->n;
        $this->assertSame(1, (int) $triggers);

        // A second call is a no-op: the column is no longer a timestamp.
        $migration->convergeColumnToDatetime('adr0029_probe', 'changed_at', '+05:00');
        $this->assertSame('2026-07-15 10:00:00', DB::selectOne('select changed_at from adr0029_probe')->changed_at);
    }

    private function dataType(): string
    {
        return DB::selectOne(
            "select DATA_TYPE as t from information_schema.COLUMNS
              where TABLE_SCHEMA = database() and TABLE_NAME = 'adr0029_probe' and COLUMN_NAME = 'changed_at'"
        )->t;
    }
}
