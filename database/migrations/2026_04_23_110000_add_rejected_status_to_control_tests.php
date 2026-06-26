<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE control_tests MODIFY COLUMN status ENUM('scheduled','in_progress','pending_review','completed','rejected','cancelled') NOT NULL DEFAULT 'scheduled'");
    }

    public function down(): void
    {
        // Rollback: coerce any 'rejected' rows to 'in_progress' then drop the enum value.
        DB::table('control_tests')->where('status', 'rejected')->update(['status' => 'in_progress']);
        DB::statement("ALTER TABLE control_tests MODIFY COLUMN status ENUM('scheduled','in_progress','pending_review','completed','cancelled') NOT NULL DEFAULT 'scheduled'");
    }
};
