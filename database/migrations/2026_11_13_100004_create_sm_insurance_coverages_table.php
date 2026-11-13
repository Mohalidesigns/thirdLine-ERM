<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 2 — insurance risk-transfer netting table,
 * frozen by ADR 0027 Amendment 2 §A2.5.
 *
 * 3 rows per scenario: one for each severity ('typical', 'serious', 'extreme').
 * Computes tiered residual, total haircut, and final net impact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_insurance_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();

            // 'typical' | 'serious' | 'extreme'
            $table->string('severity', 20);

            // Sum of transferable impact categories
            $table->decimal('transferable_amount', 18, 8)->default(0.00000000);

            // Deductibles & Limits
            $table->decimal('deductible_local', 18, 8)->default(0.00000000);
            $table->decimal('limit_local', 18, 8)->default(0.00000000);
            $table->decimal('deductible_group', 18, 8)->default(0.00000000);
            $table->decimal('limit_group', 18, 8)->default(0.00000000);

            // Haircut factors (0.0000 to 1.0000)
            $table->decimal('recovery_rate', 5, 4)->default(1.0000);
            $table->decimal('probability_of_cover', 5, 4)->default(1.0000);
            $table->decimal('insurer_default_haircut', 5, 4)->default(0.0000);

            // Computed netting results
            $table->decimal('residual_amount', 18, 8)->default(0.00000000);
            $table->decimal('total_haircut', 5, 4)->default(0.0000);
            $table->decimal('haircut_impact', 18, 8)->default(0.00000000);
            $table->decimal('gross_impact', 18, 8)->default(0.00000000);
            $table->decimal('net_impact', 18, 8)->default(0.00000000);

            $table->timestamps();

            $table->unique(['sm_scenario_id', 'severity'], 'sm_ins_coverages_scenario_sev_unique');
            $table->index(['organization_id', 'sm_scenario_id'], 'sm_ins_coverages_org_scenario_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_insurance_coverages');
    }
};
