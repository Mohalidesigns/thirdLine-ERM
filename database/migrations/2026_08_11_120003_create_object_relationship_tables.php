<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-03 TASK 1 — typed edges.
 *
 * The platform has six pivot tables today, each with its own columns, its own
 * semantics and its own controller code. They all express the same idea: this
 * object stands in a named relation to that object. One edge table with a typed
 * relationship makes the graph traversable — "what mitigates this risk, and
 * what does the thing that mitigates it depend on" is one recursive query
 * instead of a hand-written join per hop.
 *
 * weight carries the roll-up arithmetic (a control that covers 40% of a risk,
 * a subsidiary that contributes 30% of group exposure), which is what makes
 * graph-derived roll-up possible at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_relationship_types', function (Blueprint $table) {
            $table->id();
            // NULL = system relationship type, as with object_types.
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 120);
            // The name of the edge read backwards: 'mitigates' <-> 'mitigated_by'.
            // Stored rather than derived so traversal in either direction reads
            // as the domain phrase a user would recognise.
            $table->string('inverse_code', 64)->nullable();
            // Empty array = any type. Validated on write, not by the schema,
            // because the constraint is "one of these ids" and no SQL dialect
            // expresses that against a JSON column portably.
            $table->json('from_type_ids')->nullable();
            $table->json('to_type_ids')->nullable();
            $table->enum('cardinality', ['one_to_one', 'one_to_many', 'many_to_many'])->default('many_to_many');
            $table->boolean('has_weight')->default(false);
            $table->json('attribute_schema')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('object_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('relationship_type_id')->constrained('object_relationship_types')->restrictOnDelete();
            $table->foreignId('from_object_id')->constrained('objects')->cascadeOnDelete();
            $table->foreignId('to_object_id')->constrained('objects')->cascadeOnDelete();
            $table->decimal('weight', 8, 4)->default(1);
            $table->json('attributes')->nullable();
            // Period-aware edges: a control mitigated a risk for FY2025 and was
            // replaced in FY2026. A roll-up as at a date must see the edge that
            // was live then, not the one that is live now.
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['relationship_type_id', 'from_object_id', 'to_object_id'], 'object_relationships_edge_unique');
            $table->index('from_object_id');
            $table->index('to_object_id');
            $table->index(['organization_id', 'relationship_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_relationships');
        Schema::dropIfExists('object_relationship_types');
    }
};
