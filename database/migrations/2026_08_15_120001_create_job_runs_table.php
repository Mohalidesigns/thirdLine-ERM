<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-07 TASK 1 — somewhere for a background job to say how it is getting on.
 *
 * Moving work onto a queue without this makes the product worse, not better.
 * A Monte Carlo run that used to block the request for ninety seconds at least
 * told the user something was happening; the same run dispatched to a worker
 * with no progress record is a button that appears to do nothing, and the user
 * presses it again. Four times.
 *
 * So every long job gets a row here before it is dispatched, updates it as it
 * goes, and finishes it — including when it fails, which is the case the
 * "spinner until it works" pattern always forgets.
 *
 * CANCELLATION IS A FLAG, NOT A SIGNAL. A worker cannot be interrupted from
 * outside; it can only be asked. `cancel_requested_at` is that request, and the
 * job checks it at a point where stopping leaves nothing half-written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            // Nullable: a system job (an FX fetch, a cross-tenant sweep) has no
            // organization, and forcing one would mean picking a tenant at
            // random to own work that belongs to none of them.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('job_class');
            $table->string('label');
            $table->string('queue', 50)->nullable();

            // What the job is about, so a screen can show "your board pack" and
            // not "job 41".
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('total')->nullable();
            $table->string('message', 500)->nullable();

            $table->dateTime('queued_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('cancel_requested_at')->nullable();
            $table->foreignId('cancel_requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('error')->nullable();
            $table->json('result')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at'], 'job_runs_org_status_index');
            $table->index(['subject_type', 'subject_id'], 'job_runs_subject_index');
            $table->index(['created_by', 'status'], 'job_runs_owner_index');
        });

        // The quantification screens read simulation_runs, not job_runs, so the
        // simulation keeps its own progress in the table that already models it
        // — the same "keep the per-module columns updated" rule WP-06 applied to
        // approvals. simulation_runs already has status, error_message,
        // started_at, completed_at and runtime_seconds; these are what it was
        // missing to be cancellable and observable.
        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
            $table->unsignedBigInteger('completed_iterations')->default(0)->after('progress');
            $table->dateTime('cancel_requested_at')->nullable()->after('completed_at');
            $table->foreignId('job_run_id')->nullable()->after('initiated_by')
                ->constrained('job_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('simulation_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_run_id');
            $table->dropColumn(['progress', 'completed_iterations', 'cancel_requested_at']);
        });

        Schema::dropIfExists('job_runs');
    }
};
