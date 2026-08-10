<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MonteCarloService now draws from a seeded generator (WP-01 TASK 0). Record
 * the seed alongside the run so a capital figure can be re-derived exactly.
 *
 * Additive: one nullable column. Runs written before this migration keep a
 * NULL seed, which is the honest answer — those runs are not reproducible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('simulation_runs', 'random_seed')) {
                $table->unsignedBigInteger('random_seed')->nullable()->after('iterations');
            }
        });
    }

    public function down(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table) {
            if (Schema::hasColumn('simulation_runs', 'random_seed')) {
                $table->dropColumn('random_seed');
            }
        });
    }
};
