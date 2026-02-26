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
        Schema::create('kri_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kri_id')->constrained('key_risk_indicators')->cascadeOnDelete();
            $table->date('measurement_date');
            $table->decimal('value', 18, 4);
            $table->string('status', 10);
            $table->foreignId('entered_by')->constrained('users');
            $table->string('data_source', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['kri_id', 'measurement_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kri_measurements');
    }
};
