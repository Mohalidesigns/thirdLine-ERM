<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * simulation_runs.correlation_method advertised a capability the engine does
 * not have.
 *
 * 2026_02_22_200031 created the column with `->default('GAUSSIAN_COPULA')`.
 * There is no copula anywhere in the codebase: MonteCarloService aggregates
 * scenarios with `$totalLosses[$i] += $annualLoss;`, which is independent
 * summation — the scenarios share no dependence structure at all. Independence
 * understates aggregate tail risk relative to a positively-coupled copula, so
 * the label was not merely wrong, it was wrong in the direction that flatters
 * the capital number.
 *
 * Only the UI controller ever set the field explicitly (to 'independent'). Every
 * other path — the seeder, the API, a queued run, a raw insert — took the
 * column default, so rows were stamped GAUSSIAN_COPULA by the database and that
 * string reached the customer through the quantification CSV export. The export
 * column has been removed in the same change set; this migration fixes the
 * stored data underneath it.
 *
 * down() restores the old default so the schema round-trips, but deliberately
 * does NOT re-stamp the rows. Rolling this migration back does not resurrect a
 * copula that was never implemented, and re-labelling completed runs as
 * GAUSSIAN_COPULA would put a false statement about a regulatory capital figure
 * back into the database. Rolling back schema is reversible; re-fabricating
 * provenance is not.
 *
 * When a genuine copula does land, this migration is NOT the thing to revert.
 * The new engine should write its own method name on the runs it produces and
 * leave historical rows saying what they actually did.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->setDefault('independent');

        // Existing rows carry the fabricated label. Both spellings appear: the
        // column default supplied the upper-case one, the quantification seeder
        // the lower-case one.
        DB::table('simulation_runs')
            ->whereIn('correlation_method', ['GAUSSIAN_COPULA', 'gaussian_copula'])
            ->update(['correlation_method' => 'independent']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->setDefault('GAUSSIAN_COPULA');

        // No row update on the way back — see the class docblock. A run that
        // summed its scenarios independently did so whichever direction the
        // migrator is travelling.
    }

    /**
     * Change the column default on both supported drivers.
     *
     * doctrine/dbal is not installed, and while Laravel 12 can emit a native
     * `->change()` on MySQL it does so as `MODIFY COLUMN`, which rewrites the
     * whole table — an unnecessary outage on a production simulation_runs table
     * for what MySQL can do as an instant metadata-only operation. SQLite has no
     * `ALTER COLUMN` at all, so there the Schema builder's table rebuild is the
     * only option; the test suite runs on an in-memory database where a rebuild
     * costs nothing.
     */
    private function setDefault(string $default): void
    {
        if (! Schema::hasTable('simulation_runs') || ! Schema::hasColumn('simulation_runs', 'correlation_method')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // Interpolated rather than bound: MySQL does not accept parameter
            // placeholders in DDL. $default is never caller-supplied — it is one
            // of the two literals this file passes in — so there is no untrusted
            // value to bind, but it is still escaped rather than concatenated
            // raw.
            DB::statement(
                'ALTER TABLE simulation_runs ALTER COLUMN correlation_method SET DEFAULT '
                .DB::getPdo()->quote($default)
            );

            return;
        }

        Schema::table('simulation_runs', function (Blueprint $table) use ($default) {
            $table->string('correlation_method', 30)->default($default)->change();
        });
    }
};
