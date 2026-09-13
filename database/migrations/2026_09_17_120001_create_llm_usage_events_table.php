<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `llm_usage_events` — phase-11a-ai-contract.md §3.1. FROZEN SHAPE.
 *
 * PLATFORM-OWNED, UNPREFIXED — the precedent is `risk_audit_trail`. Written by
 * `App\Services\Llm\UsageRecorder` for every gateway call from every module,
 * INCLUDING REFUSALS. Append-only: no `updated_at`.
 *
 * `organization_id` IS NEVER NULL. A call with no tenant is refused before a
 * row is ever built, not recorded unattributed — a nullable value here would
 * be silently excluded by the `BelongsToOrganization` global scope, the exact
 * `QuestionnaireTemplate` trap ADR 0015 §5 names.
 *
 * THREE INDEXES AND NO MORE. `(organization_id, usage_month, service)` serves
 * both the cap check (its own leftmost two columns) and the by-service report
 * — a separate `(organization_id, usage_month)` index would be a redundant
 * prefix of it. No index on `outcome` or `module`: both are low-cardinality
 * and always filtered alongside `organization_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('module', 20);
            $table->string('service', 40);
            $table->string('prompt_key', 60);
            $table->string('prompt_version', 40);
            $table->string('endpoint_profile', 40);
            $table->string('model', 120);
            $table->string('outcome', 20);
            $table->unsignedTinyInteger('attempts')->default(1);

            // Backend-reported. Null when not reported — never 0.
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            // Stored, not derived at read time, so the monthly SUM is one
            // column with no SQL arithmetic — MariaDB 10.4, no window
            // function, no generated-column arithmetic required.
            $table->unsignedInteger('total_tokens')->nullable();

            $table->unsignedInteger('duration_ms');

            // Populated only when the resolved endpoint profile declares a
            // price. Null = not priced, never 0 — ADR 0015 §4.
            $table->unsignedInteger('unit_cost_minor')->nullable();
            $table->char('currency', 3)->nullable();

            // 'YYYY-MM', computed in PHP at insert. The portable-SQL device
            // that makes the monthly aggregate index-usable on MariaDB 10.4 —
            // no DATE_FORMAT(created_at, ...) in a WHERE clause anywhere
            // against this table.
            $table->char('usage_month', 7);

            $table->string('subject_type', 255)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // No updated_at: the row is append-only. NOT NULLABLE (contract
            // §3.1, Gate 2 advisory 5) — `UsageRecorder::record()` always
            // supplies `now()`, and a null row would never match
            // `PruneLlmUsageEvents`'s `where('created_at', '<', $cutoff)`,
            // so it would be retained forever rather than pruned.
            $table->timestamp('created_at');

            $table->index(['organization_id', 'usage_month', 'service'], 'llm_usage_events_org_month_service_idx');
            $table->index(['organization_id', 'created_at'], 'llm_usage_events_org_created_idx');
            $table->index(['subject_type', 'subject_id'], 'llm_usage_events_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_usage_events');
    }
};
