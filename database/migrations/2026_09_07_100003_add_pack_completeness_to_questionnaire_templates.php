<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 2 — how complete a shipped questionnaire pack is.
 *
 * The same honesty the framework catalogues carry, for the same reason. TRD
 * Appendix B states an approximate size for each pack — CBN Cyber §2.3 Core at
 * ~55 questions, the NDPA processor assessment at ~48 — and the packs shipped
 * in this phase are smaller than that. Without these two columns the module
 * would present a 16-question pack as though it were the whole of CBN §2.3,
 * and a client would file it as evidence of a coverage it does not have.
 *
 * So a pack declares the size Appendix B specifies and whether what ships is
 * the complete set or a starting subset, and the builder shows "16 of ~55"
 * rather than "16". Extending a pack is then a visible piece of work with a
 * number attached, not a silent gap.
 *
 * A TENANT'S OWN TEMPLATE LEAVES BOTH NULL. It has no external specification to
 * be measured against — its size is whatever its author decided — and a null
 * here means exactly that rather than "unknown".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tp_questionnaire_templates', function (Blueprint $table) {
            $table->unsignedSmallInteger('declared_question_count')->nullable()->after('scoring_mode');
            $table->string('catalogue_status', 20)->nullable()->after('declared_question_count');
            $table->text('catalogue_note')->nullable()->after('catalogue_status');
        });
    }

    public function down(): void
    {
        Schema::table('tp_questionnaire_templates', function (Blueprint $table) {
            $table->dropColumn(['declared_question_count', 'catalogue_status', 'catalogue_note']);
        });
    }
};
