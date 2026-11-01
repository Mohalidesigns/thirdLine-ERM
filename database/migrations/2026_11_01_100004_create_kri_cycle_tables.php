<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0026 section 4 - the monthly cycle: runs, returns, and the draft store.
 *
 * `kri_returns` (organization, business unit, period) is the idempotency key for
 * the whole cycle. `kri_return_lines` is the DRAFT STORE, never a readings
 * table: a draft in measure_values would be re-banded by kri:check-breaches
 * overnight and open a breach from a number nobody submitted.
 *
 * Every *_at the application writes is dateTime() (ADR 0022). No json, no ENUM,
 * no useCurrentOnUpdate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kri_cycle_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->date('run_on');
            $table->string('trigger', 16);
            $table->boolean('forced')->default(false);
            $table->string('status', 16);
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('departments_due')->default(0);
            $table->unsignedInteger('returns_opened')->default(0);
            $table->unsignedInteger('requests_queued')->default(0);
            $table->unsignedInteger('departments_without_champion')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'period_id'], 'kri_cycle_runs_org_period_unique');
        });

        Schema::create('kri_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->foreignId('kri_cycle_run_id')->nullable()->constrained('kri_cycle_runs')->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->date('requested_on');
            $table->date('due_on');
            $table->dateTime('opened_at');
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('reopen_count')->default(0);
            // Amendment 1, A1.8: optimistic locking. Every draft save, submit, reopen and
            // line-changing sync increments it; a stale posted value is a 422 on lock_version.
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'business_unit_id', 'period_id'], 'kri_returns_unit_period_unique');
            $table->index(['organization_id', 'period_id', 'status'], 'kri_returns_org_period_status_idx');
            $table->index(['organization_id', 'status', 'due_on'], 'kri_returns_org_status_due_idx');
        });

        Schema::create('kri_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kri_return_id')->constrained('kri_returns')->cascadeOnDelete();
            $table->foreignId('key_risk_indicator_id')->constrained('key_risk_indicators')->cascadeOnDelete();
            // The KRI's OWN measurement period (month, quarter, ...), not the reporting month.
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->decimal('value', 28, 6)->nullable();
            $table->text('comment')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('action_point')->nullable();
            $table->date('timeline')->nullable();
            $table->foreignId('action_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('timeline_change_reason', 500)->nullable();
            // For display only. Never authoritative: the server re-bands on every write.
            $table->string('band', 32)->nullable();
            $table->foreignId('measure_value_id')->nullable()->constrained('measure_values')->nullOnDelete();
            $table->foreignId('measure_breach_id')->nullable()->constrained('measure_breaches')->nullOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('saved_at')->nullable();
            $table->timestamps();

            $table->unique(['kri_return_id', 'key_risk_indicator_id'], 'kri_return_lines_return_kri_unique');
            $table->index(['organization_id', 'key_risk_indicator_id'], 'kri_return_lines_org_kri_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kri_return_lines');
        Schema::dropIfExists('kri_returns');
        Schema::dropIfExists('kri_cycle_runs');
    }
};
