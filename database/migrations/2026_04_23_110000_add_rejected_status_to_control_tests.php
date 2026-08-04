<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ENUM MODIFY is MySQL-specific; SQLite (used by the test suite) stores
        // enums as CHECK-constrained strings and needs no change here.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE control_tests MODIFY COLUMN status ENUM('scheduled','in_progress','pending_review','completed','rejected','cancelled') NOT NULL DEFAULT 'scheduled'");
        }
    }

    public function down(): void
    {
        // Rollback: coerce any 'rejected' rows to 'in_progress' then drop the enum value.
        DB::table('control_tests')->where('status', 'rejected')->update(['status' => 'in_progress']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE control_tests MODIFY COLUMN status ENUM('scheduled','in_progress','pending_review','completed','cancelled') NOT NULL DEFAULT 'scheduled'");
        }
    }
};
