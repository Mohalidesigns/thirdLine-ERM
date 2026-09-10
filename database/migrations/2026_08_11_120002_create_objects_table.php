<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-03 TASK 1 — the graph itself.
 *
 * `objects` is an INDEX over the existing typed tables, not a replacement for
 * them. risks keeps every column it has; a row in `objects` mirrors the handful
 * of fields that are common to everything governed (who owns it, what node it
 * hangs off, what state it is in) so that one query can answer "everything at
 * or below Retail Banking" without a union across nine tables.
 *
 * source_model_type / source_model_id point back at the row of record. The
 * typed table stays the source of truth for its own domain columns; `objects`
 * is authoritative only for the graph — parentage, node ownership, edges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('object_type_id')->constrained('object_types')->restrictOnDelete();

            // The owning org-graph node. For an org node this is the object
            // itself, which keeps "everything owned by this subtree" a single
            // whereIn against one column rather than a special case per type.
            $table->foreignId('node_id')->nullable()->constrained('objects')->nullOnDelete();

            $table->string('code', 64);
            $table->string('name', 500);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delegate_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lifecycle_state', 64)->nullable();
            $table->string('status', 32)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('attributes')->nullable();

            // Materialised path over parent_id, format /1/7/23/ — leading and
            // trailing delimiters included so LIKE '/1/7/%' cannot match 70.
            $table->string('hierarchy_path', 1024)->nullable();
            $table->unsignedSmallInteger('hierarchy_depth')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('objects')->nullOnDelete();
            $table->integer('sort_order')->default(0);

            $table->string('source_model_type', 64)->nullable();
            $table->unsignedBigInteger('source_model_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'object_type_id', 'code']);
            $table->index(['organization_id', 'object_type_id']);
            $table->index('node_id');
            $table->index(['source_model_type', 'source_model_id']);
            $table->index('lifecycle_state');
        });

        // hierarchy_path is VARCHAR(1024); at utf8mb4 a full-column index is
        // 4096 bytes and exceeds InnoDB's 3072-byte key limit, so MySQL gets a
        // 191-character prefix — long enough for ~30 levels of nesting, which
        // is well past any real org chart. Other drivers index the column whole.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('CREATE INDEX objects_hierarchy_path_index ON objects (hierarchy_path(191))');
        } else {
            Schema::table('objects', function (Blueprint $table) {
                $table->index('hierarchy_path');
            });
        }

        Schema::create('object_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('objects')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->string('change_reason', 500)->nullable();
            $table->enum('source', ['ui', 'api', 'import', 'job', 'migration'])->default('ui');

            $table->index(['object_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_versions');
        Schema::dropIfExists('objects');
    }
};
