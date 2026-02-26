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
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->string('strategy', 20);
            $table->string('action_title', 200);
            $table->text('action_description');
            $table->foreignId('owner_id')->constrained('users');
            $table->date('target_date');
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('not_started')->index();
            $table->smallInteger('progress_pct')->default(0);
            $table->text('progress_notes')->nullable();
            $table->decimal('cost_estimate_ngn', 18, 2)->nullable();
            $table->decimal('actual_cost_ngn', 18, 2)->nullable();
            $table->json('expected_risk_reduction')->nullable();
            $table->json('actual_risk_reduction')->nullable();
            $table->date('completion_date')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->text('dependencies')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('treatment_plans');
    }
};
