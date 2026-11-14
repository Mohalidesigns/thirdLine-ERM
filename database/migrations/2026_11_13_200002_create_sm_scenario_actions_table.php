<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * ADR 0027 Amendment 3 §A3.3 — sm_scenario_actions
     */
    public function up(): void
    {
        Schema::create('sm_scenario_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('sm_scenario_id')->constrained('sm_scenarios')->cascadeOnDelete();
            $table->string('kind', 30); // gap_remediation, immediate
            $table->string('time_horizon', 50)->nullable(); // today, within_7_days, within_30_days, custom
            $table->string('risk_area', 200);
            $table->text('current_position')->nullable();
            $table->text('identified_gap')->nullable();
            $table->text('action_plan');
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->string('department', 200)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('not_started'); // not_started, in_progress, completed, overdue
            $table->dateTime('completed_at')->nullable();
            $table->text('proof_of_closure')->nullable();
            $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(1);
            $table->nullableTimestamps();

            $table->index(['organization_id', 'status'], 'sm_actions_org_status_idx');
            $table->index(['sm_scenario_id', 'kind'], 'sm_actions_scenario_kind_idx');
            $table->index('issue_id', 'sm_actions_issue_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sm_scenario_actions');
    }
};
