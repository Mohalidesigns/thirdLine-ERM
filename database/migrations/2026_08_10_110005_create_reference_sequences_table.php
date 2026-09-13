<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-01 TASK 3 — a real counter for reference codes.
 *
 * ReferenceCodeService used to derive the next number by reading the highest
 * existing code and adding one. That is wrong twice over:
 *
 *   1. Read-then-write with nothing holding the row. Two requests reading at
 *      the same moment both see LE-2026-0041 and both write LE-2026-0042. On a
 *      table with a unique index one of them 500s; on a table without one, two
 *      loss events share a reference that appears in a CBN filing.
 *
 *   2. orderByDesc on a *string* column. Lexicographically 'LE-2026-9999'
 *      sorts above 'LE-2026-10000', so the counter silently walks backwards
 *      the moment an organisation passes 9,999 records in a year and starts
 *      re-issuing codes it has already used.
 *
 * This table replaces both with an incrementing counter held under
 * SELECT ... FOR UPDATE, so the number is allocated by the database rather
 * than guessed at by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // "loss_events.event_reference" — the table and column the codes
            // are issued for. Two columns on the same table get separate
            // counters, which is what you want.
            $table->string('sequence_key', 120);
            $table->string('prefix', 20);

            // Codes are PREFIX-YEAR-NNNN, so the counter resets each year.
            // Stored rather than derived so a back-dated import can allocate
            // from the right year's sequence.
            $table->smallInteger('period');

            $table->unsignedBigInteger('next_value')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);

            $table->timestamps();

            $table->unique(
                ['organization_id', 'sequence_key', 'prefix', 'period'],
                'reference_sequences_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_sequences');
    }
};
