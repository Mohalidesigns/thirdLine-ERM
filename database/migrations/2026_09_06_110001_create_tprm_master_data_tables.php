<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 0, part 1 of 9 — master data (TRD §8.1).
 *
 * The two-level model in TRD §5.1 is the whole design and it is worth stating
 * before the first table: a THIRD PARTY is a legal entity, and an ENGAGEMENT
 * is a thing we buy from it. Risk is assessed at the engagement, never at the
 * entity. The same vendor hosting our core banking platform and printing our
 * Christmas cards is one row here and two rows in `tp_engagements`, with
 * different tiers, different questionnaires and different exit obligations.
 * Corporate evidence — an ISO certificate, a SOC 2, audited accounts — hangs
 * off the entity and is INHERITED by every engagement but SCORED per
 * engagement, because a SOC 2 covering the vendor's cloud platform is strong
 * evidence for a hosting engagement and close to worthless for a call centre.
 *
 * THREE DEVIATIONS FROM THE TRD, each because the TRD was written against a
 * generic Laravel codebase and this one has settled conventions:
 *
 *   1. `organization_id`, not `tenant_id`. Tenancy in this product is
 *      `ThirdLine\Platform\Tenancy\BelongsToOrganization`, a global scope on
 *      that column (development standard §7). A `tenant_id` column would be
 *      outside the scope and outside `TenancyIsolationTest`, which is the
 *      guard that actually proves cross-tenant reads return nothing.
 *
 *   2. bigint primary keys with a separate unique `uuid` column, not UUID
 *      primary keys. The TRD asks for UUIDs "where the object may be
 *      referenced externally"; this product already answers that need with the
 *      `uuid` column pattern on `risks`, `controls` and the rest, and
 *      `HasObjectIdentity` mirrors rows into the object graph by numeric key.
 *      UUID PKs here would mean the graph index could not reference them.
 *
 *   3. `is_prohibited_outsourcing` lives on `tp_business_functions` — the
 *      thing that may not be outsourced — and also on `tp_categories`, which
 *      is where the TRD puts it. Both are needed: the function carries the
 *      prohibition the CBN actually states, and the category carries a tier
 *      floor for a whole class of vendor. AC-01 is enforced against the
 *      function.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Categories — the vendor taxonomy */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tp_categories')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->text('description')->nullable();

            // A category may floor the tier of every engagement under it. A
            // core banking provider is not permitted to come out Low because
            // somebody answered the intake questionnaire optimistically.
            $table->string('default_tier_floor', 20)->nullable();

            // ICT categories are the population of the CBN Cyber Framework
            // Appendix II §1.4 register and the DORA register of information.
            $table->boolean('is_ict')->default(false);

            // Set on categories that describe a function a bank may not
            // outsource at all. The binding prohibition is on the FUNCTION;
            // this flag is a second net for the category as a whole.
            $table->boolean('is_prohibited_outsourcing')->default(false);
            $table->string('prohibition_citation', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        /* ------------------------------------------------------------------ */
        /*  Third parties — the legal entities */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_third_parties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('legal_name', 255);
            $table->string('trading_name', 255)->nullable();
            $table->string('slug', 255);

            // The three identifiers deduplication matches on exactly
            // (FR-TPR-02): CAC registration number, FIRS tax identification
            // number, and the Legal Entity Identifier where the vendor has
            // one. Fuzzy name matching is the fallback, not the primary key.
            $table->string('registration_number', 60)->nullable();
            $table->string('tax_id', 60)->nullable();
            $table->string('lei', 20)->nullable();

            $table->string('entity_type', 30)->nullable();
            $table->string('ownership_type', 40)->nullable();
            $table->string('country_of_incorporation', 2)->nullable();
            $table->string('country_of_hq', 2)->nullable();
            $table->string('website', 255)->nullable();
            $table->unsignedSmallInteger('year_established')->nullable();
            $table->string('employee_band', 30)->nullable();

            // Self-reference up to the ultimate parent. This is what the
            // concentration analyser groups by: five engagements with five
            // subsidiaries of one group is not a diversified portfolio, and
            // the HHI in TRD §7.8 is computed over the group, not the entity.
            $table->foreignId('ultimate_parent_id')->nullable()->constrained('tp_third_parties')->nullOnDelete();
            $table->boolean('is_intra_group')->default(false);

            $table->string('status', 30)->default('prospect');

            $table->foreignId('relationship_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('oversight_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('tp_categories')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->boolean('portal_enabled')->default(false);

            // Roll-ups maintained by the scoring service, never computed in a
            // view (TRD §8.10). A register listing 5,000 vendors must not pay
            // for the derivation on every page load.
            $table->decimal('data_confidence', 3, 2)->nullable();
            $table->decimal('aggregate_residual', 5, 2)->nullable();

            $table->string('screening_status', 30)->nullable();
            $table->timestamp('last_screened_at')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->text('blacklist_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'legal_name']);
            $table->index(['organization_id', 'registration_number']);
            $table->index(['organization_id', 'category_id']);
            $table->index(['organization_id', 'last_screened_at']);
        });

        /* ------------------------------------------------------------------ */
        /*  Locations */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('role', 30);
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 2)->nullable();

            // Where personal data physically sits. NDPA §41 cross-border
            // analysis reads this, not the vendor's headquarters country.
            $table->boolean('is_data_processing_location')->default(false);

            // Uptime Institute or TIA-942 rating where the location is a data
            // centre — the CBN Cyber Framework Appendix II §1.4 data-centre
            // inspection record refers to it.
            $table->string('datacentre_tier', 30)->nullable();
            $table->json('certifications')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'third_party_id']);
            $table->index(['organization_id', 'country']);
        });

        /* ------------------------------------------------------------------ */
        /*  Contacts */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('name', 200);
            $table->string('role_type', 30);
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary_portal_contact')->default(false);

            // Populated in Phase 8 when the contact accepts a portal
            // invitation. Deliberately NOT a foreign key to `users`: a portal
            // user is a separate table with a separate guard and no
            // relationship to the internal user model, which is the whole
            // security argument of the portal.
            $table->unsignedBigInteger('portal_user_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'third_party_id', 'role_type']);
        });

        /* ------------------------------------------------------------------ */
        /*  Ownership — shareholders, directors and UBOs */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_ownership', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('third_party_id')->constrained('tp_third_parties')->cascadeOnDelete();

            $table->string('holder_name', 255);
            $table->string('holder_type', 20);
            $table->string('relationship', 30);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('nationality', 2)->nullable();
            $table->date('date_of_birth')->nullable();

            $table->boolean('is_pep')->default(false);
            $table->string('pep_category', 60)->nullable();
            $table->string('source', 120)->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The screening service reads this index: CBN AML/CFT Regulations
            // Reg. 29 requires every director and beneficial owner to be
            // screened, not just the entity, and re-screened on any change.
            $table->index(['organization_id', 'third_party_id', 'relationship']);
            $table->index(['organization_id', 'is_pep']);
        });

        /* ------------------------------------------------------------------ */
        /*  Business functions — the DORA RT.06.01 model */
        /* ------------------------------------------------------------------ */

        Schema::create('tp_business_functions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('function_code', 40);
            $table->string('name', 200);
            $table->text('description')->nullable();

            // The org node that owns the function. `business_units` is this
            // product's org chart; the TRD's `org_unit_id` is that.
            $table->foreignId('owning_business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();

            $table->string('licensed_activity', 200)->nullable();

            // critical | important | standard. Drives the CRIT factor in the
            // inherent model and the KO-CIF knockout.
            $table->string('criticality', 20)->default('standard');
            $table->text('criticality_rationale')->nullable();
            $table->date('criticality_assessed_at')->nullable();

            $table->unsignedInteger('mtpd_hours')->nullable();
            $table->unsignedInteger('rto_hours')->nullable();
            $table->unsignedInteger('rpo_hours')->nullable();
            $table->text('mbco')->nullable();
            $table->text('impact_of_discontinuation')->nullable();

            // AC-01 IS ENFORCED AGAINST THESE TWO COLUMNS. Selecting a
            // function flagged here hard-blocks intake submission and renders
            // the citation. The citation is stored per row rather than
            // hardcoded in the error message because the prohibited set
            // differs by licence type — a PMB and a DMB are not told the same
            // thing — and a client must never be shown a citation that does
            // not apply to it.
            $table->boolean('is_prohibited_outsourcing')->default(false);
            $table->string('prohibition_citation', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'function_code']);
            $table->index(['organization_id', 'criticality']);
            $table->index(['organization_id', 'is_prohibited_outsourcing'], 'tp_bf_prohibited_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_business_functions');
        Schema::dropIfExists('tp_ownership');
        Schema::dropIfExists('tp_contacts');
        Schema::dropIfExists('tp_locations');
        Schema::dropIfExists('tp_third_parties');
        Schema::dropIfExists('tp_categories');
    }
};
