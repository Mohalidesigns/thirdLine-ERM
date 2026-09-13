<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BCMS Phase 4 — somewhere for the generator to say why (ADR 0012).
 *
 * One column. Phase 0 specified the exercise engine well enough that the
 * calendar, the ladder, `needs_scheduling`, the reschedule counters and the
 * blackout recurrence grammar all landed already; what it did not provide is a
 * place for the generation run's REASONING, which is what acceptance criteria 3
 * and 4 actually ask to be able to read back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcms_exercise_definitions', function (Blueprint $table) {
            // The last run only. A regeneration replaces it, which is the right
            // lifetime: it explains the occurrences that currently exist, and
            // the previous run's decisions were superseded by definition.
            $table->json('generation_log')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bcms_exercise_definitions', function (Blueprint $table) {
            $table->dropColumn('generation_log');
        });
    }
};
