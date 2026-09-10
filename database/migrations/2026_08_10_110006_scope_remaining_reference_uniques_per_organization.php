<?php

use App\Support\ReferenceCodeAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-01 TASK 3 — the three tables the earlier scoping migration missed.
 *
 * 2026_08_09_100002 converted control_tests, issues, loss_events and
 * assessment_campaigns from a global unique index to
 * UNIQUE(organization_id, code). near_misses, quantification_scenarios and
 * simulation_runs still carry a globally unique index, which means
 * organization B's NM-2026-0001 fails to insert because organization A already
 * has one.
 *
 * Each table is checked for duplicates first (ReferenceCodeAudit). A table
 * with collisions is left exactly as it is and reported, rather than the
 * migration dying part-way through a production deploy — run
 * `php artisan reference-codes:audit`, fix the data, and migrate again.
 */
return new class extends Migration
{
    /**
     * table => [code column, legacy unique index name, new index name]
     *
     * The new names are given explicitly because Laravel's generated name for
     * quantification_scenarios would be
     * `quantification_scenarios_organization_id_scenario_reference_unique` —
     * 65 characters, one over MySQL's 64-character identifier limit. SQLite
     * accepts it happily, so the migration passes locally and dies on the
     * production database. Naming them removes the whole class of problem.
     */
    private const TARGETS = [
        'near_misses' => [
            'reference',
            'near_misses_reference_unique',
            'near_misses_org_reference_unique',
        ],
        'quantification_scenarios' => [
            'scenario_reference',
            'quantification_scenarios_scenario_reference_unique',
            'quant_scenarios_org_reference_unique',
        ],
        'simulation_runs' => [
            'simulation_reference',
            'simulation_runs_simulation_reference_unique',
            'simulation_runs_org_reference_unique',
        ],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => [$column, $legacyIndex, $newIndex]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (! ReferenceCodeAudit::canEnforceUnique($table, $column)) {
                logger()->error(
                    'Skipped composite unique index: duplicate reference codes exist within an organization',
                    ['table' => $table, 'column' => $column, 'remedy' => 'php artisan reference-codes:audit']
                );

                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $column, $legacyIndex, $newIndex) {
                if ($this->hasIndex($table, $legacyIndex)) {
                    $t->dropUnique($legacyIndex);
                }

                if (! $this->hasIndex($table, $newIndex)) {
                    $t->unique(['organization_id', $column], $newIndex);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => [$column, $legacyIndex, $newIndex]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $column, $legacyIndex, $newIndex) {
                if ($this->hasIndex($table, $newIndex)) {
                    $t->dropUnique($newIndex);
                }

                if (! $this->hasIndex($table, $legacyIndex)) {
                    $t->unique($column, $legacyIndex);
                }
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $i) => $i['name'] === $index);
    }
};
