<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['risks', 'controls', 'issues', 'loss_events', 'key_risk_indicators'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'entity_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('entity_id')->nullable()->after('organization_id')->constrained('entities')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = ['risks', 'controls', 'issues', 'loss_events', 'key_risk_indicators'];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'entity_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('entity_id');
                });
            }
        }
    }
};
