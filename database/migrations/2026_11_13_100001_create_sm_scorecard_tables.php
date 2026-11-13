<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scenario Modelling & Analysis Phase 2 — the COSO control scorecard tables,
 * frozen by ADR 0027 Amendment 2 §A2.2.
 *
 * 1:1 between `sm_scenarios` and `sm_scorecards`.
 * 1:N between `sm_scorecards` and `sm_scorecard_components` (5 COSO components).
 *
 * Formulas decoded from BIFF8 workbook `Scorecard` sheet (PHASE0-SPIKE.md §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_scorecards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();

            // 'mainly_manual' | 'mixed' | 'mainly_automated'
            $table->string('control_nature', 20)->default('mixed');

            // Sum of component weighting scores (Sigma K)
            $table->unsignedSmallInteger('total_weight')->nullable();

            // Sum of component weighted scores (Sigma L)
            $table->decimal('total_weighted_score', 12, 4)->nullable();

            // Global efficiency score = Sigma L / Sigma K (0.0 to 1.0)
            $table->decimal('global_score', 12, 8)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('sm_scenario_id', 'sm_scorecards_scenario_unique');
            $table->index(['organization_id', 'sm_scenario_id'], 'sm_scorecards_org_scenario_idx');
        });

        Schema::create('sm_scorecard_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sm_scorecard_id')->constrained('sm_scorecards')->cascadeOnDelete();

            // 'control_environment' | 'risk_assessment' | 'control_activities' |
            // 'information_communication' | 'monitoring'
            $table->string('component_code', 40);

            // 0 = unset, 1..5 = Very low to Very high
            $table->unsignedTinyInteger('efficiency_level')->default(0);
            $table->unsignedTinyInteger('weighting_factor')->default(0);
            $table->unsignedTinyInteger('score')->default(0);

            // Non-linear level score {1:0.20, 2:0.40, 3:0.55, 4:0.70, 5:0.90}
            $table->decimal('level_score', 5, 2)->default(0.00);

            // level_score - nature deduction (-0.10, -0.05, 0.00)
            $table->decimal('adjusted_score', 5, 2)->default(0.00);

            $table->unsignedTinyInteger('weighting_score')->default(0);
            $table->decimal('weighted_score', 8, 4)->default(0.0000);

            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['sm_scorecard_id', 'component_code'], 'sm_sc_components_scorecard_code_unique');
            $table->index(['organization_id', 'sm_scorecard_id'], 'sm_sc_components_org_scorecard_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_scorecard_components');
        Schema::dropIfExists('sm_scorecards');
    }
};
