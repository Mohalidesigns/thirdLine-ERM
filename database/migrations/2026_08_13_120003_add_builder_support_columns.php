<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-05 TASKS 1 and 2 — the three columns the builder and the dynamic renderer
 * need. All additive, all nullable, nothing dropped.
 *
 * object_attributes.maps_to_column
 *   Without this, <x-dynamic-form> can only write objects.attributes, so it can
 *   decorate a hand-written form but never replace one — the acceptance
 *   criterion asks for five replaced create/edit pairs, and a renderer that
 *   cannot reach control.name cannot produce a control form. An attribute with
 *   a mapped column reads and writes that column on the domain model; one
 *   without it falls back to the JSON bag exactly as before. Existing rows are
 *   NULL and therefore behave identically.
 *
 * object_attributes.visible_when
 *   Conditional visibility, as {"field":"has_regulatory_impact","equals":true}.
 *   It was previously smuggled into the `validation` blob alongside the role
 *   gate, which meant a display rule and a security rule shared one column and
 *   one validation path. They are different things: getting a display rule
 *   wrong makes a form confusing, getting a role gate wrong leaks data.
 *
 * object_relationships.archived_at
 *   The guardrail for deleting a relationship type that has instances. The type
 *   is restrictOnDelete from the edge table, so the choice was previously
 *   between refusing the delete and cascading real graph edges into oblivion.
 *   Archiving keeps the edges — and therefore the history of what once
 *   mitigated what — while removing them from every live traversal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('object_attributes', function (Blueprint $table) {
            // The domain column this attribute reads and writes, e.g. 'name'
            // on Control. NULL means the value lives in objects.attributes.
            $table->string('maps_to_column', 64)->nullable()->after('code');
            $table->json('visible_when')->nullable()->after('validation');
            // Layout hints for the renderer. 'full' spans both columns of the
            // form grid; a mobile variant lets a field be dropped from the
            // small-screen layout without being dropped from the record.
            $table->string('width', 16)->default('half')->after('sort_order');
            $table->boolean('show_on_mobile')->default(true)->after('width');
            $table->boolean('show_in_detail')->default(true)->after('show_on_mobile');
        });

        Schema::table('object_relationships', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('effective_to');
            $table->index('archived_at');
        });

        Schema::table('object_types', function (Blueprint $table) {
            // The model class whose create/edit pair this type renders, when
            // one exists. ObjectTypeRegistry::modelTypeMap() already holds this
            // mapping in code; storing it lets a TENANT-DEFINED type declare
            // that it is backed by a table, which code in a static map cannot.
            $table->string('backing_model', 160)->nullable()->after('code_prefix');
            $table->text('description')->nullable()->after('plural_name');
        });
    }

    public function down(): void
    {
        Schema::table('object_types', function (Blueprint $table) {
            $table->dropColumn(['backing_model', 'description']);
        });

        Schema::table('object_relationships', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });

        Schema::table('object_attributes', function (Blueprint $table) {
            $table->dropColumn([
                'maps_to_column', 'visible_when', 'width', 'show_on_mobile', 'show_in_detail',
            ]);
        });
    }
};
