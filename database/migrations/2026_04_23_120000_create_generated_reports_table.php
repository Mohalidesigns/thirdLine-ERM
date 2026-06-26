<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 255);
            $table->string('report_type', 60);          // e.g. cbn_orms, basel, nfiu_str, loss_event_management, loss_event_trends, loss_events_full_export
            $table->string('scope', 60)->default('loss_events'); // which module produced it
            $table->string('period', 100)->nullable();  // Q1 2026, Jan–Mar 2026, Last 6 Months, YTD, etc.
            $table->string('file_name', 255)->nullable();
            $table->string('download_route', 120)->nullable(); // route name — re-run to download again
            $table->json('parameters')->nullable();     // the filters the user chose
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'scope', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
    }
};
