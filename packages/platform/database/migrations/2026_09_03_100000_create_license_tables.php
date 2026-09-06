<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both tables are guarded by hasTable(), which a migration inside an
     * APPLICATION would never need.
     *
     * A package migration lands in consumers that may already have these tables
     * from their own history — ThirdLine created them in March 2026, months
     * before this package existed, and its rows are live. Without the guard,
     * opting into LicensingServiceProvider would fail that install on
     * "table license_stores already exists" and there would be nothing the
     * operator could do about it short of editing a vendor file.
     */
    public function up(): void
    {
        if (! Schema::hasTable('license_stores')) {
            $this->createLicenseStores();
        }

        if (! Schema::hasTable('license_audit_logs')) {
            $this->createLicenseAuditLogs();
        }
    }

    private function createLicenseStores(): void
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
    }

    private function createLicenseAuditLogs(): void
    {
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
