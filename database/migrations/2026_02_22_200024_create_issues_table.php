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
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('issue_reference', 20)->unique();

            // Details
            $table->string('title', 500);
            $table->text('description');
            $table->text('observation')->nullable();
            $table->text('criteria_violated')->nullable();

            // Source
            $table->string('issue_source', 50);
            $table->string('examination_ref', 200)->nullable();
            $table->date('examination_date')->nullable();

            // Classification
            $table->string('issue_category', 50);
            $table->string('priority', 20);
            $table->string('issue_status', 30)->default('OPEN')->index();

            // Organizational
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('department', 200)->nullable();
            $table->foreignId('responsible_owner_id')->nullable()->constrained('users')->nullOnDelete();

            // Regulatory
            $table->boolean('regulatory_reportable')->default(false);
            $table->boolean('cbn_reportable')->default(false);
            $table->boolean('cbn_examination_finding')->default(false);
            $table->date('cbn_response_deadline')->nullable();
            $table->boolean('cbn_response_submitted')->default(false);
            $table->boolean('bofia_reportable')->default(false);
            $table->boolean('ndpa_reportable')->default(false);
            $table->string('ndpa_breach_type', 100)->nullable();

            // Remediation
            $table->date('management_response_due')->nullable();
            $table->date('remediation_due_date')->nullable();
            $table->date('actual_close_date')->nullable();
            $table->text('management_response')->nullable();
            $table->text('action_plan')->nullable();
            $table->text('interim_controls')->nullable();

            // Escalation
            $table->string('current_escalation_level', 50)->nullable();
            $table->string('escalation_path', 200)->nullable();

            // Financial
            $table->bigInteger('potential_loss_kobo')->nullable();
            $table->bigInteger('actual_loss_kobo')->nullable();

            // Linkages
            $table->foreignId('risk_register_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->foreignId('loss_event_id')->nullable()->constrained('loss_events')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
