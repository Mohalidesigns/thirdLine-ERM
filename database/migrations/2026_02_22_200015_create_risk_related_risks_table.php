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
        Schema::create('risk_related_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_risk_id')->constrained('risks')->cascadeOnDelete();
            $table->string('relationship_type', 30);
            $table->string('correlation_strength', 20)->nullable();
            $table->timestamps();
            $table->unique(['risk_id', 'related_risk_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('risk_related_risks');
    }
};
