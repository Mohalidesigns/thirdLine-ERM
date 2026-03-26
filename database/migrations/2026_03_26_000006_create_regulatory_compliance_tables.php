<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('regulator', 50);
            $table->string('report_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('deadline_date');
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'semi_annual', 'annual', 'ad_hoc'])->default('monthly');
            $table->enum('status', ['upcoming', 'in_progress', 'submitted', 'overdue', 'not_applicable'])->default('upcoming');
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'deadline_date']);
            $table->index('status');
        });

        Schema::create('regulatory_circulars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('regulator', 50);
            $table->string('circular_ref', 100);
            $table->string('title');
            $table->date('date_issued');
            $table->date('effective_date')->nullable();
            $table->text('summary')->nullable();
            $table->json('affected_risk_ids')->nullable();
            $table->json('affected_control_ids')->nullable();
            $table->enum('impact_level', ['critical', 'high', 'medium', 'low'])->default('medium');
            $table->enum('compliance_status', ['not_assessed', 'compliant', 'partially_compliant', 'non_compliant', 'not_applicable'])->default('not_assessed');
            $table->decimal('compliance_pct', 5, 2)->default(0);
            $table->text('action_required')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'regulator']);
            $table->index('compliance_status');
        });

        Schema::create('regulatory_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deadline_id')->constrained('regulatory_deadlines')->cascadeOnDelete();
            $table->date('filing_date');
            $table->foreignId('filed_by')->constrained('users');
            $table->enum('status', ['draft', 'submitted', 'accepted', 'rejected', 'resubmitted'])->default('draft');
            $table->string('document_ref')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Risk taxonomy management
        Schema::create('risk_taxonomies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('framework', 50)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('depth')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('risk_taxonomies')->nullOnDelete();
            $table->index(['organization_id', 'parent_id']);
        });

        // Data import logs
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('import_type', 50);
            $table->string('file_name');
            $table->string('file_path');
            $table->integer('total_rows')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->json('column_mapping')->nullable();
            $table->json('errors')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->foreignId('imported_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('risk_taxonomies');
        Schema::dropIfExists('regulatory_filings');
        Schema::dropIfExists('regulatory_circulars');
        Schema::dropIfExists('regulatory_deadlines');
    }
};
