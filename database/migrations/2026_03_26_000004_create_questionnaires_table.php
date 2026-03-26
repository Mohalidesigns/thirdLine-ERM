<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('version')->default(1);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->enum('scoring_method', ['average', 'weighted', 'highest', 'sum'])->default('average');
            $table->enum('questionnaire_type', ['rcsa', 'fraud_risk', 'compliance', 'new_product', 'vendor', 'custom'])->default('custom');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('questionnaire_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('questionnaire_sections')->cascadeOnDelete();
            $table->enum('question_type', ['multiple_choice', 'likert', 'yes_no', 'free_text', 'numeric', 'file_upload', 'matrix', 'rating'])->default('likert');
            $table->text('question_text');
            $table->json('options')->nullable();
            $table->json('scoring_rules')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('conditional_logic')->nullable();
            $table->text('help_text')->nullable();
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->timestamps();
        });

        Schema::create('question_library', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 100);
            $table->text('question_text');
            $table->string('question_type', 30)->default('likert');
            $table->json('default_options')->nullable();
            $table->json('tags')->nullable();
            $table->integer('usage_count')->default(0);
            $table->boolean('is_global')->default(false);
            $table->timestamps();

            $table->index('category');
        });

        // Add questionnaire_id to campaign assignments
        Schema::table('assessment_campaigns', function (Blueprint $table) {
            $table->foreignId('questionnaire_id')->nullable()->after('campaign_type')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_campaigns', function (Blueprint $table) {
            $table->dropForeign(['questionnaire_id']);
            $table->dropColumn('questionnaire_id');
        });
        Schema::dropIfExists('question_library');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('questionnaire_sections');
        Schema::dropIfExists('questionnaires');
    }
};
