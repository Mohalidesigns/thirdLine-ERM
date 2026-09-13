<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-03 TASK 1 — configurable state machines.
 *
 * Today each domain's states live in controller conditionals and a varchar
 * column with no constraint, which is why loss_events carries both
 * INVESTIGATING and UNDER_INVESTIGATION: nothing ever refused the second one.
 * A lifecycle row declares the states, the legal transitions between them and
 * the permission each transition needs, so the same rule is enforced by the UI,
 * the API and the importer.
 *
 * The seeded lifecycles use the state codes ALREADY IN THE DATA rather than an
 * idealised set. A lifecycle that disagrees with its own table cannot validate
 * anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_lifecycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('object_type_id')->constrained('object_types')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 120);
            // [{code,name,color,is_initial,is_terminal,allowed_transitions[],
            //   required_permission,required_workflow_id}]
            $table->json('states');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'object_type_id', 'code'], 'object_lifecycles_scope_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_lifecycles');
    }
};
