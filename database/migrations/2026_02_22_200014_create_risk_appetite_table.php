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
        Schema::create('risk_appetite', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_category_id')->constrained('risk_categories');
            $table->string('appetite_level', 20);
            $table->text('appetite_statement');
            $table->string('tolerance_metric', 200);
            $table->decimal('max_tolerance', 18, 4);
            $table->decimal('target_min', 18, 4);
            $table->decimal('target_max', 18, 4);
            $table->decimal('current_position', 18, 4)->nullable();
            $table->string('unit_of_measure', 50);
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('approved_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_appetite');
    }
};
