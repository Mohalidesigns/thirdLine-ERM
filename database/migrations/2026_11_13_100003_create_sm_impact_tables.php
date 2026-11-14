<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 2 — impact decomposition tables,
 * frozen by ADR 0027 Amendment 2 §A2.4.
 *
 * Each scenario has 1..N impact types (e.g. Hiring, Litigation).
 * Each impact type has 1..N criteria across Typical, Serious, and Extreme severities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_impact_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();

            $table->string('name', 250);

            // 'staff_turnover_interval' | 'litigation_probability'
            $table->string('aggregation_template', 50)->default('staff_turnover_interval');

            // Flag whether this impact category is eligible to transfer to insurance
            $table->boolean('eligible_to_transfer')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);

            // Computed subtotals per severity
            $table->decimal('typical_total', 18, 8)->default(0.00000000);
            $table->decimal('serious_total', 18, 8)->default(0.00000000);
            $table->decimal('extreme_total', 18, 8)->default(0.00000000);

            $table->timestamps();

            $table->index(['organization_id', 'sm_scenario_id'], 'sm_impact_types_org_scenario_idx');
        });

        Schema::create('sm_impact_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_impact_type_id')->constrained('sm_impact_types')->cascadeOnDelete();

            $table->string('label', 250);
            $table->string('unit', 50)->nullable();

            // 'count' | 'unit_amount' | 'duration' | 'probability' | 'flat_fee'
            $table->string('computation_role', 30);

            $table->string('data_source', 100)->nullable();

            $table->decimal('typical_value', 18, 8)->default(0.00000000);
            $table->decimal('serious_value', 18, 8)->default(0.00000000);
            $table->decimal('extreme_value', 18, 8)->default(0.00000000);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'sm_impact_type_id'], 'sm_impact_crit_org_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_impact_criteria');
        Schema::dropIfExists('sm_impact_types');
    }
};
