<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 6 — the call tree's version chain (ADR 0013).
 *
 * ONE COLUMN. An approved tree is never edited; v2 is a new row that points back
 * at v1, exactly as `bcms_plans.supersedes_plan_id` already does for the policy
 * and for every plan. Reconstructing the chain from name and business unit
 * instead would lose four years of test history the first time a department is
 * renamed.
 *
 * `nullOnDelete` and not `cascadeOnDelete`: deleting v1 must not delete the
 * history that replaced it. Breaking the chain loses a link; cascading loses the
 * evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcms_call_trees', function (Blueprint $table) {
            $table->foreignId('supersedes_call_tree_id')->nullable()->after('version')
                ->constrained('bcms_call_trees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bcms_call_trees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_call_tree_id');
        });
    }
};
