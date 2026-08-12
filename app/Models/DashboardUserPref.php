<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WP-08 TASK 3 — a user's personal rearrangement of a published dashboard.
 *
 * Not tenant-scoped directly: the row is reached only through its dashboard,
 * which is, and a user_id + dashboard_id pair cannot cross organizations
 * because both parents are tenant-bound.
 *
 * layout_override records the dashboard `version` it was made against. When
 * the dashboard has since been republished the override is DISCARDED, not
 * merged: a stale override can hide a widget the publisher deliberately
 * added, and "the board pack panel you added last week is invisible to the
 * CRO who moved a tile in June" is a support ticket nobody can diagnose.
 */
class DashboardUserPref extends Model
{
    protected $fillable = [
        'user_id',
        'dashboard_id',
        'layout_override',
        'saved_filters',
    ];

    protected $casts = [
        'layout_override' => 'array',
        'saved_filters' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function dashboard()
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** The override, only if it was saved against the current version. */
    public function layoutFor(Dashboard $dashboard): ?array
    {
        $override = $this->layout_override;

        if (! is_array($override) || ($override['version'] ?? null) !== $dashboard->version) {
            return null;
        }

        return is_array($override['tabs'] ?? null) ? $override['tabs'] : null;
    }
}
