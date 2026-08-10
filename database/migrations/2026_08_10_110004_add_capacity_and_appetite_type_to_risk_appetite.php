<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-01 TASK 2 — RiskAppetiteService read five columns that do not exist on
 * risk_appetite: tolerance_upper, capacity, appetite_type, tolerance_lower and
 * notes.
 *
 * Three of those had a real column behind the null-coalescing fallback, so
 * they resolved correctly by accident:
 *   tolerance_upper -> max_tolerance
 *   tolerance_lower -> target_min / target_max
 *   notes           -> appetite_statement
 *
 * Two did not, and silently returned a constant on every screen:
 *   capacity        -> always 25
 *   appetite_type   -> always 'Not Specified'
 *
 * `capacity` and `appetite_type` are genuinely part of the appetite model —
 * capacity is the absolute maximum exposure the organisation could absorb, as
 * distinct from the tolerance it is willing to run, and the WP-10 appetite
 * rewrite needs both — so they are added rather than removed from the service.
 *
 * capacity is deliberately left NULL for existing rows. Seeding it with a
 * number nobody chose would put a fabricated capacity boundary in front of
 * every tenant; the service treats NULL as "no capacity recorded" and simply
 * does not report a capacity breach until someone sets one.
 *
 * Additive: two nullable columns, nothing dropped or retyped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risk_appetite', function (Blueprint $table) {
            if (! Schema::hasColumn('risk_appetite', 'capacity')) {
                $table->decimal('capacity', 18, 4)->nullable()->after('max_tolerance');
            }

            if (! Schema::hasColumn('risk_appetite', 'appetite_type')) {
                // quantitative | qualitative | hybrid — how the appetite is
                // expressed, as opposed to appetite_level, which is the stance
                // (averse / cautious / open / hungry).
                $table->string('appetite_type', 30)->nullable()->after('appetite_level');
            }
        });
    }

    public function down(): void
    {
        Schema::table('risk_appetite', function (Blueprint $table) {
            foreach (['capacity', 'appetite_type'] as $column) {
                if (Schema::hasColumn('risk_appetite', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
