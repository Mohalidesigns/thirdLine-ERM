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
        Schema::create('controls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('control_code', 20)->index();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('control_type', 30)->nullable();
            $table->string('control_nature', 30)->nullable();
            $table->string('frequency', 30)->nullable();
            $table->string('automation_level', 30)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('effectiveness_rating', 30)->nullable();
            $table->decimal('effectiveness_pct', 5, 2)->nullable();
            $table->date('last_test_date')->nullable();
            $table->date('next_test_due')->nullable();
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'control_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('controls');
    }
};
