<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('entity_type_id')->constrained('entity_types')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('entity_code', 20);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegate_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active, inactive, archived
            $table->unsignedTinyInteger('level')->default(0); // 0-4
            $table->json('regulatory_frameworks')->nullable(); // ['CBN ORMS', 'Basel III', ...]
            $table->string('risk_appetite_level', 20)->nullable(); // averse, minimal, cautious, open, hungry
            $table->json('category_appetites')->nullable(); // {credit: 'minimal', operational: 'cautious', ...}
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'entity_code']);
            $table->index('parent_id');
            $table->index('entity_type_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
