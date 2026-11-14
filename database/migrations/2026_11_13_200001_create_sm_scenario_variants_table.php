<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * ADR 0027 Amendment 3 §A3.2 — sm_scenario_variants
     */
    public function up(): void
    {
        Schema::create('sm_scenario_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();
            $table->string('tier', 20); // tier_1, tier_2, tier_3
            $table->string('name', 150);
            $table->text('narrative')->nullable();
            $table->boolean('is_cumulative')->default(false);
            $table->json('triggers')->nullable();
            $table->json('operational_impacts')->nullable();
            $table->json('responses')->nullable();
            $table->json('response_owners')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(1);
            $table->nullableTimestamps();

            $table->unique(['sm_scenario_id', 'tier'], 'sm_variants_scenario_tier_unique');
            $table->index(['organization_id', 'sm_scenario_id'], 'sm_variants_org_scenario_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sm_scenario_variants');
    }
};
