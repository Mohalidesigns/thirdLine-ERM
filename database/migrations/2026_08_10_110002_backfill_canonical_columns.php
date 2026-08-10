<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-01 TASK 1, Migration A — backfill + single writer.
 *
 * Migration 200038 duplicated whole field sets instead of renaming them, so
 * treatment_plans, loss_events, issues and loss_event_rca each ended up with
 * two columns per concept: the original, and the one the controllers wrote.
 * This migration copies the controller-written values back onto the original
 * (canonical) columns wherever the canonical column is empty; from this
 * release the application reads and writes the canonical column only.
 *
 * Every decision — which column won, and why — is recorded in
 * docs/schema/canonical-columns.md. Migration B drops the duplicates in the
 * next release; nothing is dropped here.
 *
 * Case normalisation is part of the backfill, not cosmetic. The controllers
 * wrote basel_l1_category in lower case while RegulatoryThresholdService
 * matches INTERNAL_FRAUD / EXTERNAL_FRAUD, so historic loss events never
 * raised their NFIU STR, EFCC or cyber-fraud law-enforcement alerts. Upper
 * casing the stored values makes those events classify correctly from now on.
 */
return new class extends Migration
{
    /**
     * canonical <= duplicate, per table.
     *
     * @var array<string, array<string, string>>
     */
    private const PAIRS = [
        'treatment_plans' => [
            'action_title' => 'treatment_title',
            'action_description' => 'treatment_description',
            'strategy' => 'treatment_type',
            'owner_id' => 'treatment_owner_id',
            'target_date' => 'target_completion_date',
            'completion_date' => 'actual_completion_date',
            'cost_estimate_ngn' => 'estimated_cost',
            'actual_cost_ngn' => 'actual_cost',
            'progress_pct' => 'progress_percentage',
            'progress_notes' => 'implementation_notes',
        ],
        'loss_events' => [
            'title' => 'event_title',
            'description' => 'event_description',
            'current_status' => 'status',
            'basel_l1_category' => 'basel_event_type',
            'cbn_risk_category' => 'cbn_loss_category',
            'loss_category' => 'event_type',
            'event_severity' => 'severity',
            'initial_root_cause' => 'root_cause_summary',
        ],
        'issues' => [
            'title' => 'issue_title',
            'description' => 'issue_description',
            'responsible_owner_id' => 'issue_owner_id',
            'remediation_due_date' => 'target_resolution_date',
            'actual_close_date' => 'actual_resolution_date',
            'current_escalation_level' => 'escalation_level',
            'examination_ref' => 'source_reference',
        ],
        'loss_event_rca' => [
            'root_cause_statement' => 'root_cause_description',
            'completed_by' => 'performed_by',
            'completed_at' => 'analysis_date',
            'rca_status' => 'status',
        ],
    ];

    /**
     * Naira-denominated duplicate => kobo canonical. Multiplied by 100.
     *
     * @var array<string, array<string, string>>
     */
    private const MONEY_PAIRS = [
        'loss_events' => [
            'gross_loss_amount_kobo' => 'gross_loss_amount',
            'insurance_recovery_kobo' => 'insurance_recovery',
            'other_recovery_kobo' => 'recovery_amount',
        ],
    ];

    /**
     * Columns whose canonical form is upper case.
     *
     * @var array<string, list<string>>
     */
    private const UPPER_CASED = [
        'loss_events' => [
            'basel_l1_category',
            'basel_l2_category',
            'cbn_risk_category',
            'event_severity',
            'current_status',
        ],
    ];

    public function up(): void
    {
        foreach (self::PAIRS as $table => $pairs) {
            foreach ($pairs as $canonical => $duplicate) {
                $this->backfill($table, $canonical, $duplicate);
            }
        }

        foreach (self::MONEY_PAIRS as $table => $pairs) {
            foreach ($pairs as $canonical => $duplicate) {
                $this->backfillMoney($table, $canonical, $duplicate);
            }
        }

        $this->backfillContributoryFactors();

        foreach (self::UPPER_CASED as $table => $columns) {
            foreach ($columns as $column) {
                $this->upperCase($table, $column);
            }
        }
    }

    /**
     * Backfills are not reversible: the canonical column now holds a union of
     * both sources and there is no record of which values came from where.
     * The duplicate columns are untouched, so rolling the code back still
     * works — which is the property that actually matters.
     */
    public function down(): void
    {
        // Intentionally empty. See the docblock above.
    }

    private function backfill(string $table, string $canonical, string $duplicate): void
    {
        if (! $this->hasBoth($table, $canonical, $duplicate)) {
            return;
        }

        // "Empty" means NULL, and for text columns also the empty string:
        // 200038 defaulted several text columns to '' rather than NULL, and an
        // empty title is just as absent as a missing one. Zero is NOT empty —
        // a 0% progress figure is a real value and must not be overwritten.
        //
        // The empty-string test is applied only to text columns. Comparing a
        // numeric column to '' is a truncation error under MySQL strict mode
        // (SQLite silently coerces it), so testing every column the same way
        // passes locally and fails on the production database.
        $isText = $this->isTextColumn($table, $canonical);

        DB::table($table)
            ->whereNotNull($duplicate)
            ->where(function ($query) use ($canonical, $isText) {
                $query->whereNull($canonical);

                if ($isText) {
                    $query->orWhere($canonical, '');
                }
            })
            ->update([$canonical => DB::raw($this->quote($duplicate))]);
    }

    private function backfillMoney(string $table, string $canonical, string $duplicate): void
    {
        if (! $this->hasBoth($table, $canonical, $duplicate)) {
            return;
        }

        // The kobo columns default to 0, so "not yet set" and "genuinely zero"
        // are indistinguishable. Only rows where the naira column carries a
        // non-zero amount and the kobo column is still 0 can safely be filled.
        DB::table($table)
            ->whereNotNull($duplicate)
            ->where($duplicate, '>', 0)
            ->where(function ($query) use ($canonical) {
                $query->whereNull($canonical)->orWhere($canonical, 0);
            })
            ->update([$canonical => DB::raw('ROUND('.$this->quote($duplicate).' * 100)')]);
    }

    /**
     * contributing_factors_text (free text) => contributory_factors (JSON).
     *
     * Wrapped in a single-element array rather than split on punctuation:
     * inventing a delimiter would silently fabricate structure that the
     * original text never had.
     */
    private function backfillContributoryFactors(): void
    {
        if (! $this->hasBoth('loss_event_rca', 'contributory_factors', 'contributing_factors_text')) {
            return;
        }

        DB::table('loss_event_rca')
            ->whereNotNull('contributing_factors_text')
            ->where('contributing_factors_text', '!=', '')
            ->whereNull('contributory_factors')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('loss_event_rca')
                    ->where('id', $row->id)
                    ->update(['contributory_factors' => json_encode([$row->contributing_factors_text])]);
            });
    }

    private function upperCase(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->whereNotNull($column)
            ->update([$column => DB::raw('UPPER('.$this->quote($column).')')]);
    }

    /**
     * Whether a column can meaningfully hold the empty string.
     */
    private function isTextColumn(string $table, string $column): bool
    {
        $type = strtolower((string) Schema::getColumnType($table, $column));

        foreach (['char', 'text', 'string', 'enum'] as $textish) {
            if (str_contains($type, $textish)) {
                return true;
            }
        }

        return false;
    }

    private function hasBoth(string $table, string $canonical, string $duplicate): bool
    {
        return Schema::hasTable($table)
            && Schema::hasColumn($table, $canonical)
            && Schema::hasColumn($table, $duplicate);
    }

    /**
     * Identifier quoting for the raw expressions above. Column names here are
     * compile-time constants, but quoting keeps the SQL correct on drivers
     * where a name collides with a reserved word (`status`, for one).
     */
    private function quote(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }
};
