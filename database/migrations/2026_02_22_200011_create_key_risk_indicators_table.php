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
        Schema::create('key_risk_indicators', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kri_code', 20);
            $table->string('name', 200);
            $table->text('description');
            $table->text('metric_formula');
            $table->string('data_source', 200);
            $table->string('measurement_frequency', 20);
            $table->string('unit_of_measure', 50);
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->decimal('green_threshold_min', 18, 4)->nullable();
            $table->decimal('green_threshold_max', 18, 4)->nullable();
            $table->decimal('amber_threshold_min', 18, 4)->nullable();
            $table->decimal('amber_threshold_max', 18, 4)->nullable();
            $table->decimal('red_threshold_min', 18, 4)->nullable();
            $table->decimal('red_threshold_max', 18, 4)->nullable();
            $table->string('threshold_direction', 20)->default('higher_worse');
            $table->decimal('current_value', 18, 4)->nullable();
            $table->string('current_status', 10)->nullable();
            $table->string('trend_direction', 20)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_automated')->default(false);
            $table->json('automation_config')->nullable();
            $table->timestamp('last_measurement_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'kri_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('key_risk_indicators');
    }
};
