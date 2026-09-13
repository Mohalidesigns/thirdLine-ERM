<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-01 TASK 4 — file sizes were signed 32-bit integers, so anything over
 * 2,147,483,647 bytes (~2 GB) overflowed.
 *
 * That is not hypothetical on this platform: control-test evidence and loss
 * event attachments include database extracts and full core-banking exports.
 * On MySQL in strict mode the insert fails outright; without strict mode the
 * value is silently clamped, and the recorded size of a piece of regulatory
 * evidence is then simply wrong.
 *
 * Widening only — every existing value fits, and no data is touched.
 */
return new class extends Migration
{
    /** table => column */
    private const TARGETS = [
        'loss_event_attachments' => 'file_size_bytes',
        'issue_attachments' => 'file_size_bytes',
        'control_test_evidence' => 'file_size',
    ];

    public function up(): void
    {
        $this->change('bigInteger');
    }

    public function down(): void
    {
        // Narrowing back would truncate anything above 2 GB. Rolling the code
        // back does not require the column to shrink, so leave it wide.
    }

    private function change(string $type): void
    {
        foreach (self::TARGETS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($column, $type) {
                $t->{$type}($column)->nullable()->change();
            });
        }
    }
};
