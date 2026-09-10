<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 9 of 9 — the vendor portal, governance, waivers, imports,
 * the append-only audit log, and the bridge into the ERM register
 * (TRD §8.9, §15).
 *
 * `tp_portal_users` HAS NO RELATIONSHIP TO `users`, AND THAT IS THE WHOLE
 * SECURITY ARGUMENT OF THE PORTAL. Its own table, its own guard, its own
 * session cookie, its own middleware stack, its own route file. A vendor's
 * employee authenticating here can reach nothing internal, and AC-14 requires
 * that be proven by automated probes rather than asserted. A foreign key from
 * this table to `users` would make the two populations one population with a
 * flag, and a flag is one bug away from being read the wrong way round.
 *
 * `tp_audit_logs` IS APPEND-ONLY. No update route, no delete route, and a
 * model-level guard that throws rather than a policy that returns false —
 * because a policy protects a route and an append-only guarantee has to
 * survive a job, a console command and a future developer's tinker session.
 *
 * `tp_waivers` collects EVERY override in the module in one table: a tier
 * override, a blocking-clause waiver, a due-diligence waiver, an access
 * exception. They were four separate escape hatches in the TRD and each one
 * would have grown its own approver column, its own expiry and its own
 * half-built report. One table means one override register, which is what a
 * supervisor asks for and what a risk committee should be reading anyway.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE ERM BRIDGE — how third-party exposure reaches the key risk areas
 * ─────────────────────────────────────────────────────────────────────────
 *
 * The module is not a silo beside the register; TRD §15 requires it to feed
 * the register, and three columns carry that:
 *
 *   `tp_categories.erm_risk_category_id`  Every vendor category maps to a node
 *       of `risk_categories` — the product's key risk areas. This is what makes
 *       "how much of our operational risk area is third-party exposure" a
 *       query rather than a spreadsheet exercise. Nullable, because a tenant
 *       whose taxonomy has no third-party node yet must still be able to use
 *       the module; the roll-up simply reports uncategorised until it is set.
 *
 *   `tp_engagements.erm_risk_id`          (part 2) The risk row a High or
 *       Critical engagement is represented by in the register. One way: TPRM
 *       computes the score, the register carries the treatment.
 *
 *   `tp_kri_links`                        The eight programme KRIs of
 *       FR-RPT-10 published as `key_risk_indicators` rows with automated
 *       collection, so third-party indicators sit in the same board pack as
 *       every other KRI instead of in a separate report nobody opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Vendor portal */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_portal_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('email', 255);
            $table->string('name', 200);
            $table->string('password');

            // MFA is MANDATORY on this guard, not optional as it is internally.
            // A vendor account reaches assessment content and evidence for one
            // organisation, is used from outside our network, and is the
            // likeliest credential in the system to be reused elsewhere.
            $table->text('mfa_secret')->nullable();
            $table->boolean('mfa_enabled')->default(false);

            $table->string('status', 20)->default('invited');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Email is unique PER ORGANISATION, not globally: one person at a
            // shared service provider may hold accounts for several of our
            // tenants, and those accounts must not collide or merge.
            $table->unique(['organization_id', 'email'], 'tp_portal_user_email_unique');
            $table->index(['organization_id', 'third_party_id']);
        });

        Schema::create('tp_portal_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('email', 255);

            // HASHED, single-use. The token itself is never stored: an
            // invitation table readable by a database backup is an invitation
            // table anybody with the backup can accept.
            $table->string('token_hash', 64);

            $table->string('role', 40)->default('responder');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('token_hash');
            $table->index(['organization_id', 'third_party_id']);
        });

        Schema::create('tp_trust_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            // NO organization_id. A trust profile belongs to the VENDOR, not
            // to one of our tenants — that is the point of it. The vendor
            // completes it once and grants access per client through
            // `tp_trust_profile_shares`, which is where tenancy lives. This is
            // the only table in the module outside the tenant scope, and every
            // read of it goes through a share row.
            $table->unsignedInteger('published_version')->default(0);
            $table->json('company_profile')->nullable();
            $table->json('standard_answers')->nullable();
            $table->json('certifications')->nullable();
            $table->json('subprocessors')->nullable();
            $table->timestamp('last_published_at')->nullable();
            $table->decimal('completeness_pct', 5, 2)->default(0);

            $table->timestamps();

            $table->unique('third_party_id');
        });

        Schema::create('tp_trust_profile_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_profile_id')->constrained('tp_trust_profiles')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->json('scope')->nullable();

            // The vendor's explicit, per-client approval. Null means the share
            // has been requested and not granted, and a null here must read as
            // "no access" everywhere — never as "not yet checked".
            $table->timestamp('approved_by_vendor_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['trust_profile_id', 'organization_id'], 'tp_share_unique');
        });

        /* ------------------------------------------------------------------ */
        /*  Programme governance */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('title', 255);
            $table->string('version', 20);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('next_review_at')->nullable();
            $table->boolean('is_current')->default(false);

            $table->timestamps();

            $table->index(['organization_id', 'is_current']);
        });

        Schema::create('tp_programme_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('period', 40);
            $table->string('reviewer', 200)->nullable();

            // Whether the reviewer was independent of the programme being
            // reviewed. A self-assessment presented as an independent review
            // is the finding an examiner writes.
            $table->string('reviewer_independence', 60)->nullable();

            $table->text('scope')->nullable();
            $table->json('findings')->nullable();

            // VRMMM's eight categories 0–5 and the CSF 2.0 GV.SC subcategory
            // scores, kept together so the maturity trend is one query.
            $table->json('maturity_scores')->nullable();

            $table->timestamp('reported_to_board_at')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'period']);
        });

        /* ------------------------------------------------------------------ */
        /*  Waivers — one override register for the whole module */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // tier_override | blocking_clause | due_diligence_item |
            // access_exception | offboarding_item.
            $table->string('waivable_type', 40);
            $table->unsignedBigInteger('waivable_id');

            $table->foreignId('engagement_id')->nullable()->constrained('tp_engagements')->cascadeOnDelete();

            $table->text('rationale');
            $table->text('compensating_controls')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_role', 120)->nullable();
            $table->timestamp('approved_at')->nullable();

            // NOT NULLABLE ONCE APPROVED. Enforced in the Form Request rather
            // than the column, because a draft waiver exists before anyone has
            // decided how long it should run. A waiver with no expiry is a
            // permanent exception with a rationale written by somebody who has
            // since left.
            $table->date('expires_at')->nullable();

            $table->string('status', 20)->default('requested');
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'waivable_type', 'waivable_id'], 'tp_waiver_subject_idx');
            $table->index(['organization_id', 'status', 'expires_at'], 'tp_waiver_status_idx');
        });

        /* ------------------------------------------------------------------ */
        /*  Reversible bulk import */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('target', 40);
            $table->string('original_filename', 255)->nullable();
            $table->string('file_path', 500)->nullable();

            $table->json('column_mapping')->nullable();
            $table->string('status', 20)->default('draft');

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->json('errors')->nullable();

            // Every id this batch created, so the commit is reversible
            // (FR-TPR-09). An import that cannot be rolled back is an import
            // nobody dares run against production data, which means the
            // migration path in TRD §18 never gets used.
            $table->json('created_ids')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        /* ------------------------------------------------------------------ */
        /*  Append-only audit log */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('auditable_type', 120);
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 60);

            // user | portal_user | system. A portal actor is not a user, and
            // recording one as the other would make a vendor's action look
            // like a colleague's on the supervisory export.
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 200)->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('correlation_id', 64)->nullable();

            // A HASH CHAIN, matching `risk_audit_trail`. Append-only stops a
            // row being changed through the application; the chain makes a
            // change made AROUND the application — straight SQL against the
            // table — detectable, because every row commits to its
            // predecessor and a removed, reordered or edited row breaks the
            // digest of every row after it. TRD §14 asks for an audit trail a
            // supervisor can rely on, and "our code does not update it" is a
            // weaker claim than "here is the arithmetic".
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64)->nullable();

            // NO `updated_at`, NO `deleted_at`. Both would imply this row can
            // change, and it cannot: the model guard throws on update and
            // delete, and there is no route to either.
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'auditable_type', 'auditable_id'], 'tp_audit_subject_idx');
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'event']);
        });

        /* ------------------------------------------------------------------ */
        /*  The ERM bridge */
        /* ------------------------------------------------------------------ */

        Schema::table('tp_categories', function (Blueprint $table) {
            // Which key risk area a vendor category's exposure rolls up into.
            $table->foreignId('erm_risk_category_id')->nullable()->after('parent_id')
                ->constrained('risk_categories')->nullOnDelete();
        });

        Schema::create('tp_kri_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The TPRM metric this link publishes, e.g.
            // `critical_assessed_within_cadence` or `assurance_depth`. The
            // catalogue of eight lives in the KRI publisher service, not here,
            // so that adding a ninth is a code change with a test rather than
            // a row somebody inserts by hand.
            $table->string('metric_code', 60);

            $table->foreignId('key_risk_indicator_id')->constrained('key_risk_indicators')->cascadeOnDelete();

            // Optional narrowing: a KRI computed for one business unit or one
            // tier rather than the whole portfolio.
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->string('tier_filter', 20)->nullable();

            $table->timestamp('last_published_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'metric_code', 'key_risk_indicator_id'], 'tp_kri_link_unique');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('tp_contacts', function (Blueprint $table) {
                $table->foreign('portal_user_id')->references('id')->on('tp_portal_users')->nullOnDelete();
            });
            foreach (['tp_policies', 'tp_programme_reviews'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreign('document_id')->references('id')->on('tp_documents')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            foreach (['tp_programme_reviews', 'tp_policies'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['document_id']);
                });
            }
            Schema::table('tp_contacts', function (Blueprint $table) {
                $table->dropForeign(['portal_user_id']);
            });
        }

        Schema::dropIfExists('tp_kri_links');

        Schema::table('tp_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('erm_risk_category_id');
        });

        Schema::dropIfExists('tp_audit_logs');
        Schema::dropIfExists('tp_import_batches');
        Schema::dropIfExists('tp_waivers');
        Schema::dropIfExists('tp_programme_reviews');
        Schema::dropIfExists('tp_policies');
        Schema::dropIfExists('tp_trust_profile_shares');
        Schema::dropIfExists('tp_trust_profiles');
        Schema::dropIfExists('tp_portal_invitations');
        Schema::dropIfExists('tp_portal_users');
    }
};
