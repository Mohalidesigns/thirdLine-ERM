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
        Schema::create('loss_event_rca', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('loss_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('rca_status', 30)->default('NOT_STARTED');
            $table->string('methodology', 50)->default('5_WHYS');

            // 5-Whys
            $table->text('why_1_question')->nullable();
            $table->text('why_1_answer')->nullable();
            $table->text('why_2_question')->nullable();
            $table->text('why_2_answer')->nullable();
            $table->text('why_3_question')->nullable();
            $table->text('why_3_answer')->nullable();
            $table->text('why_4_question')->nullable();
            $table->text('why_4_answer')->nullable();
            $table->text('why_5_question')->nullable();
            $table->text('why_5_answer')->nullable();
            $table->text('root_cause_statement');
            $table->json('contributory_factors')->nullable();

            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loss_event_rca');
    }
};
