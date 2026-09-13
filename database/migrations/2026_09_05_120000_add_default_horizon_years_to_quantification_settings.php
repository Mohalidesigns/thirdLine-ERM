<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one column the quantification settings screen was missing.
 *
 * Migration Phase 5.2. That screen offered ten editable fields and stored
 * three of them; `default_time_horizon` was one of the seven it discarded, and
 * it was `required` in the validator, so a preparer had to fill it in on every
 * save and it was thrown away every time.
 *
 * The other two simulation defaults already had somewhere to go —
 * `default_iterations` and `default_confidence_levels` (json) have been on the
 * table since 2026_02_22 — so this is the only column needed to make the
 * defaults real. The rest of the discarded fields configured nothing that
 * exists and are removed from the form rather than given columns; see
 * docs/migration/phase-5-notes/quantification.md.
 *
 * 1 year is the horizon every existing run was created with (the controller's
 * own `$validated['time_horizon'] ?? 1`), so the default restates what the
 * product already does rather than changing anybody's numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quantification_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('default_horizon_years')->default(1)->after('default_confidence_levels');
        });
    }

    public function down(): void
    {
        Schema::table('quantification_settings', function (Blueprint $table) {
            $table->dropColumn('default_horizon_years');
        });
    }
};
