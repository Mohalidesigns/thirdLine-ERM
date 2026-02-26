<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('simulation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scenario_id')->nullable()->constrained('quantification_scenarios')->nullOnDelete();
            $table->string('result_type', 30);
            $table->bigInteger('expected_annual_loss_kobo')->nullable();
            $table->bigInteger('var_90_kobo')->nullable();
            $table->bigInteger('var_95_kobo')->nullable();
            $table->bigInteger('var_99_kobo')->nullable();
            $table->bigInteger('var_99_9_kobo')->nullable();
            $table->bigInteger('std_deviation_kobo')->nullable();
            $table->json('risk_contributions')->nullable();
            $table->json('percentile_distribution')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_results');
    }
};
