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
        Schema::create('simulation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('simulation_reference', 20)->unique();
            $table->string('status', 20)->default('PENDING');
            $table->integer('iterations')->default(10000);
            $table->integer('horizon_years')->default(1);
            $table->string('correlation_method', 30)->default('GAUSSIAN_COPULA');
            $table->json('confidence_levels')->nullable();
            $table->json('scenario_ids')->nullable();
            $table->json('stress_config')->nullable();
            $table->string('icaap_period', 20)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('runtime_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('initiated_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_runs');
    }
};
