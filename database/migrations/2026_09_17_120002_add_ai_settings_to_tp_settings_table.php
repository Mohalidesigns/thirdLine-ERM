<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 11a — the per-tenant AI switches, on the settings table that
 * already exists. phase-11a-ai-contract.md §3.2. FROZEN SHAPE.
 *
 * `ai_enabled` IS A NULLABLE BOOLEAN ON PURPOSE. Three states: true = the
 * tenant opted in, false = the tenant opted out, null = nobody has asked this
 * tenant, follow `config('tprm.ai.enabled')`. ADR 0015 §2 explains why this
 * differs from `bcms_settings.ai_enabled` (`boolean default false`): TPRM has
 * a module-level deployment master switch to fall through to and BCMS does
 * not, so a two-state column here would force every existing tenant to
 * `false` on migration and a later deployment-level "on" would then do
 * nothing until someone incorrectly backfilled `true` across every tenant.
 *
 * `ai_cap_action` WAS CONSIDERED AND REJECTED — see the contract. A cap that
 * only warns is not a cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tp_settings', function (Blueprint $table) {
            $table->boolean('ai_enabled')->nullable()->default(null)->after('regulatory_contact_title');

            // {"evidence_extraction": true, "clause_analysis": false}. An
            // absent key means "not decided", not false. Never queried in
            // SQL, so `json` is safe on MariaDB 10.4.
            $table->json('ai_services')->nullable()->after('ai_enabled');

            // A KEY into config('llm.profiles'), never a URL — ADR 0015 §3.
            $table->string('ai_endpoint_profile', 40)->nullable()->after('ai_services');

            // Tokens and calls, not currency — ADR 0015 §4. Null = uncapped.
            $table->unsignedBigInteger('ai_monthly_token_cap')->nullable()->after('ai_endpoint_profile');
            $table->unsignedInteger('ai_monthly_call_cap')->nullable()->after('ai_monthly_token_cap');
        });
    }

    public function down(): void
    {
        Schema::table('tp_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ai_enabled', 'ai_services', 'ai_endpoint_profile', 'ai_monthly_token_cap', 'ai_monthly_call_cap',
            ]);
        });
    }
};
