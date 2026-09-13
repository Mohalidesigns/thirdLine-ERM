<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-03 TASK 1 — the metadata half of the object graph.
 *
 * object_types is the registry of what kinds of thing exist; object_attributes
 * is the registry of what fields each kind carries beyond the fixed columns on
 * `objects`. Together they are the "configure-don't-code" surface: a tenant
 * adding a bespoke attribute to Risk writes a row here, not a migration.
 *
 * organization_id is NULLABLE on object_types and means "system type, visible
 * to every tenant". A tenant may define its own types alongside them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_types', function (Blueprint $table) {
            $table->id();
            // NULL = system type. Not a foreign key mistake: the whole point is
            // that the seeded registry belongs to no single tenant.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->string('plural_name', 120)->nullable();
            $table->enum('category', ['org_node', 'governance', 'assessment', 'reference']);
            // Type inheritance: Opportunity is a Risk with the sign flipped, so
            // it inherits Risk's attributes rather than duplicating them.
            $table->foreignId('parent_type_id')->nullable()->constrained('object_types')->nullOnDelete();
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedTinyInteger('level_hint')->nullable();
            $table->boolean('is_system')->default(false);
            // The org-graph backbone: only these types may be an objects.node_id.
            $table->boolean('is_node_type')->default(false);
            $table->json('allowed_child_type_ids')->nullable();
            // No FK: object_lifecycles.object_type_id points back here, and a
            // pair of circular constraints cannot be created in either order on
            // SQLite. The relation is enforced in the model, not the schema.
            $table->unsignedBigInteger('default_lifecycle_id')->nullable();
            $table->string('code_prefix', 12)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index('category');
            $table->index('is_node_type');
        });

        Schema::create('object_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_type_id')->constrained('object_types')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('label', 160);
            $table->enum('data_type', [
                'string', 'text', 'int', 'decimal', 'money', 'bool', 'date', 'datetime',
                'enum', 'multi_enum', 'user', 'object_ref', 'json', 'formula',
            ]);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_unique')->default(false);
            $table->text('default_value')->nullable();
            $table->json('validation')->nullable();
            $table->json('enum_options')->nullable();
            $table->foreignId('ref_object_type_id')->nullable()->constrained('object_types')->nullOnDelete();
            $table->text('formula')->nullable();
            $table->string('section', 80)->nullable();
            $table->integer('sort_order')->default(0);
            $table->text('help_text')->nullable();
            // Drives redaction in exports and the NDPR/NDPA data map. An
            // attribute flagged PII is never written to a log line.
            $table->boolean('is_pii')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['object_type_id', 'code']);
            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_attributes');
        Schema::dropIfExists('object_types');
    }
};
