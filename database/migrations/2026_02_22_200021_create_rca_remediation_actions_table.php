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
        Schema::create('rca_remediation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rca_id')->constrained('loss_event_rca')->cascadeOnDelete();
            $table->foreignId('loss_event_id')->constrained();
            $table->integer('action_number');
            $table->text('description');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department', 200)->nullable();
            $table->string('priority', 20)->nullable();
            $table->date('target_date');
            $table->date('actual_close_date')->nullable();
            $table->string('status', 30)->default('NOT_STARTED');
            $table->text('completion_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rca_remediation_actions');
    }
};
