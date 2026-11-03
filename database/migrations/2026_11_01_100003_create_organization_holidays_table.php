<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0026 section 3 - the organisation's holiday list.
 *
 * One row is one Africa/Lagos calendar date. There is no recurrence rule:
 * Nigerian holidays move (Eid), and a rule that guesses is worse than a list
 * somebody maintains. Unprefixed and owned by ERM; BCMS BlackoutPeriod is not read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name', 150);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'holiday_date'], 'org_holidays_org_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_holidays');
    }
};
