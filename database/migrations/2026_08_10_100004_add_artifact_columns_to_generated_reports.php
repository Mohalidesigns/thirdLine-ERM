<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * generated_reports becomes a record of an actual artifact rather than a note
 * that someone once pressed a button.
 *
 * As shipped, the table stored a file_name and a download_route — re-running
 * the route regenerated the report from scratch, so "downloading" a report from
 * the Recent list produced a *different* document whenever the underlying data
 * had moved. For a board pack or a regulatory return that is a serious defect:
 * the pack the board approved has to stay the pack the board approved.
 *
 * These columns let a queued job write the bytes once, to a disk, and let every
 * later download serve that same file. Additive only: file_name and
 * download_route are untouched and the old re-run path keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_reports', function (Blueprint $table) {
            // Lifecycle. Existing rows were all produced synchronously and are
            // therefore complete — hence the default.
            $table->string('status', 20)->default('completed')->after('period');
            $table->unsignedTinyInteger('progress_pct')->default(100)->after('status');
            $table->text('error_message')->nullable()->after('progress_pct');
            $table->timestamp('started_at')->nullable()->after('error_message');
            $table->timestamp('completed_at')->nullable()->after('started_at');

            // The stored artifact.
            $table->string('format', 10)->nullable()->after('file_name');
            $table->string('disk', 30)->nullable()->after('format');
            $table->string('file_path', 500)->nullable()->after('disk');
            $table->string('mime_type', 120)->nullable()->after('file_path');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');

            // Board packs are versioned per organization and report type, so a
            // superseded pack stays retrievable next to the one that replaced
            // it.
            $table->unsignedInteger('version')->default(1)->after('size_bytes');

            // The as-at date the content describes, which is not the same thing
            // as when it was generated. A pack run on 3 April for the March
            // position must say March.
            $table->date('period_as_at')->nullable()->after('period');

            $table->index(['organization_id', 'report_type', 'version']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('generated_reports', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'report_type', 'version']);
            $table->dropIndex(['organization_id', 'status']);

            $table->dropColumn([
                'status', 'progress_pct', 'error_message', 'started_at', 'completed_at',
                'format', 'disk', 'file_path', 'mime_type', 'size_bytes', 'version',
                'period_as_at',
            ]);
        });
    }
};
