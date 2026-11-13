<?php

namespace Tests\Feature\Sm;

use App\Models\Sm\BusinessLineAllocation;
use App\Models\Sm\FrequencyDriver;
use App\Models\Sm\ImpactCriterion;
use App\Models\Sm\ImpactType;
use App\Models\Sm\InsuranceCoverage;
use App\Models\Sm\Note;
use App\Models\Sm\Scenario;
use App\Models\Sm\ScenarioAction;
use App\Models\Sm\ScenarioCause;
use App\Models\Sm\ScenarioVariant;
use App\Models\Sm\Scorecard;
use App\Models\Sm\ScorecardComponent;
use App\Models\Sm\Signoff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The schema freeze, asserted against the live database (gate checklist §A).
 *
 * ADR 0027 Amendment 1 is a COLUMN-LEVEL FREEZE: "A column not named here does not exist in
 * Phase 1." §A1.13 lists what was deferred and to which phase, and adds that "a Phase 1
 * migration containing one of these is a scope breach and gate 2 should fail it". BCMS standing
 * rule 2 — adopted for Sm by the amendment — says a phase that needs a column raises a numbered
 * ADR rather than adding it and mentioning it in a commit body.
 *
 * SO THIS TEST IS THE FREEZE'S ENFORCEMENT. It fails in BOTH directions: a column the freeze does
 * not name (scope creep, including anything from §A1.13's deferred list) and a column the freeze
 * names that went missing (a rename or a dropped line, which standard §6 records as having shipped
 * repeatedly behind `?? '-'`). It reads the LIVE schema rather than the migration file, so it
 * catches what the database actually holds on both engines rather than what one file says.
 *
 * It also pins `$fillable` against the column list, which is the write-path half of standard §6:
 * a column that exists but is not fillable silently drops on every `create()`, and a fillable key
 * with no column throws on MySQL while SQLite ignores it.
 */
class SmSchemaFreezeTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /** @var array<string, string> model class => table */
    private const MODELS = [
        Scenario::class => 'sm_scenarios',
        ScenarioCause::class => 'sm_scenario_causes',
        BusinessLineAllocation::class => 'sm_business_line_allocations',
        Signoff::class => 'sm_scenario_signoffs',
        Note::class => 'sm_scenario_notes',
        Scorecard::class => 'sm_scorecards',
        ScorecardComponent::class => 'sm_scorecard_components',
        FrequencyDriver::class => 'sm_frequency_drivers',
        ImpactType::class => 'sm_impact_types',
        ImpactCriterion::class => 'sm_impact_criteria',
        InsuranceCoverage::class => 'sm_insurance_coverages',
        ScenarioVariant::class => 'sm_scenario_variants',
        ScenarioAction::class => 'sm_scenario_actions',
    ];

    /** Columns Laravel manages and that are therefore never in `$fillable`. */
    private const NON_FILLABLE = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'];

    /* ------------------------------------------------------------------ */
    /*  §A1.1 / §A2.1 / §A3.1 — the table boundary */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function exactly_the_thirteen_frozen_tables_exist_and_nothing_more(): void
    {
        // §A1.1 / §A2.1 / §A3.1: Phase 1, 2 & 3 freeze creates exactly the thirteen tables.
        // `sm_scenario_exposures` is REFUSED, not postponed — "a 1:1 child is a join with
        // nothing on the far side" — and Phase 3+ tables remain deferred.
        $expected = array_values(self::MODELS);
        sort($expected);

        $actual = collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table) => str_starts_with($table, 'sm_'))
            // unique(): MariaDB's information_schema can surface a table more than once through
            // Laravel's getTables(); values() after sort() because Collection::sort() preserves
            // keys, and assertSame on two arrays with different keys fails on the keys alone.
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual);

        $this->assertFalse(Schema::hasTable('sm_scenario_exposures'), 'refused outright by §A1.1, not merely deferred');
    }

    #[Test]
    public function none_of_the_deferred_tables_or_objects_exists(): void
    {
        // Phase 4+ deferred list.
        foreach ([
            'sm_variant_sections',
            'sm_variant_owners', 'sm_scenario_risks', 'sm_signoff_warnings',
            'sm_settings',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} is not an approved table");
        }
    }

    #[Test]
    public function no_deferred_column_landed_on_a_frozen_table(): void
    {
        // The other half of §A1.13: deferred COLUMNS, each named with the phase that needs it.
        $deferred = [
            'sm_scenarios' => [
                'entity_id',                    // Phase 4 (§A1.3)
                'loss_event_id', 'key_risk_indicator_id', 'emerging_risk_id', // Phase 4 (plan §4.4)
                'quantification_scenario_id',   // Phase 4 (D5 stamps provenance the other way)
                'lock_version', 'version', 'supersedes_id',
                // The exposures are COLUMNS, not a table (§A1.1), but every computed figure is
                // Phase 2's and lives on Phase 2 tables, never here.
                'frequency_lambda', 'gross_impact', 'net_impact', 'global_score',
            ],
            'sm_business_line_allocations' => ['business_process_id'], // Phase 4+ (§A1.5)
            'sm_scenario_signoffs' => ['is_current'], // Refused
        ];

        foreach ($deferred as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(
                    Schema::hasColumn($table, $column),
                    "{$table}.{$column} is deferred by §A1.13 and must not exist"
                );
            }
        }

        // `is_current` specifically: §A1.6 REFUSED it as "a second source of truth that drifts on
        // the third revalidation". The current row per source is a query, not a flag.
        $this->assertFalse(Schema::hasColumn('sm_scenario_signoffs', 'is_current'));
    }

    /* ------------------------------------------------------------------ */
    /*  §A1.2–§A1.7 — the column lists */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function sm_scenarios_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_scenarios', [
            'id', 'uuid', 'organization_id', 'reference', 'name', 'nature', 'status',
            'risk_id', 'risk_taxonomy_node_id', 'business_unit_id', 'weighting', 'currency',
            'fx_rate_id', 'fx_rate', 'fx_rate_date',
            'designed_on', 'validated_on', 'review_due_on',
            'background', 'rationale',
            'reputational_exposure', 'legal_compliance_exposure',
            'insurance_risk', 'financial_risk', 'eligible_to_transfer',
            'ai_generated', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
        ]);
    }

    #[Test]
    public function sm_scenario_causes_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_scenario_causes', [
            'id', 'organization_id', 'sm_scenario_id', 'side', 'cause_group', 'code',
            'is_selected', 'note', 'sort_order', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_business_line_allocations_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_business_line_allocations', [
            'id', 'organization_id', 'sm_scenario_id', 'business_unit_id', 'percent',
            'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_scenario_signoffs_has_exactly_the_frozen_columns_including_the_addendum_one_addition(): void
    {
        // `warnings_acknowledged` is addendum-1's addition, placed AFTER `comment` as the
        // addendum specifies. It is the one column Phase 1 added to a frozen table, and it did so
        // through a numbered correction rather than a quiet migration — which is the rule working.
        $this->assertColumns('sm_scenario_signoffs', [
            'id', 'organization_id', 'sm_scenario_id', 'source', 'user_id',
            'signatory_name', 'role', 'decision', 'is_independent_challenge', 'comment',
            'warnings_acknowledged', 'requested_at', 'requested_by', 'signed_at', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_scenario_notes_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_scenario_notes', [
            'id', 'organization_id', 'sm_scenario_id', 'kind', 'author_id', 'author_name',
            'body', 'is_ai_generated', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_scenario_variants_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_scenario_variants', [
            'id', 'organization_id', 'sm_scenario_id', 'tier', 'name', 'narrative',
            'is_cumulative', 'triggers', 'operational_impacts', 'responses', 'response_owners',
            'sort_order', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_scenario_actions_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_scenario_actions', [
            'id', 'organization_id', 'sm_scenario_id', 'kind', 'time_horizon', 'risk_area',
            'current_position', 'identified_gap', 'action_plan', 'business_unit_id', 'department',
            'owner_id', 'due_date', 'status', 'completed_at', 'proof_of_closure', 'issue_id',
            'sort_order', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_scorecards_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_scorecards', [
            'id', 'organization_id', 'sm_scenario_id', 'control_nature',
            'total_weight', 'total_weighted_score', 'global_score',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_scorecard_components_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_scorecard_components', [
            'id', 'organization_id', 'sm_scorecard_id', 'component_code',
            'efficiency_level', 'weighting_factor', 'score',
            'level_score', 'adjusted_score', 'weighting_score', 'weighted_score',
            'notes', 'sort_order', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_frequency_drivers_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_frequency_drivers', [
            'id', 'organization_id', 'sm_scenario_id', 'driver_type', 'criterion',
            'computation_role', 'value', 'data_source', 'sm_scorecard_id',
            'sort_order', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_impact_types_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_impact_types', [
            'id', 'organization_id', 'sm_scenario_id', 'name', 'aggregation_template',
            'eligible_to_transfer', 'sort_order', 'typical_total', 'serious_total', 'extreme_total',
            'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_impact_criteria_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_impact_criteria', [
            'id', 'organization_id', 'sm_impact_type_id', 'label', 'unit',
            'computation_role', 'data_source', 'typical_value', 'serious_value', 'extreme_value',
            'sort_order', 'created_at', 'updated_at',
        ]);
    }

    #[Test]
    public function sm_insurance_coverages_has_exactly_the_frozen_columns(): void
    {
        $this->assertColumns('sm_insurance_coverages', [
            'id', 'organization_id', 'sm_scenario_id', 'severity',
            'transferable_amount', 'deductible_local', 'limit_local', 'deductible_group', 'limit_group',
            'recovery_rate', 'probability_of_cover', 'insurer_default_haircut',
            'residual_amount', 'total_haircut', 'haircut_impact', 'gross_impact', 'net_impact',
            'created_at', 'updated_at',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Nullability and defaults — the parts that carry meaning */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function the_two_assessment_flags_are_nullable_and_the_transfer_flag_is_not(): void
    {
        // §A1.2's most consequential nullability decision. `insurance_risk` and `financial_risk`
        // are NULLABLE because the workbook dropdowns offer a blank and `->default(false)` "would
        // render 'not yet assessed' as 'No' on a governance screen". `eligible_to_transfer` is NOT
        // NULL default false because its dropdown is only 0/1 and Phase 2's netting
        // (`transferable = Σ type_total × eligible_flag`) "needs a value — NULL would be a third
        // case in a two-case formula".
        $this->assertTrue($this->column('sm_scenarios', 'insurance_risk')['nullable']);
        $this->assertNull($this->defaultOf('sm_scenarios', 'insurance_risk'));
        $this->assertTrue($this->column('sm_scenarios', 'financial_risk')['nullable']);
        $this->assertNull($this->defaultOf('sm_scenarios', 'financial_risk'));

        $eligible = $this->column('sm_scenarios', 'eligible_to_transfer');
        $this->assertFalse($eligible['nullable']);
        $this->assertSame(0, (int) $this->defaultOf('sm_scenarios', 'eligible_to_transfer'));
    }

    #[Test]
    public function weighting_is_nullable_with_no_default_and_nature_is_not_null_defaulting_to_local(): void
    {
        // §A1.2: weighting "is a judgement, and `->default('medium_low')` would be a fabricated
        // figure on every scenario nobody has weighted" — standard §5. `nature` DOES default,
        // because it is "a classification the designer knows at creation, where `local` is the
        // narrowest claim — the shape of risks.status defaulting 'draft'".
        $weighting = $this->column('sm_scenarios', 'weighting');
        $this->assertTrue($weighting['nullable']);
        $this->assertNull($this->defaultOf('sm_scenarios', 'weighting'), 'a default here would be a fabricated figure');

        $nature = $this->column('sm_scenarios', 'nature');
        $this->assertFalse($nature['nullable']);
        $this->assertSame('local', (string) $this->defaultOf('sm_scenarios', 'nature'));

        $status = $this->column('sm_scenarios', 'status');
        $this->assertFalse($status['nullable']);
        $this->assertSame('draft', (string) $this->defaultOf('sm_scenarios', 'status'));

        $currency = $this->column('sm_scenarios', 'currency');
        $this->assertFalse($currency['nullable'], 'a currency-less scenario cannot be converted');
        $this->assertSame('NGN', (string) $this->defaultOf('sm_scenarios', 'currency'));
    }

    #[Test]
    public function the_day_columns_are_dates_and_the_instant_column_is_a_nullable_datetime(): void
    {
        // §A1.2 + ADR 0026's suffix rule: `_on` is an Africa/Lagos calendar DAY, `_at` an
        // instant. Standard §13: an event time the application writes is `dateTime()`, nullable,
        // never a bare `timestamp()` and never `useCurrentOnUpdate()`.
        foreach (['fx_rate_date', 'designed_on', 'validated_on', 'review_due_on'] as $column) {
            $this->assertSame('date', $this->column('sm_scenarios', $column)['type_name'], $column);
            $this->assertTrue($this->column('sm_scenarios', $column)['nullable'], $column);
        }

        $signedAt = $this->column('sm_scenario_signoffs', 'signed_at');
        $this->assertSame('datetime', $signedAt['type_name']);
        $this->assertTrue($signedAt['nullable'], '§A1.6 + standard §13');
        $this->assertNull($this->defaultOf('sm_scenario_signoffs', 'signed_at'));
    }

    #[Test]
    public function the_sign_off_snapshots_are_not_null_and_the_signer_is_optional(): void
    {
        // §A1.6: `signatory_name` and `role` are NOT NULL snapshots because `user_id` is
        // nullOnDelete, and "a sign-off whose signatory has left must still say who signed it.
        // 'Signed by (deleted user) 4' is not evidence."
        $this->assertFalse($this->column('sm_scenario_signoffs', 'signatory_name')['nullable']);
        $this->assertFalse($this->column('sm_scenario_signoffs', 'role')['nullable']);
        $this->assertTrue($this->column('sm_scenario_signoffs', 'user_id')['nullable']);

        // Widths sized for the values they hold (ADR 0014).
        $this->assertSame(200, $this->declaredLength('sm_scenario_signoffs', 'signatory_name'));
        $this->assertSame(100, $this->declaredLength('sm_scenario_signoffs', 'role'));
        $this->assertSame(40, $this->declaredLength('sm_scenarios', 'reference'));
        $this->assertSame(250, $this->declaredLength('sm_scenarios', 'name'));
        $this->assertSame(24, $this->declaredLength('sm_scenarios', 'status'));
        $this->assertSame(3, $this->declaredLength('sm_scenarios', 'currency'));
        $this->assertSame(20, $this->declaredLength('sm_scenario_notes', 'kind'));
    }

    #[Test]
    public function the_reference_column_stays_wide_for_phase_5_imports(): void
    {
        // Addendum-2: "The column stays string(40), not narrowed to 11" — `SM-2026-001` is 11
        // characters, but Phase 5 imports filled LS workbooks whose references are the customer's
        // own, and 40 matches `bcms_management_reviews.reference`.
        $this->assertSame(40, $this->declaredLength('sm_scenarios', 'reference'));
        $this->assertSame(11, strlen('SM-2026-001'), 'the generated form is 11 characters, the column is not');
    }

    #[Test]
    public function no_sm_table_uses_a_database_enum(): void
    {
        // ADR 0026 §2's rule, which §A1.7 restates for `kind`: a fifth value is then a CODE
        // change, not an ALTER TABLE. The vocabularies are PHP consts and the columns are varchar.
        foreach (self::MODELS as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $this->assertStringNotContainsString(
                    'enum',
                    strtolower((string) $column['type']),
                    "{$table}.{$column['name']} must not be a DB enum"
                );
            }
        }
    }

    #[Test]
    public function no_sm_table_uses_a_check_constraint(): void
    {
        // §A1.5: "a grep for `CHECK (` across database/migrations returns NOTHING — no CHECK
        // precedent on either engine, and one here would fail a save with SQLSTATE[23000] where the
        // Form Request should have answered 422." The allocation invariant is a service rule.
        $connection = DB::connection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('CHECK-constraint introspection is engine-specific.');
        }

        foreach (array_values(self::MODELS) as $table) {
            // MariaDB exposes its json_valid() guards here too; `warnings_acknowledged` is the only
            // json column in the module and that constraint is the driver's, not ours.
            $checks = collect(Schema::getIndexes($table))
                ->filter(fn (array $index) => ($index['type'] ?? '') === 'check')
                ->pluck('name')
                ->filter(fn (?string $name) => $name !== null && ! str_contains($name, 'json_valid'))
                ->all();

            $this->assertSame([], $checks, "{$table} carries a CHECK constraint");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  The frozen index names */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_frozen_index_name_exists_on_the_table_it_was_named_for(): void
    {
        // §A1.2–§A1.7: "names frozen, abbreviated for MySQL's 64-char limit". The names are part
        // of the freeze because a renamed index silently changes what a query plan does, and
        // because §A1.5's collision-retry rule matches on `sm_scenarios_org_reference_unique` by
        // NAME — a rename would turn a retried collision into a 500.
        $expected = [
            'sm_scenarios' => [
                'sm_scenarios_uuid_unique' => ['uuid'],
                'sm_scenarios_org_reference_unique' => ['organization_id', 'reference'],
                'sm_scenarios_org_status_idx' => ['organization_id', 'status'],
                'sm_scenarios_org_taxonomy_idx' => ['organization_id', 'risk_taxonomy_node_id'],
                'sm_scenarios_org_unit_idx' => ['organization_id', 'business_unit_id'],
                'sm_scenarios_org_review_due_idx' => ['organization_id', 'review_due_on'],
            ],
            'sm_scenario_causes' => [
                'sm_causes_scenario_code_unique' => ['sm_scenario_id', 'code'],
                'sm_causes_org_scenario_idx' => ['organization_id', 'sm_scenario_id'],
            ],
            'sm_business_line_allocations' => [
                'sm_allocations_scenario_unit_unique' => ['sm_scenario_id', 'business_unit_id'],
                'sm_allocations_org_unit_idx' => ['organization_id', 'business_unit_id'],
            ],
            'sm_scenario_signoffs' => [
                'sm_signoffs_scenario_source_idx' => ['sm_scenario_id', 'source', 'signed_at'],
                'sm_signoffs_org_scenario_idx' => ['organization_id', 'sm_scenario_id'],
            ],
            'sm_scenario_notes' => [
                'sm_notes_org_scenario_kind_idx' => ['organization_id', 'sm_scenario_id', 'kind'],
            ],
        ];

        foreach ($expected as $table => $indexes) {
            $actual = collect(Schema::getIndexes($table))->keyBy('name');

            foreach ($indexes as $name => $columns) {
                $this->assertTrue($actual->has($name), "{$table} is missing the frozen index {$name}");
                $this->assertSame($columns, $actual[$name]['columns'], "{$table}.{$name} columns");
                $this->assertSame(
                    str_ends_with($name, '_unique'),
                    (bool) $actual[$name]['unique'],
                    "{$table}.{$name} uniqueness"
                );
                $this->assertLessThanOrEqual(64, strlen($name), 'MySQL caps index names at 64 characters');
            }
        }
    }

    #[Test]
    public function there_is_no_unique_key_on_scenario_and_source(): void
    {
        // §A1.6: "NO unique key on (sm_scenario_id, source), deliberately." D4 says sign-off
        // freezes and post-sign-off edits require revalidation — so source 1 signs, the scenario
        // is returned, and source 1 signs AGAIN; a unique key would force an UPDATE over the
        // record this table exists to protect.
        $twoColumnUnique = collect(Schema::getIndexes('sm_scenario_signoffs'))
            ->filter(fn (array $index) => $index['unique'] && $index['columns'] === ['sm_scenario_id', 'source']);

        $this->assertTrue($twoColumnUnique->isEmpty(), 'a unique key here would force an UPDATE over an append-only record');

        // The pair is still indexed, with signed_at, so "latest row per source" is one index scan.
        $this->assertTrue(
            collect(Schema::getIndexes('sm_scenario_signoffs'))->contains(
                fn (array $index) => $index['name'] === 'sm_signoffs_scenario_source_idx'
            )
        );
    }

    #[Test]
    public function every_foreign_key_points_where_the_freeze_says(): void
    {
        $expected = [
            'sm_scenarios' => [
                'organization_id' => 'organizations',
                'risk_id' => 'risks',
                'risk_taxonomy_node_id' => 'risk_taxonomies',
                'business_unit_id' => 'business_units',
                'fx_rate_id' => 'fx_rates',
                'created_by' => 'users',
                'updated_by' => 'users',
            ],
            'sm_scenario_causes' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
            ],
            'sm_business_line_allocations' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
                // §A1.5: business_units, NOT business_processes — and cascadeOnDelete, because a
                // NOT NULL column cannot be nulled and "40% to nobody" is worse than a missing row.
                'business_unit_id' => 'business_units',
            ],
            'sm_scenario_signoffs' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
                'user_id' => 'users',
                'requested_by' => 'users',
            ],
            'sm_scenario_notes' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
                'author_id' => 'users',
            ],
            'sm_scorecards' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
                'created_by' => 'users',
                'updated_by' => 'users',
            ],
            'sm_scorecard_components' => [
                'organization_id' => 'organizations',
                'sm_scorecard_id' => 'sm_scorecards',
            ],
            'sm_frequency_drivers' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
                'sm_scorecard_id' => 'sm_scorecards',
            ],
            'sm_impact_types' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
            ],
            'sm_impact_criteria' => [
                'organization_id' => 'organizations',
                'sm_impact_type_id' => 'sm_impact_types',
            ],
            'sm_insurance_coverages' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
            ],
            'sm_scenario_variants' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
            ],
            'sm_scenario_actions' => [
                'organization_id' => 'organizations',
                'sm_scenario_id' => 'sm_scenarios',
                'business_unit_id' => 'business_units',
                'owner_id' => 'users',
                'issue_id' => 'issues',
            ],
        ];

        foreach ($expected as $table => $columns) {
            $actual = [];

            foreach (Schema::getForeignKeys($table) as $key) {
                if (count($key['columns']) === 1) {
                    $actual[$key['columns'][0]] = $key['foreign_table'];
                }
            }

            foreach ($columns as $column => $foreignTable) {
                $this->assertSame(
                    $foreignTable,
                    $actual[$column] ?? null,
                    "{$table}.{$column} must reference {$foreignTable}"
                );
            }

            // And nothing else has a foreign key: an extra one is an extra coupling the freeze did
            // not authorise. Compared as sorted key lists, because Schema::getForeignKeys() reads
            // information_schema and returns them alphabetically, not in migration order.
            $expectedColumns = array_keys($columns);
            $actualColumns = array_keys($actual);
            sort($expectedColumns);
            sort($actualColumns);

            $this->assertSame(
                $expectedColumns,
                $actualColumns,
                "{$table} has foreign keys the freeze does not name, or is missing one it does"
            );
        }
    }

    #[Test]
    public function the_allocation_unit_cascades_and_the_signoff_signer_does_not(): void
    {
        // §A1.5: cascadeOnDelete on `business_unit_id`, because "a NOT NULL column cannot be
        // nulled, and '40% to nobody' is worse than a missing row — the missing row is caught by
        // the invariant, the meaningless one is not".
        // §A1.6: nullOnDelete on `user_id`, because the snapshot is the evidence and a departed
        // signatory must not take the sign-off with them.
        $this->assertSame(
            'cascade',
            $this->foreignKey('sm_business_line_allocations', 'business_unit_id')['on_delete']
        );
        $this->assertSame(
            'set null',
            strtolower((string) $this->foreignKey('sm_scenario_signoffs', 'user_id')['on_delete'])
        );
        $this->assertSame(
            'set null',
            strtolower((string) $this->foreignKey('sm_scenario_notes', 'author_id')['on_delete'])
        );
    }

    /* ------------------------------------------------------------------ */
    /*  §A1.2 — the aggregate root's shape */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function only_the_aggregate_root_carries_soft_deletes_and_a_uuid(): void
    {
        // Backend rule 6: bigint id plus a unique uuid ON AGGREGATE ROOTS ONLY. Child and line
        // tables carry no uuid and, per ADR 0026 §2, no soft deletes.
        $this->assertTrue(in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(Scenario::class), true));
        $this->assertTrue(Schema::hasColumn('sm_scenarios', 'deleted_at'));

        foreach ([
            ScenarioCause::class, BusinessLineAllocation::class, Signoff::class, Note::class,
            Scorecard::class, ScorecardComponent::class, FrequencyDriver::class,
            ImpactType::class, ImpactCriterion::class, InsuranceCoverage::class,
        ] as $class) {
            $this->assertNotContains(
                \Illuminate\Database\Eloquent\SoftDeletes::class,
                class_uses_recursive($class),
                "{$class} is a child line table and must not soft-delete"
            );
        }
    }

    #[Test]
    public function every_model_targets_the_table_it_is_named_for(): void
    {
        // `Sm\Note` → `sm_scenario_notes` and `Sm\BusinessLineAllocation` →
        // `sm_business_line_allocations` are NOT Laravel's default guesses, so an explicit $table
        // is load-bearing. A missing one would point the model at a table that does not exist,
        // and on SQLite nothing would complain until a query ran.
        foreach (self::MODELS as $class => $table) {
            $this->assertSame($table, (new $class)->getTable(), "{$class} must target {$table}");
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Standard §6 — the write path against the table */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function every_models_fillable_matches_its_column_list_exactly(): void
    {
        // BOTH HALVES MATTER, and both have shipped broken here. A fillable key with no column
        // throws on MySQL while SQLite ignores it; a column missing from fillable silently drops
        // on every create() and update(), which is how nine columns ended up rendered behind
        // `?? '-'` forever with nothing failing.
        foreach (self::MODELS as $class => $table) {
            $model = new $class;

            $columns = collect(Schema::getColumns($table))
                ->pluck('name')
                ->reject(fn (string $name) => in_array($name, self::NON_FILLABLE, true))
                ->values()
                ->all();

            $fillable = $model->getFillable();

            sort($columns);
            sort($fillable);

            $this->assertSame($columns, $fillable, "{$class}::\$fillable must match {$table}'s columns exactly");
        }
    }

    #[Test]
    public function no_model_declares_a_guarded_hole_beside_its_fillable(): void
    {
        // `$guarded = []` beside a `$fillable` list is how a mass-assignment hole opens: the two
        // interact in a way nobody reads twice, and `reference` / `status` / `validated_on` are
        // exactly the columns that must not be writable from a request.
        foreach (array_keys(self::MODELS) as $class) {
            $this->assertSame(
                ['*'],
                (new $class)->getGuarded(),
                "{$class} must keep Laravel's default guarded so \$fillable is the whole contract"
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Portability */
    /* ------------------------------------------------------------------ */

    #[Test]
    public function nothing_in_the_module_uses_sql_outside_the_engine_intersection(): void
    {
        // §A1.12: "no CHECK, no generated column, no timestampTz, no useCurrentOnUpdate". The
        // suite runs on MariaDB 10.4 locally and on BOTH that and MySQL 8.0.46 in CI, so a query
        // one engine tolerates is a red CI leg rather than a local failure. Generated columns and
        // self-updating timestamps are the two shapes that differ most between them.
        foreach (array_values(self::MODELS) as $table) {
            foreach (Schema::getColumns($table) as $column) {
                $this->assertFalse(
                    (bool) ($column['auto_increment'] ?? false) && $column['name'] !== 'id',
                    "{$table}.{$column['name']} must not be a second auto-increment"
                );

                // MariaDB reports generated columns through `extra`; MySQL 8 through the same key
                // with different wording, so both spellings are checked.
                $extra = strtolower((string) ($column['extra'] ?? ''));
                $this->assertStringNotContainsString('generated', $extra, "{$table}.{$column['name']} is generated");
                $this->assertStringNotContainsString('virtual', $extra, "{$table}.{$column['name']} is virtual");
                $this->assertStringNotContainsString('on update', $extra, "{$table}.{$column['name']} self-updates");
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<string>  $expected
     */
    private function assertColumns(string $table, array $expected): void
    {
        $actual = collect(Schema::getColumns($table))->pluck('name')->all();

        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "{$table}'s columns differ from the freeze. An extra one is scope creep (§A1.13); a missing one is a rename or a dropped line."
        );
    }

    /** @return array<string, mixed> */
    private function column(string $table, string $column): array
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return $definition;
            }
        }

        $this->fail("{$table}.{$column} does not exist.");
    }

    /**
     * A column's default, with the engines' two spellings normalised.
     *
     * BOTH ARE CROSS-ENGINE ASYMMETRIES, and this suite runs on MariaDB 10.4 and MySQL 8.0.46:
     *
     *   1. MariaDB reports the STRING 'NULL' for a nullable column with no default, where MySQL
     *      reports an actual null. `Tests\Support\TenantFixture` normalises the same thing for
     *      the same reason.
     *   2. MariaDB returns a string default WITH its quotes — `'local'` — where MySQL returns
     *      `local`. Without stripping them the assertion passes on one CI leg and fails on the
     *      other, which is worse than not asserting at all: it reads as a real schema drift.
     */
    private function defaultOf(string $table, string $column): mixed
    {
        $default = $this->column($table, $column)['default'] ?? null;

        if ($default === 'NULL' || $default === '') {
            return null;
        }

        if (is_string($default) && strlen($default) >= 2 && str_starts_with($default, "'") && str_ends_with($default, "'")) {
            return substr($default, 1, -1);
        }

        return $default;
    }

    /** @return array<string, mixed> */
    private function foreignKey(string $table, string $column): array
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return $key;
            }
        }

        $this->fail("{$table}.{$column} has no foreign key.");
    }

    private function declaredLength(string $table, string $column): ?int
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] !== $column) {
                continue;
            }

            if (preg_match('/\((\d+)\)/', (string) $definition['type'], $matches)) {
                return (int) $matches[1];
            }

            return null;
        }

        $this->fail("{$table}.{$column} does not exist.");
    }
}
