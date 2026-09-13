<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 0, part 1 of 8 — resource registers, programme governance and the
 * BIA (Blueprint §9.1).
 *
 * PHASE 0 IS THE ONLY PHASE THAT MAY CREATE STRUCTURAL MIGRATIONS
 * (Orchestration §1, correction 3, and standing rule 2). Four parallel tracks
 * start next week against this schema; a column added in week six is a
 * migration-order collision across four branches. A phase that finds it needs
 * a column raises an ADR.
 *
 * DEVIATIONS FROM BLUEPRINT §9, argued once here and recorded in ADR 0007:
 *
 *   1. `organization_id`, not `tenant_id`. Tenancy is
 *      `ThirdLine\Platform\Tenancy\BelongsToOrganization`, a global scope on
 *      that column. A `tenant_id` would be outside the scope and outside
 *      `TenancyIsolationTest`, the guard that actually proves isolation.
 *
 *   2. `business_unit_id`, not `org_node_id`. `business_units` is already the
 *      self-parented org tree; BCMS adds no third hierarchy (ADR 0006).
 *
 *   3. bigint keys with a unique `uuid` on aggregate roots only. House pattern
 *      (`risks`, `tp_engagements`); `HasObjectIdentity` indexes by numeric key.
 *      Line tables carry no uuid because nothing outside links to them.
 *
 * THE FOUR SEAM REGISTERS at the top of this file — sites, applications,
 * equipment, data sets — exist because Blueprint §4.2 expects an Enterprise
 * Architecture module to own applications and infrastructure and this product
 * does not have one yet. They are deliberately thin, they each carry
 * `external_ref` for the day an authoritative source arrives, and the morph map
 * (ADR 0002) is the seam at which they are repointed. This is named debt, not
 * an accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------------------ */
        /*  Sites — our own facilities: branches, head office, data centres. */
        /* */
        /*  A site is NOT an org node. A branch is both a department and a */
        /*  building and carries both ids: a fire drill is about the building, */
        /*  a call tree is about the department (ADR 0006). */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_sites', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('site_type', 30)->default('office'); // office|branch|data_centre|warehouse|dr_site|remote
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 2)->default('NG');

            // Present from Phase 0 although nothing writes them until Phase 2C.
            // Geo audience targeting (ADR 0003) needs them, and adding them in
            // Phase 7 would be a structural migration.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->unsignedInteger('headcount')->nullable();
            $table->boolean('is_recovery_site')->default(false);
            $table->foreignId('recovery_site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->string('external_ref', 100)->nullable(); // see ADR 0001 — the seam
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'site_type', 'is_active']);
        });

        /* ------------------------------------------------------------------ */
        /*  Applications, equipment, data sets — the other three seams. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('vendor_name', 150)->nullable();

            // Where the application is hosted matters to both DR tiering and
            // NDPA residency, and is a different question from who supplies it.
            $table->string('hosting_model', 30)->default('on_premise'); // on_premise|private_cloud|saas|iaas|paas|hybrid
            $table->string('hosting_location', 100)->nullable();
            $table->foreignId('primary_site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // Nullable link to the TPRM engagement that supplies it, so a
            // vendor concentration question can be answered without BCMS
            // duplicating the vendor register (ADR 0001).
            $table->foreignId('tprm_engagement_id')->nullable()->constrained('tp_engagements')->nullOnDelete();

            $table->string('external_ref', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('bcms_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('equipment_type', 50)->nullable(); // generator|ups|atm|vsat|server|vehicle|…
            $table->foreignId('site_id')->nullable()->constrained('bcms_sites')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('external_ref', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('bcms_data_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('classification', 30)->nullable(); // public|internal|confidential|restricted

            // NDPA: a data set holding personal data drags residency and
            // retention questions into every plan that depends on it.
            $table->boolean('contains_personal_data')->default(false);
            $table->string('residency_country', 2)->nullable();
            $table->foreignId('primary_application_id')->nullable()->constrained('bcms_applications')->nullOnDelete();
            $table->string('external_ref', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        /* ------------------------------------------------------------------ */
        /*  Programme governance — ISO 22301 clauses 4, 5 and 6. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_programmes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->unsignedSmallInteger('year');
            $table->text('scope_statement')->nullable();
            $table->text('out_of_scope_statement')->nullable();

            // The BC policy document. Nullable because a programme is drafted
            // before the policy is approved, and forcing the order would make
            // the first screen unusable.
            $table->unsignedBigInteger('policy_document_id')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft|approved|active|closed
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Board attestation — CBN Corporate Governance 2023 board oversight.
            $table->timestamp('board_attested_at')->nullable();
            $table->foreignId('board_attested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'year', 'name']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('bcms_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('bcms_programmes')->cascadeOnDelete();
            $table->string('title', 250);
            $table->text('description')->nullable();

            // Clause 6.2 requires objectives be MEASURABLE. A target with no
            // measure is an aspiration, so the measure columns are here rather
            // than in a free-text description, and the KRI link is how the
            // measurement actually happens (Blueprint §4.2 — resilience metrics
            // are KRIs, not a second metrics engine).
            $table->string('measure_description', 250)->nullable();
            $table->decimal('target_value', 15, 4)->nullable();
            $table->string('target_unit', 30)->nullable();
            $table->foreignId('key_risk_indicator_id')->nullable()->constrained('key_risk_indicators')->nullOnDelete();

            $table->date('target_date')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open'); // open|on_track|at_risk|met|missed|withdrawn
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'programme_id', 'status']);
        });

        /* ------------------------------------------------------------------ */
        /*  Processes — the BCM overlay on the process catalogue. */
        /* */
        /*  `business_process_id` is nullable and is the reuse rule in a */
        /*  column: a tenant that has populated `business_processes` links to */
        /*  it, a tenant that has not can still run a BIA (ADR 0001). */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_processes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->foreignId('business_process_id')->nullable()->constrained('business_processes')->nullOnDelete();
            $table->foreignId('parent_process_id')->nullable()->constrained('bcms_processes')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 80)->nullable();

            // 1 is the most critical. Stored rather than derived: a tier set by
            // the last approved BIA must not silently change when somebody
            // edits an impact score in a draft (the reasoning TPRM records on
            // `tp_engagements.effective_tier`).
            $table->unsignedTinyInteger('criticality_tier')->nullable();

            // BOFIA / resolution planning: the subset a regulator treats as a
            // critical service, which is not the same as tier 1.
            $table->boolean('is_critical_service')->default(false);
            $table->json('regulatory_flags')->nullable();

            $table->string('status', 20)->default('active'); // active|under_review|retired
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'criticality_tier']);
            $table->index(['organization_id', 'is_critical_service']);
            $table->index(['organization_id', 'business_unit_id']);
        });

        /* ------------------------------------------------------------------ */
        /*  BIA — campaigns, assessments, impacts, dependencies. */
        /* ------------------------------------------------------------------ */

        Schema::create('bcms_bia_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('bcms_programmes')->nullOnDelete();
            $table->string('name', 200);
            $table->string('cycle', 20)->default('annual'); // annual|semi_annual|adhoc
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->string('status', 20)->default('draft'); // draft|open|closed|cancelled

            // A STORED rate, written when the campaign closes, not an accessor.
            // A response rate recomputed on read reprints differently every
            // time a process is added to the catalogue, and a board pack that
            // changes retrospectively is worse than no board pack.
            $table->decimal('response_rate', 5, 2)->nullable();

            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('bcms_bia_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('bcms_bia_campaigns')->cascadeOnDelete();
            $table->foreignId('process_id')->constrained('bcms_processes')->cascadeOnDelete();
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft|in_progress|submitted|approved|returned

            // The four numbers the whole module turns on. MTPD is the outer
            // limit; RTO must be inside it, and an RTO greater than the MTPD is
            // a validation failure rather than a warning.
            $table->decimal('mtpd_hours', 8, 2)->nullable();
            $table->decimal('rto_hours', 8, 2)->nullable();
            $table->unsignedInteger('rpo_minutes')->nullable();
            $table->text('mbco_description')->nullable();

            $table->unsignedInteger('min_staff_required')->nullable();
            $table->json('peak_periods')->nullable();
            $table->boolean('workaround_available')->default(false);
            $table->decimal('workaround_max_duration_hours', 8, 2)->nullable();

            // AI first-draft provenance (standing rule 4). An assessment whose
            // numbers a model proposed is marked as such until a human edits or
            // approves it; nothing is submitted to a regulator on the strength
            // of a draft.
            $table->boolean('ai_generated')->default(false);
            $table->timestamp('ai_drafted_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('iso_clause_ref', 60)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'process_id']);
            $table->unique(['campaign_id', 'process_id']);
        });

        Schema::create('bcms_bia_impacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('bcms_bia_assessments')->cascadeOnDelete();
            $table->string('impact_category', 20);
            $table->string('horizon', 10);
            $table->unsignedTinyInteger('severity_score')->nullable();

            // Minor units, as everywhere else in this product. A naira figure
            // stored as a float is a rounding error somebody eventually
            // reconciles by hand.
            $table->bigInteger('financial_amount_minor')->nullable();
            $table->string('currency', 3)->nullable();

            $table->text('narrative')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'impact_category', 'horizon']);
            $table->index(['organization_id', 'assessment_id']);
        });

        Schema::create('bcms_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('bcms_bia_assessments')->cascadeOnDelete();

            // The morph map of ADR 0002. `dependable_type` stores a SHORT KEY
            // — `vendors`, `applications` — never a class name, so a namespace
            // change does not orphan a customer's rows.
            $table->string('dependable_type', 30);
            $table->unsignedBigInteger('dependable_id');

            $table->string('dependency_type', 30)->nullable(); // upstream|downstream|supporting|infrastructure
            $table->string('criticality', 20)->nullable();     // critical|high|medium|low
            $table->boolean('single_point_of_failure')->default(false);
            $table->text('recovery_notes')->nullable();
            $table->boolean('alternative_available')->default(false);
            $table->string('external_ref', 100)->nullable();
            $table->timestamps();

            $table->index(['dependable_type', 'dependable_id'], 'bcms_dependencies_morph_idx');
            $table->index(['organization_id', 'single_point_of_failure']);
            $table->unique(['assessment_id', 'dependable_type', 'dependable_id'], 'bcms_dependencies_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcms_dependencies');
        Schema::dropIfExists('bcms_bia_impacts');
        Schema::dropIfExists('bcms_bia_assessments');
        Schema::dropIfExists('bcms_bia_campaigns');
        Schema::dropIfExists('bcms_processes');
        Schema::dropIfExists('bcms_objectives');
        Schema::dropIfExists('bcms_programmes');
        Schema::dropIfExists('bcms_data_sets');
        Schema::dropIfExists('bcms_equipment');
        Schema::dropIfExists('bcms_applications');
        Schema::dropIfExists('bcms_sites');
    }
};
