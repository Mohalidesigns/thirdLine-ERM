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
        Schema::create('quantification_scenarios', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_reference', 20)->unique();
            $table->foreignId('risk_register_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->string('scenario_type', 50);
            $table->string('name', 300);
            $table->text('description')->nullable();
            $table->string('cbn_risk_category', 100)->nullable();
            $table->string('basel_l1_category', 50)->nullable();

            // Frequency distribution
            $table->string('frequency_distribution', 30)->nullable();
            $table->decimal('frequency_lambda', 10, 4)->nullable();
            $table->decimal('frequency_n', 10, 4)->nullable();
            $table->decimal('frequency_p', 10, 6)->nullable();

            // Severity distribution
            $table->string('severity_distribution', 30)->nullable();
            $table->decimal('severity_mu', 18, 6)->nullable();
            $table->decimal('severity_sigma', 18, 6)->nullable();
            $table->decimal('severity_location', 18, 6)->nullable();
            $table->decimal('severity_scale', 18, 6)->nullable();
            $table->decimal('severity_shape', 18, 6)->nullable();
            $table->bigInteger('severity_min_kobo')->nullable();
            $table->bigInteger('severity_max_kobo')->nullable();

            // Expected loss
            $table->decimal('expected_annual_frequency', 10, 4)->nullable();
            $table->bigInteger('expected_loss_per_event_kobo')->nullable();
            $table->bigInteger('expected_annual_loss_kobo')->nullable();

            // Stress testing
            $table->string('cbn_stress_scenario', 50)->nullable();
            $table->decimal('stress_multiplier_frequency', 10, 4)->nullable();
            $table->decimal('stress_multiplier_severity', 10, 4)->nullable();

            // Governance
            $table->string('status', 20)->default('DRAFT');
            $table->smallInteger('data_quality_score')->nullable();
            $table->foreignId('calibrated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('calibration_date')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('approval_date')->nullable();

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
        Schema::dropIfExists('quantification_scenarios');
    }
};
