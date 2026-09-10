<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 10 — scheduled reports (FR-RPT-09).
 *
 * "Schedulable by email on a cron with a recipient list" is three facts about
 * a report: how often, to whom, and whether the last run worked. The third is
 * the one that decides whether the feature is trustworthy — a schedule with no
 * record of its last outcome is a schedule everybody assumes is running, and
 * the alerting requirement in TRD §14 exists because a silently failing
 * report is worse than no report.
 *
 * RECIPIENTS ARE FREE-TEXT EMAILS, NOT USER IDS, AND THAT IS DELIBERATE. The
 * people who receive a contract expiry calendar are frequently a procurement
 * mailbox, an outsourced company secretary or a regulator-facing distribution
 * list — none of which is a user of this system. Keying on `users` would
 * quietly restrict the feature to internal staff, which is not what a
 * distribution list is for. Every send is still authorised against the
 * SCHEDULE OWNER's permissions, not the recipients'.
 *
 * NO `last_run_status` DEFAULT OF SUCCESS. A schedule that has never run
 * carries null, and the screen prints "Never run" rather than a green tick it
 * has not earned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The registry key: 'assessment-status', 'screening-log', …
            $table->string('report_key', 60);
            $table->string('name', 120);

            $table->string('frequency', 20);
            // 1-7 for weekly (ISO: Monday = 1), 1-28 for monthly. Capped at 28
            // so a monthly schedule cannot silently skip February.
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('send_at', 5)->default('07:00');

            $table->string('format', 10)->default('xlsx');

            $table->json('recipients');

            // Whose permissions the render is authorised against. A schedule
            // outlives the person who made it, so this is nullOnDelete and the
            // dispatcher refuses to send when it is gone rather than falling
            // back to nobody's permissions, which would be everybody's.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->dateTime('last_run_at')->nullable();
            $table->string('last_run_status', 20)->nullable();
            $table->text('last_run_message')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'report_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_report_schedules');
    }
};
