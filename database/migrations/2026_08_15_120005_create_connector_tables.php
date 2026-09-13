<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-07 TASK 4 — connectors: scheduled pulls into the measure engine.
 *
 * THE COLUMNS THIS EXISTS TO BRING TO LIFE. key_risk_indicators.is_automated
 * and automation_config have been in the schema since the KRI module shipped
 * and nothing has ever read them. An "automated" KRI was a checkbox that
 * changed nothing: somebody still typed the number in every month, and the
 * screen said the platform was collecting it.
 *
 * CREDENTIALS ARE ENCRYPTED, and separated from config so that showing a
 * connector's settings on screen does not require decrypting its password.
 *
 * EVERY RUN IS RECORDED with what it read, what it wrote and what it could not
 * parse. A sync that silently imports nothing looks exactly like a sync with
 * nothing to import, and the difference is a KRI that has quietly stopped being
 * measured — which nobody notices until the breach that was never flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('name');
            $table->text('description')->nullable();

            $table->json('config')->nullable();

            // Encrypted, and apart from config: a connector's settings are
            // shown on screen and its credentials are not.
            $table->text('credentials')->nullable();

            // How the source's columns map onto the platform's fields.
            $table->json('field_map')->nullable();

            $table->string('schedule', 40)->nullable();
            $table->boolean('is_active')->default(true);

            $table->dateTime('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active'], 'connectors_org_active_index');
            $table->index(['schedule', 'is_active'], 'connectors_schedule_index');
        });

        Schema::create('connector_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->constrained()->cascadeOnDelete();

            $table->string('trigger', 20)->default('schedule');
            $table->boolean('dry_run')->default(false);
            $table->string('status', 20)->default('running');

            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->unsignedInteger('records_read')->default(0);
            $table->unsignedInteger('records_written')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);

            $table->json('errors')->nullable();

            // What a dry run WOULD have done, which is the whole point of one:
            // a reconciliation an operator reads before letting it write.
            $table->json('reconciliation')->nullable();

            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('job_run_id')->nullable()->constrained('job_runs')->nullOnDelete();
            $table->timestamps();

            $table->index(['connector_id', 'created_at'], 'connector_runs_connector_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_runs');
        Schema::dropIfExists('connectors');
    }
};
