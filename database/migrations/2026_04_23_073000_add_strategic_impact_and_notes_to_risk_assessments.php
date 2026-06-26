<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table) {
            if (!Schema::hasColumn('risk_assessments', 'impact_strategic')) {
                $table->smallInteger('impact_strategic')->nullable()->after('impact_regulatory');
            }
            if (!Schema::hasColumn('risk_assessments', 'assessment_notes')) {
                $table->text('assessment_notes')->nullable()->after('justification');
            }
        });
    }

    public function down(): void
    {
        Schema::table('risk_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('risk_assessments', 'impact_strategic')) {
                $table->dropColumn('impact_strategic');
            }
            if (Schema::hasColumn('risk_assessments', 'assessment_notes')) {
                $table->dropColumn('assessment_notes');
            }
        });
    }
};
