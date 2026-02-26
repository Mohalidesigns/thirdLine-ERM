<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make original NOT NULL columns nullable so the alignment columns
     * can be used as the primary fields without DB errors.
     */
    public function up(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->string('strategy', 20)->nullable()->change();
            $table->string('action_title', 200)->nullable()->change();
            $table->text('action_description')->nullable()->change();
            $table->unsignedBigInteger('owner_id')->nullable()->change();
            $table->date('target_date')->nullable()->change();
        });

        Schema::table('key_risk_indicators', function (Blueprint $table) {
            $table->string('name', 200)->nullable()->change();
            $table->text('description')->nullable()->change();
            $table->text('metric_formula')->nullable()->change();
            $table->string('data_source', 200)->nullable()->change();
            $table->string('unit_of_measure', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table) {
            $table->string('strategy', 20)->nullable(false)->change();
            $table->string('action_title', 200)->nullable(false)->change();
            $table->text('action_description')->nullable(false)->change();
            $table->unsignedBigInteger('owner_id')->nullable(false)->change();
            $table->date('target_date')->nullable(false)->change();
        });

        Schema::table('key_risk_indicators', function (Blueprint $table) {
            $table->string('name', 200)->nullable(false)->change();
            $table->text('description')->nullable(false)->change();
            $table->text('metric_formula')->nullable(false)->change();
            $table->string('data_source', 200)->nullable(false)->change();
            $table->string('unit_of_measure', 50)->nullable(false)->change();
        });
    }
};
