<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCSA v2, P0 — the import staging area and the export log.
 *
 * `rcsa_import_rows` IS A STAGING TABLE AND THAT IS THE WHOLE POINT. The
 * process-flow specification requires the system to "validate uploaded records
 * and flag any errors, duplicates or incomplete information BEFORE the data is
 * published". Nothing an upload contains touches `rcsa_register_risks` until a
 * human has seen the preview and confirmed it; the publish step then runs in
 * one transaction. An importer that writes as it parses cannot offer that, and
 * a bank will not use a bulk upload it cannot inspect first.
 *
 * `rcsa_export_jobs` exists because §10.2 requires bulk download to be
 * restricted AND audit-logged: who exported what, with which filters, from
 * which address, and whether they collected it. A completed RCSA is the
 * bank's operational risk profile in one file, so the export log is a control,
 * not telemetry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rcsa_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // universe: columns A-I plus the control block.
            // assessment: the full 23-column round-trip of §10.4.
            $table->enum('type', ['universe', 'assessment'])->default('universe');

            // Set for an assessment import so the row matcher knows which
            // assessment's lines it is reconciling against.
            $table->foreignId('assessment_id')->nullable()->constrained('rcsa_assessments')->cascadeOnDelete();

            $table->string('file_path', 512);
            $table->string('original_name', 255);
            $table->string('template_version', 20)->nullable();

            $table->enum('status', ['queued', 'parsing', 'validated', 'failed', 'published', 'discarded'])
                ->default('queued');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);

            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->text('failure_reason')->nullable();
            $table->string('error_report_path', 512)->nullable();

            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'user_id']);
        });

        Schema::create('rcsa_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('rcsa_import_batches')->cascadeOnDelete();

            // 1-based row number in the uploaded sheet, INCLUDING the header
            // offset, so that an error message names the row the user sees in
            // Excel rather than an index only the parser understands.
            $table->unsignedInteger('row_number');

            $table->json('raw');                 // exactly what the cell contained
            $table->json('normalised')->nullable(); // after trimming, aliasing, date parsing

            $table->enum('status', ['valid', 'warning', 'error', 'duplicate'])->default('valid');

            // [{field, rule, severity, message}] — one entry per failed rule,
            // not one per row, because a row with four problems should list four.
            $table->json('errors')->nullable();

            // The live row this stages against, once matched.
            $table->unsignedBigInteger('target_id')->nullable();
            $table->enum('action', ['create', 'update', 'skip'])->default('create');

            $table->string('row_hash', 64)->nullable();

            $table->timestamps();

            $table->unique(['batch_id', 'row_number']);
            $table->index(['batch_id', 'status']);
            $table->index('row_hash');
        });

        Schema::create('rcsa_export_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The filters as submitted — cycle, business units, category,
            // levels, appetite status, dates. Kept verbatim so the log answers
            // "what did they take", not merely "they exported something".
            $table->json('filters')->nullable();

            $table->enum('format', ['xlsx', 'csv', 'pdf'])->default('xlsx');
            $table->enum('status', ['queued', 'processing', 'ready', 'failed', 'expired'])->default('queued');

            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_path', 512)->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedSmallInteger('download_count')->default(0);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'user_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rcsa_export_jobs');
        Schema::dropIfExists('rcsa_import_rows');
        Schema::dropIfExists('rcsa_import_batches');
    }
};
