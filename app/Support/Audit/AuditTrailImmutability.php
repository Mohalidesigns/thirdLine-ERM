<?php

namespace App\Support\Audit;

use Illuminate\Support\Facades\DB;

/**
 * The database-level append-only guard on risk_audit_trail.
 *
 * A model observer is bypassed by any query-builder update, a raw statement or
 * a DBA at the console. The trigger is not — which is the whole point of a
 * tamper-evident trail.
 *
 * WHY THIS IS A CLASS AND NOT JUST A MIGRATION. On SQLite, Laravel implements a
 * column `change()` by rebuilding the table: create a new one, copy the rows,
 * drop the original, rename. Triggers belong to the dropped table and go with
 * it — silently. Widening `action_type` therefore removed the append-only
 * enforcement on every fresh install, while leaving it intact on MySQL (where
 * MODIFY COLUMN does not touch triggers), so the two diverged with nothing
 * saying so. Any migration that alters this table must reinstall the guard
 * afterwards, and it should not have to restate the SQL to do it.
 *
 * The original migration (2026_08_09_100004) keeps its own copy of these
 * definitions because an already-applied migration is history and is not
 * rewritten. Migrations using this class drop and reinstall unconditionally, so
 * whatever is defined HERE is what ends up installed on every path.
 */
class AuditTrailImmutability
{
    public const TABLE = 'risk_audit_trail';

    public const MESSAGE = 'risk_audit_trail is append-only';

    /** @var list<string> */
    public const TRIGGERS = ['risk_audit_trail_no_update', 'risk_audit_trail_no_delete'];

    /**
     * Drop the triggers and put them back, so a caller that is about to alter
     * the table does not have to care whether its driver keeps them.
     */
    public static function reinstall(): void
    {
        self::drop();
        self::install();
    }

    public static function install(): void
    {
        $message = self::MESSAGE;

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => self::run([
                "CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                "CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
            ]),

            'pgsql' => self::run([
                "CREATE OR REPLACE FUNCTION risk_audit_trail_immutable() RETURNS trigger AS $$
                 BEGIN RAISE EXCEPTION '{$message}'; END; $$ LANGUAGE plpgsql",
                'CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 FOR EACH ROW EXECUTE FUNCTION risk_audit_trail_immutable()',
                'CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 FOR EACH ROW EXECUTE FUNCTION risk_audit_trail_immutable()',
            ]),

            // Unknown driver: the model-level guard still applies. Say so
            // rather than pretending the database is enforcing anything.
            default => logger()->warning(
                'risk_audit_trail immutability triggers not installed: unsupported driver',
                ['driver' => DB::connection()->getDriverName()]
            ),
        };
    }

    public static function drop(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }

    /** @param list<string> $statements */
    private static function run(array $statements): void
    {
        foreach ($statements as $sql) {
            DB::unprepared($sql);
        }
    }
}
