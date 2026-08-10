<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-06 TASK 4 — risk acceptance needs somewhere to land.
 *
 * "Accept" is one of the four ISO 31000 responses and the only one whose whole
 * point is a named person taking the risk on the record. The platform could
 * express the DECISION — treatment_strategy = 'accept' — and nothing about who
 * accepted it, when, on what grounds, or until when. An acceptance with no
 * expiry is how a risk stops being reviewed forever.
 *
 * Additive. Nothing reads these until the acceptance workflow writes them, and
 * an unaccepted risk keeps four nulls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('accepted_by')->nullable()->after('treatment_strategy')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('accepted_by');
            // An acceptance expires; the risk comes back for a fresh decision
            // rather than quietly staying accepted after the conditions that
            // justified it have moved.
            $table->date('acceptance_expires_at')->nullable()->after('accepted_at');
            $table->text('acceptance_rationale')->nullable()->after('acceptance_expires_at');

            $table->index('acceptance_expires_at', 'risks_acceptance_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropIndex('risks_acceptance_expiry_index');
            $table->dropConstrainedForeignId('accepted_by');
            $table->dropColumn(['accepted_at', 'acceptance_expires_at', 'acceptance_rationale']);
        });
    }
};
