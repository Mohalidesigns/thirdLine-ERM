<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 2C (reduced) — ADR 0018 §2. Three tables, one column on
 * `bcms_contacts`, one unique index. The schema freeze reads
 * 15 → 8 → 5 → 1 → 0 → 1 → 0 → 0 (P7.5) → this: 3 tables, 1 column, 1 index.
 *
 * `client_secret` IS TEXT AND IS NEVER JSON (ADR 0018 §2.2 point 1). Laravel's
 * `encrypted` cast produces a base64 envelope, which is not valid JSON;
 * MariaDB's inline `json_valid()` CHECK on a json column rejects it — the
 * `connectors.config` defect, post-mortemed in
 * `2026_09_07_130001_store_connector_config_as_text_not_json.php`, where every
 * insert into that table had failed on a real database since it existed.
 * `attribute_map`, `before_json`, `after_json` and `impact_json` ARE json
 * columns: they are not encrypted, and they hold structured data, not a
 * credential.
 *
 * No CTE, no window function, no raw JSON function — MariaDB 10.4 is the only
 * database this runs against in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcms_identity_connectors', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // 20 chars even though `entra` is the only case today — ADR 0018
            // §2.2 point 6: when a second provider (LDAPS, 2D) arrives the
            // unique index already has room for it.
            $table->string('provider', 20)->default('entra');
            $table->string('name', 120);
            $table->string('directory_tenant_id', 100);
            $table->string('client_id', 100);
            $table->text('client_secret')->nullable();
            $table->string('token_base_url', 190);
            $table->string('graph_base_url', 190);

            // An OData `$filter` over user attributes — NOT a group id. Scoping
            // by group membership needs `GroupMember.Read.All`, a second read
            // scope this phase refuses (ADR 0018 §3.2).
            $table->string('directory_filter', 255)->nullable();

            // Nullable: a null map reads Blueprint §8.2's defaults from
            // `App\Support\Bcms\DirectoryAttributeMap` in code, so improving a
            // default needs no data migration across every tenant that never
            // touched the screen.
            $table->json('attribute_map')->nullable();

            $table->string('sync_schedule', 20)->default('nightly'); // nightly|nightly_plus_delta|manual
            $table->string('auto_apply_policy', 20)->default('none'); // none|safe_only — no `all`

            // State, not a derived figure: the pointer the next delta run needs.
            $table->text('delta_link')->nullable();

            // Input, not derivation — Graph does not tell a client when its own
            // secret expires; the admin copies the date from the app
            // registration (ADR 0018 §2.2 point 4).
            $table->date('credential_expires_on')->nullable();

            // Defaults to false: a connector is created disabled and nothing
            // syncs until a human has run Test connection (ADR 0018 §2.2
            // point 5).
            $table->boolean('is_active')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // No soft deletes and no delete route (ADR 0018 §2.2 point 6):
            // runs and change rows are the audit trail of a directory read,
            // and deactivation is the off switch.
            $table->unique(['organization_id', 'provider']);
        });

        Schema::create('bcms_identity_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identity_connector_id')->constrained('bcms_identity_connectors')->cascadeOnDelete();

            $table->string('trigger', 20); // scheduled_full|scheduled_delta|manual

            // `dateTime`, NOT `timestamp` — this server runs with
            // `explicit_defaults_for_timestamp` OFF (MariaDB's legacy
            // compatibility default), under which a NOT-NULL `TIMESTAMP`
            // column with no explicit default silently gets
            // `DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()`
            // attached by the server. `finish()`/`abort()` both UPDATE this
            // row (setting `status`/`finished_at`) well after `started_at`
            // was written, and that implicit ON UPDATE clause silently
            // overwrote `started_at` to the moment of THAT update, in the
            // server's SYSTEM time zone (`WAT`, UTC+1 on this box) rather
            // than PHP's `now()` (UTC) — every completed run showed
            // `finished_at` roughly an hour BEFORE `started_at`, because
            // `started_at` had been bumped forward past it. `DATETIME` never
            // carries an implicit default or auto-update in MySQL/MariaDB;
            // only `TIMESTAMP` does. `finished_at` was never affected — it is
            // nullable, and a nullable timestamp gets `DEFAULT NULL` with no
            // auto-update — but it moves to `dateTime` too, for the same
            // column-type consistency `Model::casts()` already assumes.
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 20)->default('running'); // running|success|partial|failed

            $table->unsignedInteger('directory_objects_read')->default(0);
            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('joiner_count')->default(0);
            $table->unsignedInteger('leaver_count')->default(0);
            $table->unsignedInteger('mover_count')->default(0);
            $table->unsignedInteger('contact_change_count')->default(0);
            $table->unsignedInteger('auto_applied_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);

            // Graph's bounded `error.code`, or the HTTP status — never
            // `error.message`, never `$e->getMessage()` (ADR 0018 §2.3).
            $table->string('error_class', 190)->nullable();
            $table->string('error_code', 40)->nullable();

            // Null is the scheduler.
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'identity_connector_id', 'started_at'], 'bcms_isr_org_connector_started_idx');
            $table->index(['organization_id', 'status'], 'bcms_isr_org_status_idx');
        });

        Schema::create('bcms_identity_sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sync_run_id')->constrained('bcms_identity_sync_runs')->cascadeOnDelete();

            // Nullable: a joiner has no contact yet.
            $table->foreignId('contact_id')->nullable()->constrained('bcms_contacts')->nullOnDelete();

            $table->string('kind', 20); // joiner|leaver|mover|contact_change
            $table->string('directory_object_id', 64);
            $table->string('subject_name', 200);

            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->json('impact_json')->nullable();

            // Evaluated against the estate as it stood when the sync ran, and
            // stored rather than recomputed at read time (ADR 0018 §2.4):
            // recomputing when the queue is opened would let an unrelated tree
            // edit silently turn a change that needed a signature into one
            // that did not.
            $table->boolean('requires_ack')->default(false);

            $table->string('decision', 20)->default('pending'); // pending|approved|rejected|auto_applied|superseded
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('apply_error_class', 190)->nullable();
            $table->timestamps();

            // No `uuid` — a child table addressed nested under its run
            // (ADR 0007 deviation 4; ADR 0018 §2.4).
            $table->unique(['sync_run_id', 'directory_object_id', 'kind'], 'bcms_isc_run_object_kind_unique');
            $table->index(['organization_id', 'decision']);
            $table->index(['organization_id', 'kind', 'decision']);
            $table->index(['contact_id']);
        });

        Schema::table('bcms_contacts', function (Blueprint $table) {
            // The reporting edge a directory-sourced roster needs (ADR 0018
            // §2.1). Self-referential, so it ships with a cycle guard at every
            // call site that walks it (TreeProposalService::arrange() already
            // has one).
            $table->unsignedBigInteger('manager_contact_id')->nullable()->after('manager_user_id');
            $table->foreign('manager_contact_id')->references('id')->on('bcms_contacts')->nullOnDelete();

            // The sync's match key. Existing rows all have a null
            // `ad_object_guid`, and a unique index treats every NULL as
            // distinct on both MySQL and MariaDB, so this adds no constraint
            // on data that predates the sync.
            $table->unique(['organization_id', 'ad_object_guid'], 'bcms_contacts_org_ad_guid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bcms_contacts', function (Blueprint $table) {
            $table->dropUnique('bcms_contacts_org_ad_guid_unique');
            $table->dropForeign(['manager_contact_id']);
            $table->dropColumn('manager_contact_id');
        });

        Schema::dropIfExists('bcms_identity_sync_changes');
        Schema::dropIfExists('bcms_identity_sync_runs');
        Schema::dropIfExists('bcms_identity_connectors');
    }
};
