<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0024 §1 — one column: `bcms_alerts.template_variables`, the operator's
 * own words, and nothing else.
 *
 * HOLDS ONLY WHAT AN OPERATOR TYPED AT COMPOSE, never a derived value
 * (`TemplateRenderer` recomputes those at render), never a per-recipient
 * value, never ciphertext. Handled PHP-side only — no `JSON_*` function ever
 * touches this column in a query; it is never filtered, sorted or searched
 * (CLAUDE.md, MariaDB 10.4). MariaDB stores `json` as `LONGTEXT` behind a
 * `json_valid()` CHECK, which is exactly why an encrypted envelope can never
 * land here by accident the way it did on `connectors.config`.
 *
 * WRITE ONCE. Written at `AlertService::compose()` and never updated — there
 * is no alert-update route today, and this ADR adds none (§1: a value
 * changed after a first approval would make the second approver sign text
 * the first never saw).
 *
 * NO BARE `timestamp()` (ADR 0022) — this migration adds no timestamp
 * column at all, so the question does not arise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcms_alerts', function (Blueprint $table) {
            $table->json('template_variables')->nullable()->after('response_options');
        });
    }

    public function down(): void
    {
        Schema::table('bcms_alerts', function (Blueprint $table) {
            $table->dropColumn('template_variables');
        });
    }
};
