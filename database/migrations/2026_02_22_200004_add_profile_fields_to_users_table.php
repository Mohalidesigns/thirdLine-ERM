<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->unique()->after('id');
            $table->string('staff_id', 50)->nullable()->after('name');
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('job_title', 100)->nullable()->after('phone');
            $table->string('department', 100)->nullable()->after('job_title');
            $table->foreignId('organization_id')->nullable()->after('department')->constrained()->nullOnDelete();
            $table->foreignId('business_unit_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('business_unit_id');
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['business_unit_id']);
            $table->dropColumn([
                'uuid',
                'staff_id',
                'phone',
                'job_title',
                'department',
                'organization_id',
                'business_unit_id',
                'is_active',
                'deleted_at',
            ]);
        });
    }
};
