<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * ADR 0027 Amendment 3 §A3.1 — Phase 3 column additions to existing tables
     */
    public function up(): void
    {
        Schema::table('sm_scenarios', function (Blueprint $table) {
            $table->boolean('ai_generated')->default(false)->after('created_by');
        });

        Schema::table('sm_scenario_notes', function (Blueprint $table) {
            $table->boolean('is_ai_generated')->default(false)->after('body');
        });

        Schema::table('sm_scenario_signoffs', function (Blueprint $table) {
            $table->dateTime('requested_at')->nullable()->after('warnings_acknowledged');
            $table->foreignId('requested_by')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sm_scenario_signoffs', function (Blueprint $table) {
            $table->dropForeign(['requested_by']);
            $table->dropColumn(['requested_at', 'requested_by']);
        });

        Schema::table('sm_scenario_notes', function (Blueprint $table) {
            $table->dropColumn('is_ai_generated');
        });

        Schema::table('sm_scenarios', function (Blueprint $table) {
            $table->dropColumn('ai_generated');
        });
    }
};
