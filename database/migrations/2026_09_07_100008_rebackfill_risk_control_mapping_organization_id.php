<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `risk_control_mapping` rows still holding a NULL organization_id, repaired.
 *
 * WHY A SECOND BACKFILL. `2026_08_09_100001_backfill_organization_id_for_tenancy`
 * already does this, and it is correct — but a migration runs once. It ran in
 * batch 1 on a database whose mapping rows had not been created yet, found
 * nothing to repair, and was marked done. The rows arrived afterwards from a
 * seeder that did not set the column, and nothing has looked since.
 *
 * The write paths are FINE and this is not a code fix. Both of them stamp the
 * column today — the model's `creating` hook (BelongsToOrganization) fills it
 * from TenantContext, `attach()` goes through the same model because the
 * belongsToMany declares `->using(RiskControlMapping::class)`, and the demo
 * seeder passes it explicitly. This repairs data those paths left behind
 * before they were fixed.
 *
 * WHY IT MATTERS MORE THAN A NULL COLUMN USUALLY WOULD. `RiskControlMapping`
 * does NOT declare `$tenantIncludesGlobal`, so OrganizationScope treats a NULL
 * row as belonging to no tenant and hides it from every tenant-scoped query.
 * The relationship still works — `$risk->controls()` scopes the CONTROLS table,
 * not the pivot — so nothing looks broken until something queries the pivot as
 * a model. Two things do:
 *
 *   - `ExportController::rcsaMatrix()` — the RCSA risk-control matrix CSV,
 *     which downloads as an empty file.
 *   - `AnalysisController` — the shared-controls analysis, whose own comment
 *     says "every figure on this page is a count of rows in this table". It
 *     counts zero and renders a page reporting that no controls are shared
 *     between any risks. That reads as a FINDING rather than an error, which
 *     is the worse of the two failures: nobody doubts it.
 *
 * SCOPE IS DELIBERATELY ONE TABLE. Seven other tables hold NULL organization_id
 * rows and every one of them is legitimate — ObjectType, ObjectLifecycle,
 * ObjectRelationshipType, RcsaMethodology, RiskCauseCategory, ScoringProfile
 * and WidgetDefinition all declare `$tenantIncludesGlobal = true`, which is the
 * model saying "NULL here means shared with every tenant". `risk_control_mapping`
 * is the only table whose NULLs mean orphaned rather than global, and widening
 * this migration would stamp a tenant onto records that are deliberately
 * tenant-less.
 *
 * FORWARD-ONLY AND IDEMPOTENT. It touches only NULL rows, derives the value
 * from the mapping's own risk, and overwrites nothing. Running it twice does
 * nothing the second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('risk_control_mapping', 'organization_id')) {
            return;
        }

        // The correlated subquery form, as the original backfill used: it is
        // one statement on both MySQL and SQLite, where an UPDATE ... JOIN is
        // spelled differently on each.
        DB::table('risk_control_mapping')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select organization_id from risks where risks.id = risk_control_mapping.risk_id)'
                ),
            ]);

        // A mapping whose risk carries no tenant either would still be NULL and
        // still be invisible. There is no correct value to invent for it, so it
        // is reported rather than guessed at — a row a person has to look at.
        $orphaned = DB::table('risk_control_mapping')->whereNull('organization_id')->count();

        if ($orphaned > 0) {
            logger()->warning('risk_control_mapping rows remain without an organization', [
                'count' => $orphaned,
                'why' => 'Their risk has no organization_id either. They are invisible to every tenant-scoped '
                    .'query until somebody decides which tenant they belong to.',
            ]);
        }
    }

    public function down(): void
    {
        // Forward-only, like the backfill it completes: the values written here
        // are the correct ones, and setting them back to NULL would re-hide the
        // rows from the two screens this repaired.
    }
};
