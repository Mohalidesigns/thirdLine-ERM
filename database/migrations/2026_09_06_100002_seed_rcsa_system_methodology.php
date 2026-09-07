<?php

use App\Support\Rcsa\RcsaMethodologyTemplate as Template;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RCSA v2, P0 — seed the SB_RCSA Template 2026 methodology.
 *
 * A migration rather than a seeder, for the reason the scoring-profile seed
 * migration gives: the code that reads these rows ships in the same release,
 * and a seeder is optional. An install that skipped it would resolve no
 * methodology, and RcsaCalculationService would have nothing to calculate
 * against on any tenant.
 *
 * ONE ROW, organization_id NULL. Unlike the scoring-profile migration, there is
 * nothing here to preserve parity WITH — the RCSA rewrite is a new module
 * behind the `rcsa_v2` flag, not a change to how anything currently scores, so
 * no tenant needs its own copy to keep yesterday's numbers. A tenant that wants
 * to diverge clones the system methodology through the admin screen; until then
 * every tenant scores against the client's approved workbook, which is the
 * correct default for a module whose entire contract is that workbook.
 *
 * Re-runnable in the sense that matters: it inserts only if the system
 * methodology is absent, so an environment that has already been seeded (by
 * this migration or by a later refresh seeder) is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('rcsa_methodologies')
            ->whereNull('organization_id')
            ->where('code', Template::CODE)
            ->where('version', Template::VERSION)
            ->value('id');

        if ($existing !== null) {
            return;
        }

        $now = now();

        $methodologyId = DB::table('rcsa_methodologies')->insertGetId(
            Template::methodology() + [
                'uuid' => (string) Str::uuid(),
                'organization_id' => null,
                'effective_from' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('rcsa_scale_items')->insert(array_map(
            fn (array $row) => $row + [
                'methodology_id' => $methodologyId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            Template::scaleItems()
        ));

        DB::table('rcsa_impact_criteria')->insert(array_map(
            fn (array $row) => $row + [
                'methodology_id' => $methodologyId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            Template::impactCriteria()
        ));

        DB::table('rcsa_risk_bands')->insert(array_map(
            fn (array $row) => $row + [
                'methodology_id' => $methodologyId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            Template::riskBands()
        ));
    }

    public function down(): void
    {
        // Only the row this migration could have written. A methodology a
        // tenant has since cloned is not this migration's to delete, and the
        // cascade on methodology_id takes the scales, criteria and bands with it.
        DB::table('rcsa_methodologies')
            ->whereNull('organization_id')
            ->where('code', Template::CODE)
            ->where('version', Template::VERSION)
            ->delete();
    }
};
