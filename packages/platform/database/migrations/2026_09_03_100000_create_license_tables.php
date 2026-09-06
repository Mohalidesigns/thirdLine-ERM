<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('license_key')->unique();
            $table->string('plan')->default('starter');
            $table->string('status')->default('inactive');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('device_fingerprint')->nullable();
            $table->integer('max_users')->default(5);
            $table->json('features')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('license_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action');
            $table->json('metadata')->nullable();
            $table->boolean('synced')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index('synced');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_audit_logs');
        Schema::dropIfExists('license_stores');
    }
};
