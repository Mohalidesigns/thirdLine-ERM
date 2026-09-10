<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-03 TASK 4 — the resolved org-graph node on the domain tables.
 *
 * entity_id and business_unit_id both stay. This is a third column that says
 * "whichever of those two you meant, here is the one node in the unified graph
 * it resolves to". Readers move to node_id; the legacy columns keep being
 * written for one release so a rollback does not lose the answer.
 *
 * WHAT IS NOT HERE, and why:
 *
 *   users — already carries scope_entity_id (the authorization boundary) and
 *   business_unit_id (the home unit). A third node column would be a third
 *   answer to "where does this person sit" with nothing reading it. When
 *   scope_entity_id moves onto the object graph it is its own migration, with
 *   its own tests, because it is a fail-open risk and nothing else here is.
 *
 *   assessment_campaigns, risk_appetite — organization-wide by construction.
 *   A campaign spans nodes rather than belonging to one; appetite is set per
 *   risk category at the top. Giving them a node would assert a containment
 *   that the domain does not have.
 */
return new class extends Migration
{
    /**
     * table => the column node_id is placed after.
     */
    private const TABLES = [
        'risks' => 'entity_id',
        'controls' => 'entity_id',
        'issues' => 'entity_id',
        'loss_events' => 'entity_id',
        'key_risk_indicators' => 'entity_id',
        'near_misses' => 'business_unit_id',
        'treatment_plans' => 'risk_id',
        'control_tests' => 'control_id',
        'quantification_scenarios' => 'risk_register_id',
        'campaign_assignments' => 'business_unit_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName => $after) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'node_id')) {
                continue;
            }

            $anchor = Schema::hasColumn($tableName, $after) ? $after : null;

            Schema::table($tableName, function (Blueprint $table) use ($anchor) {
                $column = $table->foreignId('node_id')->nullable();

                if ($anchor !== null) {
                    $column->after($anchor);
                }

                // nullOnDelete, not restrict: a node being removed must not
                // block deleting it, and a risk with no node is a visible,
                // fixable state — the resolver falls back to the legacy
                // columns, so nothing goes dark.
                $column->constrained('objects')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'node_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('node_id');
            });
        }
    }
};
