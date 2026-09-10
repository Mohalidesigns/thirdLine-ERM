<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCSA v2, P0 — the RCSA Universe (master data).
 *
 * The universe is the governed inventory the plan's §6 describes: Business Unit
 * → Process → Sub-Process → System → Risk → Control, maintained the way the
 * Audit Universe is maintained. It is the half of the workbook that does NOT
 * change when a quarter is assessed — columns A to I and N — and separating it
 * from the assessment is the entire point: opening a cycle populates process,
 * risk and control from APPROVED master data rather than asking every business
 * unit to retype them.
 *
 * THE PLAN ASKS FOR AN `rcsa_processes` TABLE. This migration does not create
 * one, and the deviation is deliberate.
 *
 * `business_processes` already exists and is the product's process inventory:
 * it is in the object graph (ObjectSourceMap, ObjectTypeRegistry, MorphTypes),
 * `risks.business_process_id` points at it, RiskRegisterService reads it, and
 * the CURRENT RCSA screens read it. A second, RCSA-only process master would
 * mean the "Process" column of an RCSA export could name a process the risk
 * register has never heard of, and the two would drift from the first day. So
 * the one thing `business_processes` lacked for this module — a self-reference
 * giving Process → Sub-Process — is added to it here, which is exactly the
 * shape §5.2 asks for, on the table the product already has.
 *
 * What is NOT carried across from §5.2's `rcsa_processes` is `status` and
 * `version`. The publish gate that matters is on the RISK row: "only published
 * universe rows are picked up when a cycle is opened". A separate publish state
 * on the process would be a second gate with no rule behind it, and a process
 * that is live for the risk register but draft for RCSA is not a state anyone
 * asked for. `is_active` already carries the retire case.
 *
 * `rcsa_systems` IS created. The plan says to reuse an existing application or
 * asset register if one is present; there is none. `entities` is the
 * organisational hierarchy (Group → Region → Branch) and `business_units` is
 * the org chart — neither is an application inventory. When one is built,
 * `rcsa_register_risks.system_ids` becomes a foreign key set against it and
 * this table is migrated into it; until then it is a small, owned list rather
 * than free text, so that "which risks touch Finacle" stays a query.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* -------------------------------------------------------------- */
        /*  Sub-process support on the existing process inventory */
        /* -------------------------------------------------------------- */

        Schema::table('business_processes', function (Blueprint $table) {
            // NULL = a top-level process. A row with a parent is a sub-process.
            // One level of nesting is what the workbook's columns C and D
            // express; nothing enforces a depth limit here, but the universe
            // screen only offers two.
            $table->foreignId('parent_id')->nullable()->after('business_unit_id')
                ->constrained('business_processes')->nullOnDelete();
        });

        /* -------------------------------------------------------------- */
        /*  Systems */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_systems', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active']);
        });

        /* -------------------------------------------------------------- */
        /*  The universe row — workbook columns A to I */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_register_risks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();
            $table->foreignId('process_id')->nullable()->constrained('business_processes')->nullOnDelete();
            $table->foreignId('sub_process_id')->nullable()->constrained('business_processes')->nullOnDelete();

            // Column A. Defect D4: the workbook's `Risk No.` is free text with
            // no uniqueness enforcement, so two branches both file an "R1".
            // Generated as {BU_CODE}-R{seq}, editable, unique per business unit
            // — the uniqueness is on (business_unit_id, risk_no), not on the
            // organisation, because two units numbering their own risks from 1
            // is normal and correct.
            $table->string('risk_no', 40);

            $table->text('potential_risk');                      // Column F
            $table->text('risk_driver')->nullable();             // Column G
            $table->string('risk_category', 64)->nullable();     // Column H

            // Column I — free text in the workbook, a tag list here so that
            // "how many risks touch AML" stays answerable. Populated only when
            // risk_category is Others; see RcsaMethodologyTemplate.
            $table->json('secondary_categories')->nullable();

            // Column E. A list of rcsa_systems ids. JSON rather than a pivot
            // because it is a small unordered set read as a whole, never joined
            // against — the same call ObjectRelationship makes for its own
            // multi-valued attributes. Revisit if a report needs to group by it.
            $table->json('system_ids')->nullable();

            // Optional starting ratings the assessor may accept or change. They
            // are NOT an assessment: nothing scores or reports off them, and a
            // cycle copies them into the line as a default only.
            $table->unsignedTinyInteger('default_likelihood')->nullable();
            $table->unsignedTinyInteger('default_impact')->nullable();

            // draft: being written. published: eligible for a cycle.
            // retired: no longer assessed, history preserved.
            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // The import batch that created the row, so a bad upload can be
            // traced back and, if need be, reversed.
            $table->foreignId('source_batch_id')->nullable();

            // sha256(lower(trim(bu + process + sub_process + potential_risk))).
            // The duplicate key for imports, both within a file and against
            // what is already published. Indexed, not unique: a legitimate
            // re-statement of the same risk in a different unit must be
            // possible, and the import PREVIEW is where a duplicate is decided,
            // not a constraint that aborts a 1,000-row upload on row 4.
            $table->string('row_hash', 64)->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_unit_id', 'risk_no']);
            $table->index(['organization_id', 'status']);
            // Named explicitly: the generated name would be 65 characters and
            // MySQL's identifier limit is 64. SQLite, which the suite runs on,
            // has no such limit, so this fails only in production.
            $table->index(['organization_id', 'business_unit_id', 'status'], 'rcsa_register_risks_org_bu_status_index');
            $table->index(['organization_id', 'risk_category']);
            $table->index('row_hash');
        });

        /* -------------------------------------------------------------- */
        /*  Controls — workbook column N */
        /* -------------------------------------------------------------- */

        Schema::create('rcsa_register_controls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_risk_id')->constrained('rcsa_register_risks')->cascadeOnDelete();

            // The control library row this is an instance of, when the control
            // is one the library already knows. NULL for a control captured
            // during an RCSA that has not been taken into the library yet —
            // which is most of them on day one, and is why this is nullable.
            $table->foreignId('control_library_id')->nullable()->constrained('controls')->nullOnDelete();

            $table->text('description');
            $table->enum('control_type', ['preventive', 'detective', 'corrective', 'directive'])->nullable();

            // Matches Control::FREQUENCIES so a control promoted into the
            // library carries its frequency across without translation.
            $table->string('frequency', 32)->nullable();

            $table->foreignId('control_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_key')->default(false);
            $table->enum('status', ['draft', 'published', 'retired'])->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'register_risk_id']);
            $table->index('control_library_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcsa_register_controls');
        Schema::dropIfExists('rcsa_register_risks');
        Schema::dropIfExists('rcsa_systems');

        Schema::table('business_processes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
