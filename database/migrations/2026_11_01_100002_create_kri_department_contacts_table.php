<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0026 section 2 - who answers for a department's KRI return.
 *
 * Many people per role; one person may hold several roles in one department.
 * `role` is a string with an enum cast (App\Enums\KriContactRole), not a DB ENUM,
 * so a future tier is not an ALTER. No AD column is stored: the directory hint is
 * read when the screen is drawn. No soft delete: the audit row keeps the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kri_department_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_unit_id', 'user_id', 'role'], 'kri_dept_contacts_unit_user_role_unique');
            $table->index(['organization_id', 'user_id', 'role'], 'kri_dept_contacts_user_role_idx');
            $table->index(['organization_id', 'business_unit_id', 'role'], 'kri_dept_contacts_unit_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kri_department_contacts');
    }
};
