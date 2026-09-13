<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns risk_audit_trail into a tamper-evident chain (WP-00 TASK 7).
 *
 * Each row stores previous_hash (the hash of the preceding row in the same
 * organization) and hash = sha256(previous_hash || canonical row payload).
 * Editing or removing any row breaks every hash after it, which audit:verify
 * reports.
 *
 * The chain is per organization, not global: tenants' writes interleave
 * arbitrarily, and a single global chain would make one tenant's insert rate
 * a source of contention — and verification failures — for everyone else.
 * Within an organization the chain is total, so a deleted row is still caught.
 *
 * changed_by is widened to nullable in the same pass. It was NOT NULL, which
 * is why AuditTrailService used to fall back to user id 1 for system-initiated
 * changes — recording a real change against the wrong person. NULL now means
 * "no interactive actor", which is the truth.
 *
 * Additive: two new columns and one widened constraint. Nothing is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_audit_trail', function (Blueprint $table) {
            if (! Schema::hasColumn('risk_audit_trail', 'previous_hash')) {
                $table->char('previous_hash', 64)->nullable()->after('change_reason');
            }

            if (! Schema::hasColumn('risk_audit_trail', 'hash')) {
                $table->char('hash', 64)->nullable()->after('previous_hash');
                $table->index(['organization_id', 'id'], 'risk_audit_trail_chain_index');
            }
        });

        $this->widenChangedBy();
        $this->backfillChain();
        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        $this->dropImmutabilityTriggers();

        Schema::table('risk_audit_trail', function (Blueprint $table) {
            if (Schema::hasColumn('risk_audit_trail', 'hash')) {
                $table->dropIndex('risk_audit_trail_chain_index');
                $table->dropColumn('hash');
            }

            if (Schema::hasColumn('risk_audit_trail', 'previous_hash')) {
                $table->dropColumn('previous_hash');
            }
        });
    }

    /**
     * changed_by NOT NULL -> nullable, keeping the foreign key.
     */
    private function widenChangedBy(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite rebuilds the table for a column change and cannot do so
            // while the foreign key is in place, so drop and restore it.
            Schema::table('risk_audit_trail', function (Blueprint $table) {
                $table->dropForeign(['changed_by']);
            });
        }

        Schema::table('risk_audit_trail', function (Blueprint $table) {
            $table->unsignedBigInteger('changed_by')->nullable()->change();
        });

        if ($driver === 'sqlite') {
            Schema::table('risk_audit_trail', function (Blueprint $table) {
                $table->foreign('changed_by')->references('id')->on('users');
            });
        }
    }

    /**
     * Seal the rows that already exist so the chain starts from a known state.
     */
    private function backfillChain(): void
    {
        $organizationIds = DB::table('risk_audit_trail')
            ->select('organization_id')->distinct()->pluck('organization_id');

        foreach ($organizationIds as $organizationId) {
            $previous = null;

            DB::table('risk_audit_trail')
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->each(function ($row) use (&$previous) {
                    $hash = \App\Models\RiskAuditTrail::chainHash((array) $row, $previous);

                    DB::table('risk_audit_trail')->where('id', $row->id)->update([
                        'previous_hash' => $previous,
                        'hash' => $hash,
                    ]);

                    $previous = $hash;
                });
        }
    }

    /**
     * Enforce append-only in the database, not just in Eloquent.
     *
     * A model observer is bypassed by any query-builder update, a raw
     * statement or a DBA at the console. The trigger is not — which is the
     * whole point of a tamper-evident trail.
     */
    private function createImmutabilityTriggers(): void
    {
        $message = 'risk_audit_trail is append-only';

        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => collect([
                "CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                "CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
            ])->each(fn (string $sql) => DB::unprepared($sql)),

            'sqlite' => collect([
                "CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 BEGIN SELECT RAISE(ABORT, '{$message}'); END",
                "CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 BEGIN SELECT RAISE(ABORT, '{$message}'); END",
            ])->each(fn (string $sql) => DB::unprepared($sql)),

            'pgsql' => collect([
                "CREATE OR REPLACE FUNCTION risk_audit_trail_immutable() RETURNS trigger AS $$
                 BEGIN RAISE EXCEPTION '{$message}'; END; $$ LANGUAGE plpgsql",
                'CREATE TRIGGER risk_audit_trail_no_update BEFORE UPDATE ON risk_audit_trail
                 FOR EACH ROW EXECUTE FUNCTION risk_audit_trail_immutable()',
                'CREATE TRIGGER risk_audit_trail_no_delete BEFORE DELETE ON risk_audit_trail
                 FOR EACH ROW EXECUTE FUNCTION risk_audit_trail_immutable()',
            ])->each(fn (string $sql) => DB::unprepared($sql)),

            // Unknown driver: the model-level guard still applies. Say so
            // rather than pretending the database is enforcing anything.
            default => logger()->warning(
                'risk_audit_trail immutability triggers not installed: unsupported driver',
                ['driver' => DB::connection()->getDriverName()]
            ),
        };
    }

    private function dropImmutabilityTriggers(): void
    {
        foreach (['risk_audit_trail_no_update', 'risk_audit_trail_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
