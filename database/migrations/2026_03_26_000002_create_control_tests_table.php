<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->constrained()->cascadeOnDelete();
            $table->string('test_code', 50)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('test_type', ['design_effectiveness', 'operating_effectiveness', 'walkthrough', 'substantive'])->default('operating_effectiveness');
            $table->foreignId('tester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('scheduled_date');
            $table->date('started_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->enum('result', ['effective', 'partially_effective', 'ineffective', 'not_tested'])->default('not_tested');
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'pending_review', 'completed', 'cancelled'])->default('scheduled');
            $table->integer('score')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['control_id', 'scheduled_date']);
        });

        Schema::create('control_test_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_test_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type', 50)->nullable();
            $table->integer('file_size')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Add test tracking columns to controls
        Schema::table('controls', function (Blueprint $table) {
            $table->string('last_test_result', 30)->nullable()->after('next_test_due');
            $table->integer('tests_passed_count')->default(0)->after('last_test_result');
            $table->integer('tests_failed_count')->default(0)->after('tests_passed_count');
            $table->integer('total_tests_count')->default(0)->after('tests_failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('controls', function (Blueprint $table) {
            $table->dropColumn(['last_test_result', 'tests_passed_count', 'tests_failed_count', 'total_tests_count']);
        });
        Schema::dropIfExists('control_test_evidence');
        Schema::dropIfExists('control_tests');
    }
};
