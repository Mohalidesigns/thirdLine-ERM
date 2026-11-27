<?php

use App\Support\Graph\ObjectRegistryInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0028 Phase 2 — Process Groups, business_processes additions, and
 * entity_business_process M:N pivot table.
 *
 * MariaDB 10.4 ∩ MySQL 8.0.46 & ADR 0022 compliant:
 * - Does NOT drop or alter any existing business_processes columns.
 * - Does NOT touch bcms_processes (BCMS overlay per ADR 0001).
 * - All application-written event times use dateTime(), never bare timestamp().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_groups', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained('business_units')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('apqc_code', 32)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code'], 'pg_org_code_unique');
            $table->index(['organization_id', 'business_unit_id'], 'pg_org_bu_idx');
            $table->index(['organization_id', 'status'], 'pg_org_status_idx');
        });

        Schema::table('business_processes', function (Blueprint $table) {
            $table->foreignId('process_group_id')
                ->nullable()
                ->after('business_unit_id')
                ->constrained('process_groups')
                ->nullOnDelete();
            $table->string('apqc_code', 32)
                ->nullable()
                ->after('code');

            $table->index(['organization_id', 'process_group_id'], 'bp_org_group_idx');
            $table->index(['organization_id', 'code'], 'bp_org_code_idx');
        });

        Schema::create('entity_business_process', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('business_process_id')->constrained('business_processes')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('attached_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['organization_id', 'entity_id', 'business_process_id'], 'ebp_org_entity_process_unique');
            $table->index(['organization_id', 'entity_id'], 'ebp_org_entity_idx');
            $table->index(['organization_id', 'business_process_id'], 'ebp_org_process_idx');
        });

        // Register the new ProcessGroup org-node object type in object_types
        // idempotently when the object graph table exists.
        if (Schema::hasTable('object_types')) {
            (new ObjectRegistryInstaller)->install();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_business_process');

        // `up()` added two organization_id-led indexes, and InnoDB then dropped the
        // implicit index it had created for `business_processes_organization_id_foreign`,
        // because either new index could serve the constraint. So once the first is
        // dropped, `bp_org_code_idx` is the ONLY index backing that FK, and dropping it
        // fails with 1553 on MariaDB 10.4 and MySQL 8 alike — half-way through a
        // rollback. Put the plain index back first, under the name the engine originally
        // gave it, so the table returns to its pre-migration shape.
        if (! Schema::hasIndex('business_processes', 'business_processes_organization_id_foreign')) {
            Schema::table('business_processes', function (Blueprint $table) {
                $table->index('organization_id', 'business_processes_organization_id_foreign');
            });
        }

        Schema::table('business_processes', function (Blueprint $table) {
            $table->dropIndex('bp_org_group_idx');
            $table->dropIndex('bp_org_code_idx');
            $table->dropConstrainedForeignId('process_group_id');
            $table->dropColumn('apqc_code');
        });

        Schema::dropIfExists('process_groups');
    }
};
