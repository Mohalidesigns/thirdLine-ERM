<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference codes are now generated per organization (see ReferenceCodeService),
 * so a globally unique index on the code column would make organization B's
 * insert fail because organization A already used LE-2026-0001.
 *
 * risks, controls and key_risk_indicators already declare
 * unique(organization_id, code). These four tables were still globally unique;
 * bring them in line.
 *
 * No column is added, removed or retyped — only index shape changes. Existing
 * rows cannot violate the composite index, because anything unique globally is
 * unique within an organization too.
 */
return new class extends Migration
{
    /**
     * table => [code column, legacy unique index name]
     */
    private const TARGETS = [
        'control_tests' => ['test_code', 'control_tests_test_code_unique'],
        'issues' => ['issue_reference', 'issues_issue_reference_unique'],
        'loss_events' => ['event_reference', 'loss_events_event_reference_unique'],
        'assessment_campaigns' => ['campaign_code', 'assessment_campaigns_campaign_code_unique'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => [$column, $legacyIndex]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $column, $legacyIndex) {
                if ($this->hasIndex($table, $legacyIndex)) {
                    $t->dropUnique($legacyIndex);
                }

                if (! $this->hasIndex($table, "{$table}_organization_id_{$column}_unique")) {
                    $t->unique(['organization_id', $column]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => [$column, $legacyIndex]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $column, $legacyIndex) {
                if ($this->hasIndex($table, "{$table}_organization_id_{$column}_unique")) {
                    $t->dropUnique(['organization_id', $column]);
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
