<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens for SCIM 2.0 provisioning, one or more per organization.
 *
 * Only the SHA-256 of the token is stored. A directory that loses its copy
 * gets a new token; nobody with database access gets to impersonate an IdP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scim_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_tokens');
    }
};
