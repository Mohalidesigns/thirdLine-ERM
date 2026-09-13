<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-13 — a dashboard now has two layouts, not one.
 *
 * `dashboards.tabs` was both the thing the builder wrote on every keystroke
 * and the thing Business HQ rendered. DashboardBuilder::persist() runs on
 * every add, move, resize, rename and tab change, so dragging a widget on a
 * PUBLISHED dashboard changed what every user of that dashboard saw, live,
 * with no way back and nothing on screen to say it had happened. "Publish"
 * meant nothing except flipping a boolean, and the version bump — which
 * exists to retire stale user layout overrides (see DashboardUserPref) — fired
 * on a republish that might contain no change at all.
 *
 * The split is the fix and it is the whole of it:
 *
 *   tabs            the working draft. The builder owns it and writes it
 *                   constantly. Nobody outside the builder ever renders it,
 *                   except deliberately through ?preview= on Business HQ.
 *   published_tabs  what Business HQ renders. Written only by publish().
 *
 * "Unpublished changes" then falls out of comparing the two, and an admin can
 * rework a live dashboard for a week without the board seeing half of it.
 *
 * BACKFILL. Every currently-published dashboard copies tabs → published_tabs,
 * so this migration changes nothing anyone can see: what was on screen before
 * it ran is on screen after. Rows that predate it and somehow reach the
 * renderer with a NULL published_tabs fall back to `tabs` in
 * Dashboard::publishedTabList(), so a half-applied deploy degrades to the old
 * behaviour rather than to a blank page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        Schema::table('dashboards', function (Blueprint $table) {
            // Nullable: a draft has never been published and has no live
            // layout. NULL is the honest representation of that, and the
            // renderer treats it as "nothing to show" rather than "{}".
            $table->json('published_tabs')->nullable()->after('tabs');
            $table->timestamp('published_at')->nullable()->after('is_published');
        });

        DB::table('dashboards')
            ->where('is_published', true)
            ->update([
                'published_tabs' => DB::raw('tabs'),
                'published_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashboards')) {
            return;
        }

        // Dropping published_tabs discards any draft/live divergence: whatever
        // the builder last autosaved becomes live again the moment the column
        // is gone. That is the old behaviour, which is what down() means here,
        // but it is a data-losing direction and worth knowing before running.
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropColumn(['published_tabs', 'published_at']);
        });
    }
};
