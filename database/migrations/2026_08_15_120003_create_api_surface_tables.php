<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-07 TASK 2 — what the API needs beyond Sanctum's stock table.
 *
 * TOKENS CARRY A TENANT. Sanctum's personal_access_tokens is tokenable-only:
 * the organization comes from the user behind it. That works for a personal
 * token and not at all for machine-to-machine, where there is no person — a
 * nightly connector authenticating as "the integration user" would otherwise
 * have to borrow somebody's account, and the audit trail would say a named
 * employee made every automated change at 3am for the next four years. So the
 * tenant is ON the token.
 *
 * IDEMPOTENCY IS A TABLE, NOT A CACHE. A client that times out and retries a
 * POST must get the FIRST response back, not a second loss event. Holding those
 * responses in a cache that may be flushed, or that a second web node cannot
 * see, means the guarantee quietly stops applying exactly when the network is
 * bad enough for anyone to need it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            // client_credentials tokens belong to a system, not a person.
            $table->string('token_type', 20)->default('personal')->after('name');
            $table->string('client_id', 64)->nullable()->unique()->after('token_type');
            $table->text('description')->nullable()->after('client_id');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            // Per-token throttling, so one runaway integration cannot spend the
            // whole tenant's budget.
            $table->unsignedInteger('rate_limit_per_minute')->default(120);

            // Where it was last used from, which is the only way anyone ever
            // answers "is this token still needed?" or "who has a copy of it?".
            $table->string('last_used_ip', 45)->nullable();

            $table->index(['organization_id', 'token_type'], 'pat_org_type_index');
        });

        // Sanctum's morphs('tokenable') is NOT NULL, which makes a token that
        // belongs to no person impossible to store — and a machine token
        // belonging to no person is the entire point of client credentials.
        // The alternative is a synthetic "integration" user, whose name then
        // appears against every automated change in the audit trail.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('tokenable_type')->nullable()->change();
            $table->unsignedBigInteger('tokenable_id')->nullable()->change();
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('key', 191);
            $table->string('method', 10);
            $table->string('path', 500);

            // A hash of the body: the same key with a DIFFERENT payload is a
            // client bug, and replaying the first response would hide it. That
            // case returns 422 rather than pretending the second request was
            // the first.
            $table->string('request_hash', 64);

            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->string('state', 20)->default('in_flight');
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            // The uniqueness that makes the guarantee work: one key per tenant.
            $table->unique(['organization_id', 'key'], 'idempotency_org_key_unique');
            $table->index('expires_at', 'idempotency_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('pat_org_type_index');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn([
                'token_type', 'client_id', 'description', 'revoked_at',
                'rate_limit_per_minute', 'last_used_ip',
            ]);
        });
    }
};
