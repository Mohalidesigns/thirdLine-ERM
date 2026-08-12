<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * WP-08 TASK 3 — a composition of widgets: tabs, each a 12-column grid of
 * placements. Always tenant-owned (no $tenantIncludesGlobal): which widgets a
 * bank puts in front of its board is that bank's decision, seeded per tenant
 * the same way workflow definitions are.
 *
 * `tabs` is the whole layout: [{code, label, layout: [{widget_id, x, y, w, h,
 * overrides}]}]. Publishing bumps `version`, which is how a user's
 * layout_override knows it has gone stale (see DashboardUserPref).
 */
class Dashboard extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'object_type_id',
        'role_ids',
        'is_default_for_role',
        'tabs',
        'is_published',
        'version',
    ];

    protected $casts = [
        'role_ids' => 'array',
        'is_default_for_role' => 'boolean',
        'tabs' => 'array',
        'is_published' => 'boolean',
        'version' => 'integer',
    ];

    public function objectType()
    {
        return $this->belongsTo(ObjectType::class, 'object_type_id');
    }

    public function userPrefs()
    {
        return $this->hasMany(DashboardUserPref::class, 'dashboard_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /** Dashboards composed for one of the user's roles, or for every role. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        $roleIds = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $query->where(function (Builder $q) use ($roleIds) {
            $q->whereNull('role_ids');

            foreach ($roleIds as $roleId) {
                $q->orWhereJsonContains('role_ids', $roleId);
            }
        });
    }

    /** @return array<int, array{code: string, label: string, layout: array}> */
    public function tabList(): array
    {
        return collect($this->tabs ?? [])
            ->map(fn (array $tab) => [
                'code' => (string) ($tab['code'] ?? ''),
                'label' => (string) ($tab['label'] ?? $tab['code'] ?? ''),
                'layout' => is_array($tab['layout'] ?? null) ? $tab['layout'] : [],
            ])
            ->filter(fn (array $tab) => $tab['code'] !== '')
            ->values()
            ->all();
    }

    public function tab(string $code): ?array
    {
        foreach ($this->tabList() as $tab) {
            if ($tab['code'] === $code) {
                return $tab;
            }
        }

        return null;
    }

    /** Every widget id placed anywhere on this dashboard, deduplicated. */
    public function placedWidgetIds(): array
    {
        return collect($this->tabList())
            ->flatMap(fn (array $tab) => collect($tab['layout'])->pluck('widget_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
