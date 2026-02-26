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
        Schema::create('loss_event_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loss_event_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 50);
            $table->string('action', 30);
            $table->string('decision', 30)->nullable();
            $table->text('comments')->nullable();
            $table->text('conditions')->nullable();
            $table->boolean('cbn_notified')->default(false);
            $table->foreignId('actioned_by')->constrained('users');
            $table->timestamp('actioned_at')->useCurrent();
            $table->integer('days_in_stage')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loss_event_approvals');
    }
};
