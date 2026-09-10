<?php

use App\Support\MorphTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-01 TASK 2 — one representation for every polymorphic entity_type.
 *
 * Four tables record what kind of thing a row is about, and before this
 * migration they disagreed:
 *
 *   risk_audit_trail.entity_type   "Risk" (AuditTrailService) and "risk"
 *                                  (RiskRegisterController) in the same column
 *   approval_requests.entity_type  "Risk"
 *   workflow_instances.entity_type "App\Models\Risk"
 *   notifications_log.metadata     "risk" inside a JSON blob
 *
 * Risk::auditTrail() filtered on "risk", so every row AuditTrailService wrote
 * was invisible to it — $risk->auditTrail returned an empty collection and the
 * change history screen showed nothing.
 *
 * Rewrites each column to the canonical snake_case alias from
 * App\Support\MorphTypes. Values that do not resolve to a known model are left
 * untouched and reported, rather than being guessed at: an audit row pointing
 * at the wrong entity is worse than one that is obviously orphaned.
 */
return new class extends Migration
{
    /**
     * table => entity type column.
     *
     * risk_audit_trail is deliberately ABSENT.
     *
     * That table is append-only at the database level (BEFORE UPDATE and
     * BEFORE DELETE triggers SIGNAL SQLSTATE 45000) and each row stores
     * hash = sha256(previous_hash || payload), where entity_type is one of the
     * hashed fields. Rewriting the column would therefore mean dropping the
     * immutability triggers, editing sealed rows, recomputing every hash in
     * every organization's chain, and re-arming the triggers.
     *
     * That is technically possible and still the wrong thing to do. The chain
     * exists so that a changed row is detectable; re-sealing it makes the
     * migration's own edit indistinguishable from a tamper, and invalidates
     * any chain head an auditor has previously attested to. Rewriting an
     * immutable audit trail to fix a query bug inverts the guarantee the trail
     * is there to provide.
     *
     * The stored values are also, literally, the truth: they record what the
     * application wrote at the time. The defect was on the read side —
     * Risk::auditTrail() filtered on a single spelling — and that is where it
     * is fixed, via MorphTypes::spellingsFor(). New rows are canonical because
     * Relation::enforceMorphMap() is registered, so the legacy set is bounded
     * and never grows.
     */
    private const TARGETS = [
        'approval_requests' => 'entity_type',
        'workflow_instances' => 'entity_type',
        'domain_events' => 'aggregate_type',
        'workflow_definitions' => 'entity_type',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $column) {
            $this->normaliseTable($table, $column);
        }

        $this->normaliseNotificationMetadata();
    }

    /**
     * Not reversible: the aliases are now the only representation and there is
     * no record of which of the three historic spellings each row used. The
     * previous code read whatever it found, so a rollback still functions.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock above.
    }

    private function normaliseTable(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $distinct = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column);

        $unresolved = [];

        foreach ($distinct as $value) {
            $alias = MorphTypes::normalise($value);

            if ($alias === null) {
                $unresolved[] = $value;

                continue;
            }

            if ($alias === $value) {
                continue;
            }

            DB::table($table)->where($column, $value)->update([$column => $alias]);
        }

        if ($unresolved !== []) {
            logger()->warning('Unrecognised entity types left as-is during morph-map normalisation', [
                'table' => $table,
                'column' => $column,
                'values' => $unresolved,
            ]);
        }
    }

    /**
     * notifications_log stores entity_type inside a JSON metadata blob, so it
     * needs a row-by-row rewrite rather than a set-based UPDATE.
     */
    private function normaliseNotificationMetadata(): void
    {
        if (! Schema::hasTable('notifications_log') || ! Schema::hasColumn('notifications_log', 'metadata')) {
            return;
        }

        DB::table('notifications_log')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->each(function ($row) {
                $metadata = json_decode((string) $row->metadata, true);

                if (! is_array($metadata) || ! isset($metadata['entity_type'])) {
                    return;
                }

                $alias = MorphTypes::normalise($metadata['entity_type']);

                if ($alias === null || $alias === $metadata['entity_type']) {
                    return;
                }

                $metadata['entity_type'] = $alias;

                DB::table('notifications_log')
                    ->where('id', $row->id)
                    ->update(['metadata' => json_encode($metadata)]);
            });
    }
};
