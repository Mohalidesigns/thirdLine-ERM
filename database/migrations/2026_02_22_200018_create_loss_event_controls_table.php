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
        Schema::create('loss_event_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loss_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->constrained();
            $table->string('failure_type', 50)->nullable();
            $table->text('failure_description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loss_event_controls');
    }
};
