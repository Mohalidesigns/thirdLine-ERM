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
        Schema::create('near_misses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->unique();
            $table->string('title', 500);
            $table->text('description');
            $table->date('date_occurred');
            $table->date('date_reported')->useCurrent();
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('potential_loss_kobo')->nullable();
            $table->string('severity', 20);
            $table->boolean('control_gap_identified')->default(false);
            $table->text('control_gap_description')->nullable();
            $table->foreignId('linked_control_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->string('status', 30)->default('OPEN');
            $table->foreignId('converted_loss_event_id')->nullable()->constrained('loss_events')->nullOnDelete();
            $table->foreignId('investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('investigation_deadline')->nullable();
            $table->foreignId('risk_register_id')->nullable()->constrained('risks')->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('near_misses');
    }
};
