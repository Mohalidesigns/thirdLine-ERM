<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 2 — frequency drivers table,
 * frozen by ADR 0027 Amendment 2 §A2.3.
 *
 * Stores input rows feeding expected annual frequency:
 * lambda = count * (1 - scorecard_score) * probability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_frequency_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();

            // Semantic key e.g. 'exposed_staff_count', 'retention_plan_efficiency', 'competition_pressure_prob'
            $table->string('driver_type', 50);
            $table->string('criterion', 250);

            // 'count' | 'control_score_complement' | 'probability'
            $table->string('computation_role', 30);

            // Numerical value of driver (e.g. 55, 0.69090909, 0.95)
            $table->decimal('value', 18, 8)->default(0.00000000);

            // Provenance source (e.g. 'HR information', 'Scorecard', 'Expert judgement')
            $table->string('data_source', 100)->default('expert_judgement');

            // Optional link when driver is fed by a scorecard
            $table->foreignId('sm_scorecard_id')->nullable()->constrained('sm_scorecards')->nullOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'sm_scenario_id'], 'sm_freq_drivers_org_scenario_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_frequency_drivers');
    }
};
