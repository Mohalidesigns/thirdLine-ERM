<?php

namespace Tests\Feature\Database;

use App\Models\Tprm\AuditLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsOffsetZoneWorld;
use Tests\Support\CreatesDomainFixtures;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * ADR 0029: the TIMESTAMP values written under an old session zone are
 * converted once, and nothing the application reads moves.
 *
 * The world is built under `+02:00` at 10:00 UTC, so a TIMESTAMP holds 08:00Z
 * while the application wrote "10:00:00". Pinning the session to UTC without
 * converting would show 08:00:00 and break the TPRM chain: the first test
 * proves the defect reproduces, the rest prove the conversion repairs it.
 */
class TimestampZoneConversionTest extends TestCase
{
    use BuildsOffsetZoneWorld, CreatesDomainFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        // The zone is a property of the connection, not of the rolled-back transaction.
        $this->useUtcSession();
        Carbon::setTestNow();
        TenantContext::clear();

        parent::tearDown();
    }

    #[Test]
    public function pinning_utc_without_converting_moves_every_timestamp_and_breaks_the_tprm_chain(): void
    {
        $this->buildOffsetZoneWorld();
        $this->useUtcSession();

        $raw = $this->rawSnapshot();

        $this->assertSame('2026-07-15 08:00:00', $raw['org.created_at'], 'The defect did not reproduce.');
        $this->assertSame('2026-07-15 08:00:00', $raw['tp.created_at']);
        $this->assertSame('2026-07-15 08:00:00', $raw['approval.requested_at']);
        // risk_audit_trail.changed_at is DATETIME, so it holds the literal and is immune.
        $this->assertSame('2026-07-15 10:00:00', $raw['audit.changed_at']);

        $tprm = AuditLog::query()->withoutGlobalScopes()->orderBy('id')->first();
        $this->assertFalse($tprm->isIntact(), 'A pin without a conversion must read as a tampered TPRM row.');
    }

    #[Test]
    public function the_conversion_restores_every_value_as_written_and_records_each_table(): void
    {
        $this->buildOffsetZoneWorld();

        $asWritten = $this->rawSnapshot();   // read under +02:00: the strings the application wrote
        $this->assertSame('2026-07-15 10:00:00', $asWritten['org.created_at']);

        $this->useUtcSession();
        $this->assertNull(DB::table('approval_requests')->value('reviewed_at'));

        $converted = $this->zoneMigration()->convertFrom('+02:00');

        $this->assertSame($asWritten, $this->rawSnapshot());
        $this->assertNull(DB::table('approval_requests')->value('reviewed_at'), 'A NULL must stay NULL.');

        $tables = (int) DB::selectOne(
            "select count(distinct c.TABLE_NAME) as n from information_schema.COLUMNS c
               join information_schema.TABLES t on t.TABLE_SCHEMA = c.TABLE_SCHEMA and t.TABLE_NAME = c.TABLE_NAME
              where c.TABLE_SCHEMA = database() and t.TABLE_TYPE = 'BASE TABLE' and lower(c.DATA_TYPE) = 'timestamp'"
        )->n;

        $this->assertGreaterThan(100, $tables);
        $this->assertCount($tables, $converted);

        $ledger = DB::table('db_timezone_conversions')->where('outcome', 'converted')->get();
        $this->assertCount($tables, $ledger);
        $this->assertSame(['+02:00'], $ledger->pluck('from_zone')->unique()->values()->all());
        $this->assertTrue($ledger->every(fn ($r) => $r->table_name !== '*' && $r->column_names !== ''));
        $this->assertStringStartsWith('created_at,updated_at', $ledger->firstWhere('table_name', 'organizations')->column_names);
    }

    #[Test]
    public function a_second_run_changes_nothing_and_a_missing_ledger_row_resumes_only_that_table(): void
    {
        $this->buildOffsetZoneWorld();
        $this->useUtcSession();

        $m = $this->zoneMigration();
        $m->convertFrom('+02:00');

        $values = $this->rawSnapshot();
        $ledgerRows = DB::table('db_timezone_conversions')->count();

        $this->assertSame([], $m->convertFrom('+02:00'));
        $this->assertSame($values, $this->rawSnapshot());
        $this->assertSame($ledgerRows, DB::table('db_timezone_conversions')->count());

        // A run that died after most tables committed: only the table without a
        // ledger row moves again. This is why the ledger exists.
        DB::table('db_timezone_conversions')->where('table_name', 'approval_requests')->delete();

        $this->assertSame(['approval_requests'], array_keys($m->convertFrom('+02:00')));

        $after = $this->rawSnapshot();
        $this->assertSame('2026-07-15 12:00:00', $after['approval.requested_at']);
        $this->assertSame($values['org.created_at'], $after['org.created_at']);
    }

    #[Test]
    public function both_audit_chains_verify_after_the_conversion(): void
    {
        $this->buildOffsetZoneWorld();
        $this->assertTrue($this->chainsIntact(), 'Control: both chains verify under the zone they were sealed in.');

        $this->useUtcSession();
        $this->zoneMigration()->convertFrom('+02:00');

        $this->assertTrue($this->chainsIntact());
    }

    #[Test]
    public function an_unconvertible_zone_throws_before_anything_is_written(): void
    {
        $m = $this->zoneMigration();

        $this->assertTrue($m->isUtcEquivalent('+00:00'));
        $this->assertFalse($m->isUtcEquivalent('+02:00'));

        DB::table('db_timezone_conversions')->insert([
            'table_name' => 'organizations', 'column_names' => 'created_at', 'outcome' => 'converted',
            'from_zone' => 'Not/AZone', 'rows_affected' => 0, 'converted_at' => now(),
        ]);
        $before = DB::table('db_timezone_conversions')->count();

        try {
            $m->resolveOldZone();
            $this->fail('A zone CONVERT_TZ cannot map must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Not/AZone', $e->getMessage());
            $this->assertStringContainsString('ADR 0029', $e->getMessage());
        }

        $this->assertSame($before, DB::table('db_timezone_conversions')->count());
    }

    #[Test]
    public function a_resumed_run_with_disagreeing_source_zones_refuses_to_continue(): void
    {
        foreach (['organizations' => '+02:00', 'users' => '+01:00'] as $table => $zone) {
            DB::table('db_timezone_conversions')->insert([
                'table_name' => $table, 'column_names' => 'created_at', 'outcome' => 'converted',
                'from_zone' => $zone, 'rows_affected' => 0, 'converted_at' => now(),
            ]);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('more than one source zone');

        $this->zoneMigration()->resolveOldZone();
    }

    #[Test]
    public function a_migrated_database_records_exactly_one_skip_row(): void
    {
        $rows = DB::table('db_timezone_conversions')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('*', $rows[0]->table_name);
        // Which one depends on the server's zone: UTC hosts skip as equivalent,
        // a CEST or WAT developer machine as a fresh database.
        $this->assertContains($rows[0]->outcome, ['skipped_utc_equivalent', 'skipped_fresh_database']);
    }
}
