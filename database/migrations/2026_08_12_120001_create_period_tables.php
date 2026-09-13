<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-04 TASK 1 — time as a first-class dimension.
 *
 * Everything the platform reports is currently "now": a risk score is whatever
 * the last approved assessment wrote onto `risks`, a KRI reading is whatever
 * row happens to have the latest measurement_date. There is no way to ask what
 * the register looked like at the end of Q1, which is the question a board pack
 * and a regulator both actually ask.
 *
 * A period is the unit of "as at". It is calendar-derived rather than free
 * text so that ‹ › navigation, parent roll-up (month -> quarter -> year) and
 * period close all have something structural to work against.
 *
 * CLOSE is the reason periods carry state. Once a period is closed its
 * measure_values are locked and a late correction has to be an explicit,
 * audited reopen rather than a silent overwrite — which is the difference
 * between a number that reconciles and one that merely looks right today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 200);

            // 1 = January. Nigerian banks report on a January-December financial
            // year (CBN prudential returns assume it), so that is the default;
            // subsidiaries of foreign parents frequently do not, hence a column
            // rather than a constant.
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_default']);
        });

        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('calendar_id')->constrained('period_calendars')->cascadeOnDelete();
            $table->string('code', 64);
            $table->enum('type', ['day', 'week', 'month', 'quarter', 'half', 'year', 'custom']);
            $table->string('name', 200);
            $table->date('start_date');
            $table->date('end_date');

            // month -> quarter -> half -> year. Roll-up walks this, so a measure
            // aggregated at quarter level does not have to re-derive which
            // months it contains from the dates.
            $table->foreignId('parent_period_id')->nullable()->constrained('periods')->nullOnDelete();

            $table->boolean('is_closed')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['calendar_id', 'code']);
            $table->index(['organization_id', 'start_date', 'end_date']);
            // resolve(date) filters by type then brackets the date; without the
            // type in the index every lookup scans all six granularities.
            $table->index(['organization_id', 'type', 'start_date']);
        });
    }

    public function down(): void
    {
        // periods.parent_period_id is self-referential; SQLite will not drop a
        // table that is still the target of its own foreign key on some
        // builds, so the constraint goes first.
        Schema::table('periods', function (Blueprint $table) {
            $table->dropForeign(['parent_period_id']);
        });

        Schema::dropIfExists('periods');
        Schema::dropIfExists('period_calendars');
    }
};
