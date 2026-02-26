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
        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('risk_code', 20)->index();
            $table->string('title', 200)->index();
            $table->text('description');
            $table->foreignId('category_id')->constrained('risk_categories');
            $table->string('risk_source', 20)->default('internal');
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('business_processes')->nullOnDelete();
            $table->foreignId('risk_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('risk_steward_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('identified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_identified')->nullable();
            $table->string('status', 20)->default('draft')->index();

            // Inherent risk scores
            $table->smallInteger('inherent_likelihood')->nullable();
            $table->smallInteger('inherent_impact')->nullable();
            $table->smallInteger('inherent_impact_financial')->nullable();
            $table->smallInteger('inherent_impact_operational')->nullable();
            $table->smallInteger('inherent_impact_reputational')->nullable();
            $table->smallInteger('inherent_impact_regulatory')->nullable();
            $table->smallInteger('inherent_score')->nullable();
            $table->string('inherent_rating', 20)->nullable();

            // Control effectiveness
            $table->decimal('control_effectiveness_pct', 5, 2)->nullable();

            // Residual risk
            $table->smallInteger('residual_likelihood')->nullable();
            $table->smallInteger('residual_impact')->nullable();
            $table->smallInteger('residual_score')->nullable();
            $table->string('residual_rating', 20)->nullable();

            // Target & appetite
            $table->string('target_rating', 20)->nullable();
            $table->string('risk_velocity', 20)->nullable();
            $table->boolean('appetite_aligned')->nullable();
            $table->text('appetite_notes')->nullable();

            // Treatment
            $table->string('treatment_strategy', 20)->nullable();

            // Review
            $table->string('review_frequency', 20)->nullable();
            $table->date('last_assessment_date')->nullable();
            $table->date('next_review_date')->nullable()->index();

            // Financial
            $table->decimal('financial_exposure_ngn', 18, 2)->nullable();

            // Flexible fields
            $table->json('regulatory_mapping')->nullable();
            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'risk_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risks');
    }
};
