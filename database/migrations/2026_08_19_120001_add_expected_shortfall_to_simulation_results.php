<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expected shortfall (CVaR) had no column, so it had no value.
 *
 * SimulationRun::getExpectedShortfallAttribute() published a KPI titled
 * "Expected Shortfall" on the quantification dashboard and on the results page,
 * under a comment reading "ES approximated as average of losses above VaR 95",
 * and returned var_99_kobo. VaR(99) is one order statistic of the loss sample;
 * ES(95) is the mean of the entire tail beyond VaR(95). The number on screen
 * was neither of the things it claimed to be.
 *
 * A tail mean cannot be recovered after the fact from the VaR ladder — it needs
 * the sorted loss vector, which MonteCarloService discards at the end of the
 * run. So the fix is a column that the engine writes while the sample is still
 * in memory, not a smarter accessor.
 *
 * Nullable on purpose, and NOT backfilled: every run completed before this
 * migration was simulated without retaining its loss vector, so there is no
 * honest value to write. Those rows read null and the accessor returns null,
 * which is the difference between "we did not compute this" and "your tail loss
 * is zero". Re-running the simulation is the only way to populate them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('simulation_results', function (Blueprint $table) {
            $table->bigInteger('es_95_kobo')->nullable()->after('var_99_9_kobo');
            $table->bigInteger('es_99_kobo')->nullable()->after('es_95_kobo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('simulation_results', function (Blueprint $table) {
            $table->dropColumn(['es_95_kobo', 'es_99_kobo']);
        });
    }
};
