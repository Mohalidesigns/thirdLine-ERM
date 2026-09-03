<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * WP-08 TASK 3 — a composition of widgets: tabs, each a 12-column grid of
 * placements.
 *
 * WP-12: a dashboard may now be a SYSTEM dashboard (organization_id NULL),
 * visible to every tenant, exactly as WidgetDefinition already worked. The
 * original comment here read "always tenant-owned ... which widgets a bank
 * puts in front of its board is that bank's decision" — true, and still true:
 * a tenant-owned dashboard for the same object type shadows the system one
 * (see DashboardResolver::pick()). What the old rule actually produced was a
 * brand-new tenant with zero dashboards, so every org node in the tree
 * rendered "No dashboard published for Enterprise". A default a bank can
 * override beats no default at all.
 *
 * `tabs` is the whole layout: [{code, label, layout: [{widget_id, x, y, w, h,
 * overrides}]}]. Publishing bumps `version`, which is how a user's
 * layout_override knows it has gone stale (see DashboardUserPref).
 *
 * WP-13: there are now TWO layouts. `tabs` is the DRAFT the builder autosaves
 * into on every drag; `published_tabs` is what Business HQ renders. Before the
 * split they were the same column, so moving a widget on a published dashboard
 * moved it for everyone immediately and "Publish" meant nothing but a boolean.
 * Read the live layout through publishedTabList(), never through tabList().
 */
class Dashboard extends Model
{
    use BelongsToOrganization;

    /** System dashboards (organization_id NULL) are visible to every tenant. */
    protected $tenantIncludesGlobal = true;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'object_type_id',
        'role_ids',
        'is_default_for_role',
        'tabs',
        'published_tabs',
        'is_published',
        'published_at',
        'version',
    ];

    protected $casts = [
        'role_ids' => 'array',
        'is_default_for_role' => 'boolean',
        'tabs' => 'array',
        'published_tabs' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
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

    /**
     * The DRAFT layout — what the builder is working on. Business HQ must not
     * render this; see publishedTabList().
     *
     * @return array<int, array{code: string, label: string, layout: array}>
     */
    public function tabList(): array
    {
        return $this->normaliseTabs($this->tabs ?? []);
    }

    /**
     * Both layout columns are user-authored JSON read on hot paths, so neither
     * is trusted to have the right shape.
     *
     * @return array<int, array{code: string, label: string, layout: array}>
     */
    private function normaliseTabs(mixed $tabs): array
    {
        return collect(is_array($tabs) ? $tabs : [])
            ->filter(fn ($tab) => is_array($tab))
            ->map(fn (array $tab) => [
                'code' => (string) ($tab['code'] ?? ''),
                'label' => (string) ($tab['label'] ?? $tab['code'] ?? ''),
                'layout' => is_array($tab['layout'] ?? null) ? array_values($tab['layout']) : [],
            ])
            ->filter(fn (array $tab) => $tab['code'] !== '')
            ->values()
            ->all();
    }

    /**
     * The LIVE layout — what Business HQ renders.
     *
     * Falls back to the draft when `published_tabs` is NULL. That is the
     * pre-WP-13 shape (one column for both), and a published row still
     * carrying it should keep rendering rather than go blank because the
     * migration has not run yet.
     *
     * @return array<int, array{code: string, label: string, layout: array}>
     */
    public function publishedTabList(): array
    {
        if ($this->published_tabs === null) {
            return $this->tabList();
        }

        return $this->normaliseTabs($this->published_tabs);
    }

    /**
     * Is the draft ahead of what is live?
     *
     * Only meaningful on a published dashboard: an unpublished one is nothing
     * but draft, and calling that "unpublished changes" would put an amber
     * warning on every new dashboard before anyone had done anything wrong.
     */
    public function hasUnpublishedChanges(): bool
    {
        if (! $this->is_published) {
            return false;
        }

        return json_encode($this->tabList()) !== json_encode($this->publishedTabList());
    }

    /**
     * Make the draft live.
     *
     * The version bump is deliberately conditional on the dashboard ALREADY
     * being published. Version exists to retire stale user layout overrides,
     * and there are none to retire the first time a dashboard goes live — the
     * old unconditional bump meant a brand-new dashboard was born at v2.
     */
    public function publish(): void
    {
        $this->forceFill([
            'published_tabs' => $this->tabs,
            'is_published' => true,
            'published_at' => now(),
            'version' => $this->is_published ? $this->version + 1 : $this->version,
        ])->save();
    }

    /**
     * Take it off Business HQ without destroying the live layout.
     *
     * `published_tabs` is left in place so republishing an untouched dashboard
     * restores exactly what was there, and so "what did this look like when it
     * was live?" stays answerable.
     */
    public function unpublish(): void
    {
        $this->forceFill(['is_published' => false])->save();
    }

    /** Widgets on the LIVE layout — what a viewer actually gets. */
    public function publishedWidgetCount(): int
    {
        return collect($this->publishedTabList())->sum(fn (array $tab) => count($tab['layout']));
    }

    /** Widgets on the draft, across every tab. */
    public function draftWidgetCount(): int
    {
        return collect($this->tabList())->sum(fn (array $tab) => count($tab['layout']));
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
