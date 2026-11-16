<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loss_event_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();
            $table->string('period_code', 20); // e.g. "2026-11"
            $table->string('status', 30)->default('pending'); // pending, submitted
            $table->boolean('has_loss')->default(false);
            $table->text('comment')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('loss_events_count')->default(0);
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'business_unit_id', 'period_code'], 'unique_org_bu_loss_period');
            $table->index(['organization_id', 'period_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loss_event_returns');
    }
};
