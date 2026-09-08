<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 10 — the four facts a Register of Information names the
 * institution by.
 *
 * DORA RT.01.01 identifies the entity maintaining the register by its LEI,
 * its country, its competent authority and its reporting currency. None of the
 * four can be derived: `organizations` carries a CBN institution code and an
 * RC number, which are Nigerian registrations and not any of these, and a
 * default would be a false statement about a regulated entity.
 *
 * THEY LIVE ON `tp_settings` RATHER THAN ON `organizations` because that is
 * where this module already keeps the per-tenant figures it cannot ship a
 * default for — shareholders' funds, added in Phase 9 for exactly the same
 * reason. Putting a DORA field on the shared organisation table would push a
 * European supervisory concept into a table every other module reads, which is
 * the boundary `OrganizationBranding` was extracted to hold.
 *
 * All four are NULLABLE and the register prints "Not set" for each. A register
 * that silently invented an LEI would be worse than one that admits it has
 * none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tp_settings', function (Blueprint $table) {
            // ISO 17442 is 20 characters.
            $table->string('lei', 20)->nullable()->after('organization_id');
            // ISO 3166-1 alpha-2, the same shape as every other country column
            // in this module.
            $table->string('country', 2)->nullable()->after('lei');
            $table->string('competent_authority', 200)->nullable()->after('country');
            $table->string('reporting_currency', 3)->nullable()->after('competent_authority');
        });
    }

    public function down(): void
    {
        Schema::table('tp_settings', function (Blueprint $table) {
            $table->dropColumn(['lei', 'country', 'competent_authority', 'reporting_currency']);
        });
    }
};
