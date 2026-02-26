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
        Schema::create('quantification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('default_iterations')->default(10000);
            $table->json('default_confidence_levels')->nullable();
            $table->decimal('cbn_minimum_car', 8, 4)->default(10.0);
            $table->decimal('cbn_conservation_buffer', 8, 4)->default(2.5);
            $table->decimal('cbn_mpr', 8, 4)->default(27.5);
            $table->bigInteger('cbn_loss_threshold_kobo')->default(500000000);
            $table->bigInteger('nfiu_str_threshold_kobo')->default(500000000);
            $table->bigInteger('nfiu_ctr_threshold_kobo')->default(1000000000);
            $table->bigInteger('ndic_threshold_kobo')->default(50000000);
            $table->json('distribution_defaults')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quantification_settings');
    }
};
