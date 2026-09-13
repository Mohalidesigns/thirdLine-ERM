<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The global vendor identity the reusable trust profile hangs from —
 * FR-PRT-04, and the fix for a contradiction Phase 0 left behind.
 *
 * PHASE 0's `tp_trust_profiles` CARRIED A COMMENT SAYING THE PROFILE BELONGS
 * TO THE VENDOR RATHER THAN TO ONE OF OUR TENANTS, AND THEN KEYED IT ON
 * `third_party_id` — WHICH IS TENANT-SCOPED. Lagos Union Bank's "Cloudspan
 * Nigeria Limited" and Abuja Trust Bank's are two rows in
 * `tp_third_parties`, so a profile keyed on one of them can never be the same
 * profile the other bank sees. The acceptance criterion for this phase is
 * precisely that a vendor invited to two tenants completes its profile ONCE,
 * so the contradiction had to be resolved rather than worked around.
 *
 * `tp_vendor_identities` is the row that means "this company, in the world".
 * It carries no `organization_id` — deliberately, and it is the second table
 * in the module outside the tenant scope after `tp_trust_profiles` itself.
 * Everything a tenant can read about it goes through a share row.
 *
 * NOTHING LINKS A THIRD PARTY TO AN IDENTITY AUTOMATICALLY. Not by name, not
 * fuzzily, not on a registration number typed by a procurement officer in a
 * hurry. A wrong link here does not merely mis-cluster a report — it shows one
 * bank the security posture another bank's vendor declared, which is a
 * disclosure incident. The link is made by the VENDOR, in the portal, as an
 * explicit act; see `VendorIdentityService`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_vendor_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // What the vendor calls itself. Not authoritative for anything —
            // each tenant keeps its own `legal_name` on its own third-party
            // row, because banks disagree about names and neither is wrong.
            $table->string('canonical_name', 255);

            /*
             * Registration number and LEI are UNIQUE WHERE PRESENT, which is
             * what makes a duplicate identity detectable. They are not how the
             * link is made — see the class comment — but two identities
             * claiming one RC number is a data-quality problem somebody should
             * be told about.
             */
            $table->string('registration_number', 60)->nullable();
            $table->string('country_of_incorporation', 2)->nullable();
            $table->string('lei', 20)->nullable();
            $table->string('primary_domain', 255)->nullable();

            $table->timestamps();

            $table->unique(['registration_number', 'country_of_incorporation'], 'tp_vendor_identity_rc_unique');
            $table->unique('lei');
        });

        Schema::table('tp_third_parties', function (Blueprint $table): void {
            // Nullable, and stays null for most rows. A vendor with no portal
            // account has no identity and needs none; the column fills in when
            // a vendor claims its profile.
            $table->foreignId('vendor_identity_id')
                ->nullable()
                ->after('ultimate_parent_id')
                ->constrained('tp_vendor_identities')
                ->nullOnDelete();

            $table->index(['organization_id', 'vendor_identity_id'], 'tp_third_party_identity_idx');
        });

        /*
         * Re-key the trust profile onto the identity.
         *
         * The table is empty on every installation — nothing in Phases 0 to 8a
         * writes to it — so this drops and recreates rather than carrying a
         * migration that would have to invent identities for rows that do not
         * exist.
         */
        Schema::dropIfExists('tp_trust_profile_shares');
        Schema::dropIfExists('tp_trust_profiles');

        Schema::create('tp_trust_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // NO organization_id, and now genuinely so: the identity it hangs
            // from has none either.
            $table->foreignId('vendor_identity_id')->constrained('tp_vendor_identities')->cascadeOnDelete();

            /*
             * DRAFT AND PUBLISHED ARE SEPARATE COLUMNS, not one document with
             * a status. A vendor editing next quarter's answers must not
             * change what four banks are currently reading, and a status flag
             * on one document forces exactly that: either the edits are live
             * or the vendor cannot start them.
             */
            $table->json('draft')->nullable();
            $table->json('published')->nullable();

            $table->unsignedInteger('published_version')->default(0);
            $table->timestamp('last_published_at')->nullable();

            // Computed on save from the published document. Stored because the
            // "what unlocks faster onboarding" panel and the internal register
            // both read it, and recomputing per row per render is a lot of
            // JSON parsing for a number that changes when somebody types.
            $table->decimal('completeness_pct', 5, 2)->default(0);

            $table->timestamps();

            $table->unique('vendor_identity_id');
        });

        Schema::create('tp_trust_profile_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trust_profile_id')->constrained('tp_trust_profiles')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Which third-party row in that tenant this share feeds. Lets the
            // client's own screens find the profile without scanning.
            $table->foreignId('third_party_id')->nullable()->constrained('tp_third_parties')->nullOnDelete();

            /*
             * Which sections the client may read. A vendor that must disclose
             * everything to everybody will disclose nothing to anybody, and
             * the sections a bank actually needs differ by what it buys.
             */
            $table->json('scope')->nullable();

            $table->string('status', 20)->default('requested');

            // The vendor's explicit, per-client approval. NULL MEANS NO
            // ACCESS, everywhere, and must never read as "not yet checked".
            $table->timestamp('approved_by_vendor_at')->nullable();
            $table->unsignedBigInteger('approved_by_portal_user_id')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();

            $table->unique(['trust_profile_id', 'organization_id'], 'tp_share_unique');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_trust_profile_shares');
        Schema::dropIfExists('tp_trust_profiles');

        Schema::table('tp_third_parties', function (Blueprint $table): void {
            /*
             * THE INDEX IS DROPPED EXPLICITLY, BEFORE THE COLUMN. MySQL does
             * not remove a COMPOSITE index when one of its columns goes — it
             * silently narrows it to the remaining columns — so a rollback
             * that only dropped the column left `tp_third_party_identity_idx`
             * behind, and the next `migrate` then failed on a duplicate key
             * name. Found by running down() and up() against MySQL rather than
             * against the SQLite the tests use, where it would never have
             * shown.
             */
            $table->dropIndex('tp_third_party_identity_idx');
            $table->dropConstrainedForeignId('vendor_identity_id');
        });

        Schema::dropIfExists('tp_vendor_identities');
    }
};
