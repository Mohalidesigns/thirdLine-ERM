<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('parent_risk_id')->nullable()->after('entity_id')->constrained('risks')->nullOnDelete();
            $table->enum('risk_level', ['granular', 'intermediate', 'enterprise'])->default('granular')->after('parent_risk_id');
            $table->string('hierarchy_path', 500)->nullable()->after('risk_level');
            $table->decimal('roll_up_weight', 5, 2)->default(1.00)->after('hierarchy_path');
            $table->integer('hierarchy_depth')->default(0)->after('roll_up_weight');

            $table->index(['organization_id', 'risk_level']);
            $table->index('parent_risk_id');
            $table->index('hierarchy_path');
        });
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropForeign(['parent_risk_id']);
            $table->dropColumn(['parent_risk_id', 'risk_level', 'hierarchy_path', 'roll_up_weight', 'hierarchy_depth']);
        });
    }
};
