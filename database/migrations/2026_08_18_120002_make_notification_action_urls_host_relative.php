<?php

use App\Services\NotificationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strip the host from action URLs already in `notifications_log`.
 *
 * `NotificationService` now stores a root-relative path, because a notification
 * row outlives the host that created it. The rows written before that rule
 * carry whatever origin was in scope when they were raised — `APP_URL` for
 * anything raised by a queue worker or a scheduled command, the serve port for
 * anything raised under `artisan serve`. A live database held both
 * `http://localhost/risk/my-tasks` and `http://127.0.0.1:8000/risk/my-tasks`,
 * and neither resolved for a user on the actual dev port: clicking the bell
 * produced a 404 from whatever else was listening on port 80.
 *
 * Fixing the writer does nothing for those rows — they are already stamped —
 * so they are rewritten here. The path is preserved exactly; only scheme, host
 * and port are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications_log') || ! Schema::hasColumn('notifications_log', 'action_url')) {
            return;
        }

        DB::table('notifications_log')
            ->whereNotNull('action_url')
            ->where('action_url', '!=', '')
            // Anything that is not already a plain root-relative path: an
            // absolute URL, or a protocol-relative one.
            ->where(fn ($query) => $query
                ->where('action_url', 'not like', '/%')
                ->orWhere('action_url', 'like', '//%'))
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $path = NotificationService::normaliseActionUrl($row->action_url);

                    DB::table('notifications_log')
                        ->where('id', $row->id)
                        ->update(['action_url' => $path]);
                }
            });
    }

    /**
     * Not reversible, and deliberately so: the original host is not recorded
     * anywhere, and reinstating a guessed one would recreate the broken links
     * this migration exists to remove.
     */
    public function down(): void {}
};
