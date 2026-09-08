<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ThirdLine\Platform\Tenancy\BelongsToOrganization;

/**
 * A row with a NULL `organization_id` belongs to everybody or to nobody, and
 * which one it is has to be a decision somebody made.
 *
 * WHY THIS TEST EXISTS. `OrganizationScope` hides a NULL-tenant row from every
 * tenant-scoped query unless its model declares `$tenantIncludesGlobal = true`.
 * That is the right default — a row that lost its tenant must not leak into all
 * of them — but it fails SILENTLY and in the most misleading direction: the row
 * is still there, relationships still traverse it, and only a query through the
 * model itself comes back empty.
 *
 * P8 found twelve such rows in `risk_control_mapping`. Nothing looked broken:
 * `$risk->controls()` returned controls, because the scope applies to the
 * CONTROLS table rather than to the pivot. What was broken was every screen
 * querying the pivot as a model — the RCSA matrix CSV downloaded as a header
 * with no rows, and the shared-controls analysis rendered a page reporting that
 * no controls are shared between any risks. The second is the dangerous shape:
 * zeros that read as a FINDING rather than an error, on a page whose own
 * comment says every figure is a count of rows in that table.
 *
 * THE RULE, STATED ONCE: a table holding NULL-organization rows must have a
 * model that declares them global. Several tables legitimately do — they hold
 * shared system content, and backfilling a tenant onto those would be the
 * opposite bug. Anything else is an orphan.
 *
 * IT IS A SCHEMA AND DECLARATION TEST, NOT A PRODUCTION DATA TEST. It runs
 * against the migrated test database, so it cannot see a deployment's rows.
 * What it pins is that every model whose table can hold NULLs has made the
 * declaration deliberately. For a real deployment the companion check is one
 * query per table:
 *
 *     select count(*) from <table> where organization_id is null
 */
class NullOrganizationRowsAreDeclaredGlobalTest extends TestCase
{
    // WITHOUT THIS, EVERY ASSERTION BELOW PASSES BY LOOKING AT NOTHING. No
    // migrations means Schema::hasTable() is false for every table and each
    // loop skips its whole body — four green tests that inspected zero tables.
    // The schema is the subject here, so it has to exist.
    use RefreshDatabase;

    /**
     * Models whose table carries `organization_id`, keyed by table.
     *
     * Discovered rather than listed: a list is one more thing to forget to
     * update, and catching what somebody forgot is the whole point.
     *
     * @return array<string, class-string<Model>>
     */
    private function tenantModels(): array
    {
        $models = [];

        $files = array_merge(
            glob(app_path('Models/*.php')) ?: [],
            glob(app_path('Models/*/*.php')) ?: [],
        );

        foreach ($files as $file) {
            $class = 'App\\Models\\'.Str::of(Str::after($file, app_path('Models/')))
                ->replace('/', '\\')
                ->replace('.php', '')
                ->toString();

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (! in_array(BelongsToOrganization::class, class_uses_recursive($class), true)) {
                continue;
            }

            $models[(new $class)->getTable()] = $class;
        }

        return $models;
    }

    /**
     * The guard on the guard.
     *
     * Both halves matter: models discovered, AND their tables actually migrated.
     * The first version of this suite ran without RefreshDatabase and four of
     * its five tests passed green having examined nothing at all.
     */
    #[Test]
    public function the_discovery_finds_the_models_and_their_tables_exist(): void
    {
        $models = $this->tenantModels();

        $this->assertGreaterThan(50, count($models), 'Model discovery found almost nothing.');

        $present = array_filter(array_keys($models), fn (string $table) => Schema::hasTable($table));

        $this->assertGreaterThan(
            50,
            count($present),
            'The tenant tables are not migrated, so every check below would skip its whole body.',
        );
    }

    /**
     * Every model using the tenancy trait sits on a table that has the column.
     *
     * The inverse of the main test, and worth distinguishing: a model scoping
     * on a column its table lacks throws on every query rather than quietly
     * returning the wrong rows.
     */
    #[Test]
    public function every_tenant_scoped_model_has_the_column_it_scopes_on(): void
    {
        $missing = [];

        foreach ($this->tenantModels() as $table => $class) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'organization_id')) {
                $missing[] = "{$class} scopes on {$table}.organization_id, which does not exist.";
            }
        }

        $this->assertSame([], $missing, implode("\n", $missing));
    }

    /**
     * THE RULE. A table holding NULL-organization rows must declare them global.
     */
    #[Test]
    public function a_table_holding_null_organization_rows_declares_them_global(): void
    {
        $undeclared = [];

        foreach ($this->tenantModels() as $table => $class) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            $nulls = DB::table($table)->whereNull('organization_id')->count();

            if ($nulls === 0 || (new $class)->tenantIncludesGlobalRecords()) {
                continue;
            }

            $undeclared[] = sprintf(
                '%s holds %d row(s) with a NULL organization_id but %s does not declare '
                .'$tenantIncludesGlobal. Those rows are invisible to every tenant-scoped query, '
                .'while relationships through them keep working — so nothing looks broken.',
                $table,
                $nulls,
                $class,
            );
        }

        $this->assertSame([], $undeclared, implode("\n", $undeclared));
    }

    /**
     * The models that currently declare NULL to mean "shared with every
     * tenant", pinned so that changing the set is a deliberate act.
     *
     * NOT A STYLE ASSERTION. Dropping `$tenantIncludesGlobal` from any of these
     * hides the shared content it governs from every tenant at once — the RCSA
     * system methodology, the object-type registry, the default dashboard
     * widgets. Each is a whole feature going blank, and none would fail loudly.
     * Adding it to a model that holds tenant data is the opposite and worse:
     * one tenant's rows shown to all of them.
     */
    #[Test]
    public function the_models_that_share_rows_across_tenants_are_the_expected_ones(): void
    {
        $global = [];

        foreach ($this->tenantModels() as $table => $class) {
            if ((new $class)->tenantIncludesGlobalRecords()) {
                $global[] = $table;
            }
        }

        sort($global);

        $this->assertSame([
            // BCMS reference libraries, added in Phase 0. Each ships rows with
            // the product — the exercise-type catalogue and its ISO 22398
            // ladder, the readiness checklists, the Nigerian blackout calendar,
            // the alert templates in English and Pidgin, the scenario library,
            // the training curricula — readable by every tenant and editable by
            // none. This declaration is the mechanism ADR 0006 requires, and
            // dropping it from any of them would hide a shipped library from
            // every tenant while the seeder kept writing to it: the exact
            // silent disagreement this whole test exists to catch.
            //
            // `bcms_clause_refs` is deliberately absent — it has no
            // `organization_id` column at all, because ISO 22301 clause 8.5
            // does not vary by customer and a per-tenant copy would let one
            // tenant's edit change what a clause means in their evidence pack.
            'bcms_alert_templates',
            'bcms_blackout_periods',
            'bcms_exercise_types',
            'bcms_readiness_template_tasks',
            'bcms_readiness_templates',
            'bcms_scenarios',
            'bcms_training_curricula',

            'dashboards',
            'fx_rates',
            'object_lifecycles',
            'object_relationship_types',
            'object_types',
            'question_library',
            'rcsa_methodologies',
            'risk_cause_categories',
            'scoring_profiles',
            'widget_definitions',
        ], $global, 'A model started or stopped sharing rows across tenants. If deliberate, update this list and '
            .'say why — it means shared content appearing in, or vanishing from, every tenant at once.');
    }

    /**
     * `risk_control_mapping` specifically, because it is the one that was wrong.
     */
    #[Test]
    public function the_risk_control_pivot_is_not_global_and_holds_no_orphans(): void
    {
        $mapping = new \App\Models\RiskControlMapping;

        $this->assertFalse(
            $mapping->tenantIncludesGlobalRecords(),
            'risk_control_mapping must NOT be global: a mapping belongs to the tenant that owns its risk, and '
            .'declaring it global would show every tenant every other tenant\'s control mappings.',
        );

        $this->assertSame(0, DB::table('risk_control_mapping')->whereNull('organization_id')->count());
    }
}
