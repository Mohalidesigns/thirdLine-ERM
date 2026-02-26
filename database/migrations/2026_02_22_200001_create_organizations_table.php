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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 200);
            $table->string('short_name', 50)->nullable();
            $table->string('cbn_institution_code', 20)->nullable();
            $table->string('ndic_member_number', 50)->nullable();
            $table->string('rc_number', 50)->nullable();
            $table->string('tin', 50)->nullable();
            $table->string('institution_type', 50)->nullable();
            $table->string('sector', 50)->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
