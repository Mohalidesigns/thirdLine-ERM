<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §14 Q4 — "Is appetite a single ceiling, or a statement per risk category?"
 *
 * The plan asked the bank this before P3 and never got an answer, so P0 stored
 * a single `appetite_ceiling_level` and said in its own comment that a
 * per-category table "would hang off this column, not replace it". This is that
 * table.
 *
 * IT DOES NOT ANSWER THE QUESTION. `appetite_mode` defaults to `single`, which
 * is exactly the behaviour that has shipped since P0, and no tenant's numbers
 * move on the day this migration runs. The bank answers Q4 by setting a value;
 * they do not answer it by commissioning a rebuild, and we do not answer it for
 * them by making per-category the default and calling it an improvement.
 *
 * The third change here is structural rather than a feature. `above_appetite`
 * becomes a stored column on the line. Until now three services asked the
 * methodology "which bands are above appetite", got a flat list of levels back
 * and ran `whereIn('residual_level', ...)`. That question stops having a single
 * answer the moment appetite varies by category — the same VERY LOW band can be
 * inside appetite for one category and outside it for another. Storing the
 * boolean the engine already computes is both the correct generalisation and a
 * cheaper query than the one it replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rcsa_methodologies', function (Blueprint $table) {
            // single:       one ceiling for every risk, `appetite_ceiling_level`.
            // per_category: `rcsa_category_appetites` decides, falling back to
            //               that same column for any category without a row.
            //
            // Seeded `single` deliberately. See the class comment.
            $table->enum('appetite_mode', ['single', 'per_category'])
                ->default('single')
                ->after('appetite_ceiling_level');
        });

        Schema::create('rcsa_category_appetites', function (Blueprint $table) {
            $table->id();

            // Hangs off the methodology, not the organisation, because appetite
            // is part of what a cycle FREEZES. P3 made opening a cycle lock the
            // methodology so that "why was this risk above appetite in March"
            // stays answerable; an appetite table scoped to the tenant instead
            // would be editable underneath a closed cycle and would make last
            // quarter's obligations un-reconstructable.
            $table->foreignId('methodology_id')->constrained('rcsa_methodologies')->cascadeOnDelete();

            // The workbook's own category spelling, matching
            // `rcsa_assessment_lines.risk_category` — a string, not a foreign
            // key, for the reason RcsaMethodologyTemplate::RISK_CATEGORIES
            // gives: this vocabulary is the workbook's, not the enterprise
            // taxonomy's, and the two are allowed to differ.
            $table->string('risk_category', 64);

            // The highest residual band still inside appetite FOR THIS
            // CATEGORY. Same vocabulary as `appetite_ceiling_level`.
            $table->string('ceiling_level', 32);

            // Why this category differs from the house ceiling. Free text, and
            // worth having: a per-category appetite is a board decision, and the
            // reason it was set is what an examiner asks for.
            $table->text('note')->nullable();

            $table->timestamps();

            // Named, because an implicit name is generated from the table and
            // column names and MySQL's 64-character limit is not SQLite's. P0
            // was bitten by exactly this (`24d50bb`).
            $table->unique(['methodology_id', 'risk_category'], 'rcsa_cat_appetite_unique');
        });

        Schema::table('rcsa_assessment_lines', function (Blueprint $table) {
            // NULL = not yet scored, and it must stay distinguishable from
            // false. A line with no residual band is not "inside appetite"; it
            // is unanswered, and the dashboards count those separately.
            $table->boolean('above_appetite')->nullable()->after('appetite_status');

            $table->index(['assessment_id', 'above_appetite'], 'rcsa_lines_above_appetite_idx');
        });

        $this->backfillAboveAppetite();
    }

    /**
     * Set `above_appetite` on every line already scored.
     *
     * Computed from each methodology's ceiling rather than assumed, and done in
     * one UPDATE per methodology rather than one per line. Every existing
     * methodology is in `single` mode by definition — `per_category` did not
     * exist a moment ago — so the single ceiling is the whole answer here.
     *
     * A line with no `residual_level` is left NULL, not false.
     */
    private function backfillAboveAppetite(): void
    {
        $methodologies = DB::table('rcsa_methodologies')
            ->select('id', 'appetite_ceiling_level')
            ->get();

        foreach ($methodologies as $methodology) {
            $bands = DB::table('rcsa_risk_bands')
                ->where('methodology_id', $methodology->id)
                ->orderBy('min_score')
                ->pluck('level')
                ->all();

            $ceiling = array_search($methodology->appetite_ceiling_level, $bands, true);

            if ($ceiling === false) {
                // A methodology whose ceiling names no band it owns. Leaving
                // these NULL is right: guessing would put a number on a board
                // pack that nothing in the configuration supports.
                continue;
            }

            $above = array_slice($bands, $ceiling + 1);
            $within = array_slice($bands, 0, $ceiling + 1);

            if ($above !== []) {
                DB::table('rcsa_assessment_lines')
                    ->where('methodology_id', $methodology->id)
                    ->whereIn('residual_level', $above)
                    ->update(['above_appetite' => true]);
            }

            if ($within !== []) {
                DB::table('rcsa_assessment_lines')
                    ->where('methodology_id', $methodology->id)
                    ->whereIn('residual_level', $within)
                    ->update(['above_appetite' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('rcsa_assessment_lines', function (Blueprint $table) {
            $table->dropIndex('rcsa_lines_above_appetite_idx');
            $table->dropColumn('above_appetite');
        });

        Schema::dropIfExists('rcsa_category_appetites');

        Schema::table('rcsa_methodologies', function (Blueprint $table) {
            $table->dropColumn('appetite_mode');
        });
    }
};
