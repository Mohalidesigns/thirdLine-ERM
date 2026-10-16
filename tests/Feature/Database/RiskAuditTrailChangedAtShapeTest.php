<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR 0029 section 2f: the evidence table's column leaves session-zone
 * conversion, and the append-only triggers survive the retype.
 */
class RiskAuditTrailChangedAtShapeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function changed_at_is_a_non_null_datetime_with_a_current_timestamp_default(): void
    {
        $column = DB::selectOne(
            "select DATA_TYPE as t, IS_NULLABLE as n, COLUMN_DEFAULT as d from information_schema.COLUMNS
              where TABLE_SCHEMA = database() and TABLE_NAME = 'risk_audit_trail' and COLUMN_NAME = 'changed_at'"
        );

        $this->assertSame('datetime', strtolower($column->t));
        $this->assertSame('NO', $column->n);
        // MariaDB 10.4 reports current_timestamp(); MySQL 8 reports CURRENT_TIMESTAMP.
        $this->assertSame('CURRENT_TIMESTAMP', strtoupper(str_replace('()', '', (string) $column->d)));
    }

    #[Test]
    public function both_append_only_triggers_are_still_in_place(): void
    {
        $triggers = collect(DB::select(
            "select TRIGGER_NAME as n from information_schema.TRIGGERS
              where TRIGGER_SCHEMA = database() and EVENT_OBJECT_TABLE = 'risk_audit_trail'"
        ))->pluck('n')->map(fn ($n) => strtolower($n))->all();

        $this->assertContains('risk_audit_trail_no_update', $triggers);
        $this->assertContains('risk_audit_trail_no_delete', $triggers);
    }
}
