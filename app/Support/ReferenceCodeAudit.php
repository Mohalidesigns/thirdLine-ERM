<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finds reference codes that are used more than once within an organization.
 *
 * Used by two callers that must agree: the `reference-codes:audit` command,
 * and the migration that adds UNIQUE(organization_id, code). The migration
 * consults this first and declines to create the index on any table that would
 * fail, so a deploy never dies on a constraint violation in the middle of a
 * migration run — it reports instead, and the operator fixes the data.
 */
class ReferenceCodeAudit
{
    /**
     * table => code column. Every reference code the platform issues.
     */
    public const TABLES = [
        'risks' => 'risk_code',
        'controls' => 'control_code',
        'key_risk_indicators' => 'kri_code',
        'control_tests' => 'test_code',
        'loss_events' => 'event_reference',
        'near_misses' => 'reference',
        'issues' => 'issue_reference',
        'quantification_scenarios' => 'scenario_reference',
        'simulation_runs' => 'simulation_reference',
        'assessment_campaigns' => 'campaign_code',
        'treatment_plans' => 'treatment_code',
    ];

    /**
     * Duplicate (organization_id, code) pairs for one table.
     *
     * @return list<array{organization_id: int|null, code: string, occurrences: int, ids: list<int>}>
     */
    public static function collisions(string $table, string $column): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'organization_id')) {
            return [];
        }

        $duplicates = DB::table($table)
            ->select('organization_id', $column, DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull($column)
            ->groupBy('organization_id', $column)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        return $duplicates->map(fn ($row) => [
            'organization_id' => $row->organization_id,
            'code' => $row->{$column},
            'occurrences' => (int) $row->occurrences,
            'ids' => DB::table($table)
                ->where('organization_id', $row->organization_id)
                ->where($column, $row->{$column})
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        ])->all();
    }

    /**
     * Every table's collisions, keyed by table. Tables with none are omitted.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function all(): array
    {
        $report = [];

        foreach (self::TABLES as $table => $column) {
            $collisions = self::collisions($table, $column);

            if ($collisions !== []) {
                $report[$table] = $collisions;
            }
        }

        return $report;
    }

    /**
     * Whether a composite unique index can safely be created on this table.
     */
    public static function canEnforceUnique(string $table, string $column): bool
    {
        return self::collisions($table, $column) === [];
    }
}
