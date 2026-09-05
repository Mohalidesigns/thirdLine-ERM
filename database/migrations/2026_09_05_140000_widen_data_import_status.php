<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The import pipeline writes six statuses; the column allowed four.
 *
 * Migration Phase 5.5. `data_imports.status` was
 * `enum('pending','processing','completed','failed')`, and WP-07 — which moved
 * the row loop out of the request and onto a queue — introduced two more:
 *
 *   - `queued`, written by DataImportController::processImport() the moment a
 *     user submits their column mapping;
 *   - `cancelled`, written by the job when a run is stopped.
 *
 * Neither was added to the column. MySQL in strict mode answers the write with
 * "Data truncated for column 'status'" and SQLite with a CHECK violation, so
 * **`processImport()` has thrown on every call since**: mapping the columns of
 * an uploaded spreadsheet and pressing the button ended in a 500, and no
 * import has ever been processed through the interface.
 *
 * Found by writing the first test of that route. The controller, the job and
 * the processor were all correct; only the column disagreed with them.
 *
 * WHY A STRING RATHER THAN A WIDER ENUM. The vocabulary now lives on
 * `DataImport::STATUSES`, which is what the screens, the job and any future
 * validator read — the same shape 5.3 gave `RegulatoryDeadline::STATUSES`. A
 * database enum is a second copy of that list that only one of the two drivers
 * this codebase runs on can be altered in place, and keeping the two in step
 * is exactly what failed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_imports', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Anything in a state the old column cannot hold goes back to the
        // nearest one it can, so the rollback does not fail on its own data.
        DB::table('data_imports')->where('status', 'queued')->update(['status' => 'pending']);
        DB::table('data_imports')->where('status', 'cancelled')->update(['status' => 'failed']);

        Schema::table('data_imports', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending')->change();
        });
    }
};
