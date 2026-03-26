<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('campaign_code', 50)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('campaign_type', ['rcsa', 'fraud_risk', 'compliance', 'new_product', 'custom'])->default('rcsa');
            $table->enum('status', ['draft', 'active', 'in_progress', 'under_review', 'closed', 'cancelled'])->default('draft');
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('total_assignments')->default(0);
            $table->integer('completed_assignments')->default(0);
            $table->decimal('completion_pct', 5, 2)->default(0);
            $table->json('settings')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('campaign_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('assessment_campaigns')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('respondent_id')->constrained('users');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'submitted', 'under_review', 'approved', 'rejected'])->default('pending');
            $table->date('due_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->unique(['campaign_id', 'business_unit_id', 'respondent_id'], 'campaign_bu_respondent_unique');
        });

        Schema::create('campaign_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('campaign_assignments')->cascadeOnDelete();
            $table->foreignId('risk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('likelihood_score')->nullable();
            $table->integer('impact_score')->nullable();
            $table->integer('overall_score')->nullable();
            $table->string('rating', 20)->nullable();
            $table->string('control_effectiveness', 30)->nullable();
            $table->text('comments')->nullable();
            $table->json('questionnaire_data')->nullable();
            $table->timestamps();

            $table->index('assignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_responses');
        Schema::dropIfExists('campaign_assignments');
        Schema::dropIfExists('assessment_campaigns');
    }
};
