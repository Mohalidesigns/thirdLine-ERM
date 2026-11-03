<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0026 section 5 - the monthly request and the manual reminders are rows.
 *
 * The idempotency keys ARE the "at most once" rules:
 *   request:r{kri_return_id}               one request per return, ever;
 *   reminder:bu{business_unit_id}:{Y-m-d}  one manual reminder per department per Lagos day.
 * Recipients are resolved at send time; their rows are the delivery outcomes.
 * `sent` means "accepted by the mail transport", nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kri_return_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kri_return_id')->constrained('kri_returns')->cascadeOnDelete();
            $table->foreignId('business_unit_id')->constrained('business_units')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('sequence');
            $table->unsignedTinyInteger('tier');
            $table->string('idempotency_key', 80);
            $table->date('sent_on');
            $table->string('status', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('queued_at');
            $table->dateTime('recipients_resolved_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'idempotency_key'], 'kri_notices_org_key_unique');
            $table->unique(['kri_return_id', 'kind', 'sequence'], 'kri_notices_return_seq_unique');
            $table->index(['organization_id', 'business_unit_id', 'sent_on'], 'kri_notices_unit_day_idx');
            $table->index(['status', 'queued_at'], 'kri_notices_status_queued_idx');
        });

        Schema::create('kri_return_notice_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kri_return_notice_id')->constrained('kri_return_notices')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email', 255);
            $table->string('name', 200);
            $table->string('contact_role', 20);
            $table->string('status', 16);
            $table->string('skip_reason', 40)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('message_id', 255)->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['kri_return_notice_id', 'user_id'], 'kri_notice_recipients_user_unique');
            $table->index(['organization_id', 'status'], 'kri_notice_recipients_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kri_return_notice_recipients');
        Schema::dropIfExists('kri_return_notices');
    }
};
