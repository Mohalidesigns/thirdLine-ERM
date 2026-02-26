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
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->string('entity_type', 50); // 'Risk', 'LossEvent', 'Control', etc.
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 50); // 'create', 'update', 'delete', 'status_change'
            $table->string('status', 20)->default('pending'); // 'pending', 'approved', 'rejected'
            $table->json('payload')->nullable(); // The proposed changes
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at')->useCurrent();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['organization_id', 'status']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('requested_by');
            $table->index('reviewed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
