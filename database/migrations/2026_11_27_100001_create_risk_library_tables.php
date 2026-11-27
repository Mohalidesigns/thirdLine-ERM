<?php

use App\Support\Graph\ObjectRegistryInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0028 Phase 1 — Risk Library master catalogue & immutable version snapshots.
 *
 * MariaDB 10.4 ∩ MySQL 8.0.46 & ADR 0022 compliant:
 * - No softDeletes() on risk_library_items (items are retired, never deleted).
 * - Zero score columns on risk_library_items (ISO 31000 §6.4.2).
 * - All application-written event timestamps use dateTime(), never bare timestamp().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_library_items', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('title', 255);
            $table->text('description');
            $table->foreignId('category_id')->constrained('risk_categories')->restrictOnDelete();
            $table->string('risk_source', 40)->nullable();
            $table->string('basel_l1_event_type', 40)->nullable();
            $table->string('basel_l2_event_type', 64)->nullable();
            $table->json('typical_causes')->nullable();
            $table->json('typical_consequences')->nullable();
            $table->json('suggested_controls')->nullable();
            $table->json('suggested_kris')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->string('status', 20)->default('active');
            $table->dateTime('retired_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retirement_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'rli_org_code_unique');
            $table->index(['organization_id', 'status'], 'rli_org_status_idx');
            $table->index(['organization_id', 'category_id'], 'rli_org_cat_idx');
            $table->index(['organization_id', 'basel_l1_event_type', 'basel_l2_event_type'], 'rli_org_basel_idx');
        });

        Schema::create('risk_library_item_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('library_item_id')->constrained('risk_library_items')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title', 255);
            $table->text('description');
            $table->foreignId('category_id')->constrained('risk_categories')->restrictOnDelete();
            $table->string('risk_source', 40)->nullable();
            $table->string('basel_l1_event_type', 40)->nullable();
            $table->string('basel_l2_event_type', 64)->nullable();
            $table->json('typical_causes')->nullable();
            $table->json('typical_consequences')->nullable();
            $table->json('suggested_controls')->nullable();
            $table->json('suggested_kris')->nullable();
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');

            $table->unique(['library_item_id', 'version_number'], 'rliv_item_version_unique');
            $table->index(['organization_id', 'library_item_id'], 'rliv_org_item_idx');
        });

        // Register the new RiskLibraryItem reference object type in object_types
        // idempotently when the object graph table exists.
        if (Schema::hasTable('object_types')) {
            (new ObjectRegistryInstaller)->install();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_library_item_versions');
        Schema::dropIfExists('risk_library_items');
    }
};
