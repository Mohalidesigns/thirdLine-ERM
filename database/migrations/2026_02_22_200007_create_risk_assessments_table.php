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
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->string('assessment_type', 30);
            $table->date('assessment_date');
            $table->foreignId('assessor_id')->constrained('users');

            // Likelihood
            $table->smallInteger('likelihood_score')->nullable();
            $table->text('likelihood_rationale')->nullable();

            // Impact dimensions
            $table->smallInteger('impact_financial')->nullable();
            $table->smallInteger('impact_operational')->nullable();
            $table->smallInteger('impact_reputational')->nullable();
            $table->smallInteger('impact_regulatory')->nullable();
            $table->smallInteger('impact_score')->nullable();
            $table->smallInteger('overall_score')->nullable();
            $table->string('overall_rating', 20)->nullable();

            // Control data
            $table->json('control_effectiveness_data')->nullable();

            // Residual
            $table->smallInteger('residual_likelihood')->nullable();
            $table->smallInteger('residual_impact')->nullable();
            $table->smallInteger('residual_score')->nullable();
            $table->string('residual_rating', 20)->nullable();

            $table->string('risk_velocity', 20)->nullable();
            $table->text('justification')->nullable();
            $table->json('evidence_refs')->nullable();

            // Workflow
            $table->foreignId('previous_assessment_id')->nullable()->constrained('risk_assessments')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('review_date')->nullable();
            $table->text('review_comments')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('approved_date')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
