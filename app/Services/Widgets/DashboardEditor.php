<?php

namespace App\Services\Widgets;

use App\Models\Dashboard;
use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Validation\ValidationException;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Everything the dashboard builder does to a dashboard (migration Phase 2:
 * the Livewire DashboardBuilder component's behaviour, as a service the
 * Inertia endpoints call).
 *
 * The draft (`tabs`) is what every method here edits; Business HQ renders
 * `published_tabs`. publish() copies one over the other and bumps `version`
 * on a republish, which is what retires every user's saved layout override
 * (DashboardUserPref). Layout items keep the {widget_id, x, y, w, h,
 * overrides} shape the GridStack glue has always serialised.
 */
class DashboardEditor
{
    public function __construct(private readonly DashboardBinding $binding) {}

    /**
     * The dashboard, asserted editable by this tenant.
     *
     * A SYSTEM dashboard (organization_id NULL) is shared by every tenant and
     * must never be edited in place — one bank's rearrangement would land on
     * every other bank's HQ page. "Save as template" is the way to take a copy.
     */
    public function editable(Dashboard $dashboard): Dashboard
    {
        abort_if($dashboard->organization_id === null, 403, 'System dashboards cannot be edited. Save a copy first.');
        abort_unless((int) $dashboard->organization_id === (int) TenantContext::organizationId(), 403);

        return $dashboard;
    }

    /* ------------------------------------------------------------------ */
    /*  Settings */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array{name?: string, object_type_id?: int|null, role_ids?: list<int>|null, is_default_for_role?: bool}  $attributes
     */
    public function updateSettings(Dashboard $dashboard, array $attributes): void
    {
        $dashboard = $this->editable($dashboard);

        $payload = [];

        if (array_key_exists('name', $attributes)) {
            $payload['name'] = trim((string) $attributes['name']) ?: 'Untitled dashboard';
        }

        if (array_key_exists('object_type_id', $attributes)) {
            $payload['object_type_id'] = $attributes['object_type_id'] === null || $attributes['object_type_id'] === ''
                ? null
                : (int) $attributes['object_type_id'];
        }

        if (array_key_exists('role_ids', $attributes)) {
            $roleIds = array_values(array_unique(array_map('intval', (array) ($attributes['role_ids'] ?? []))));
            // [] and NULL both mean "every role"; store the one the resolver
            // has always matched first.
            $payload['role_ids'] = $roleIds === [] ? null : $roleIds;
        }

        if (array_key_exists('is_default_for_role', $attributes)) {
            $payload['is_default_for_role'] = (bool) $attributes['is_default_for_role'];
        }

        if ($payload !== []) {
            $dashboard->update($payload);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tabs */
    /* ------------------------------------------------------------------ */

    public function addTab(Dashboard $dashboard, string $label = 'New tab'): string
    {
        $dashboard = $this->editable($dashboard);
        $code = 'tab-'.str()->lower(str()->random(6));

        $tabs = $dashboard->tabList();
        $tabs[] = ['code' => $code, 'label' => trim($label) ?: 'New tab', 'layout' => []];

        $dashboard->update(['tabs' => $tabs]);

        return $code;
    }

    public function renameTab(Dashboard $dashboard, string $code, string $label): void
    {
        $dashboard = $this->editable($dashboard);
        $tabs = $dashboard->tabList();

        foreach ($tabs as $index => $tab) {
            if ($tab['code'] === $code) {
                $tabs[$index]['label'] = trim($label) ?: $tab['label'];
            }
        }

        $dashboard->update(['tabs' => $tabs]);
    }

    public function removeTab(Dashboard $dashboard, string $code): void
    {
        $dashboard = $this->editable($dashboard);
        $tabs = $dashboard->tabList();

        if (count($tabs) <= 1) {
            throw ValidationException::withMessages(['tab' => 'A dashboard needs at least one tab.']);
        }

        $dashboard->update(['tabs' => array_values(array_filter($tabs, fn (array $tab) => $tab['code'] !== $code))]);
    }

    /* ------------------------------------------------------------------ */
    /*  Widgets */
    /* ------------------------------------------------------------------ */

    /** @return int the placement's position on the tab */
    public function addWidget(Dashboard $dashboard, string $tabCode, int $widgetId): int
    {
        $dashboard = $this->editable($dashboard);
        $widget = WidgetDefinition::query()->find($widgetId);

        if ($widget === null) {
            throw ValidationException::withMessages(['widget_id' => 'That widget no longer exists.']);
        }

        $tabs = $dashboard->tabList();
        $position = -1;

        foreach ($tabs as $index => $tab) {
            if ($tab['code'] !== $tabCode) {
                continue;
            }

            // Place at the bottom; GridStack compacts on the next paint.
            $maxY = collect($tab['layout'])->max(fn (array $p) => (int) ($p['y'] ?? 0) + (int) ($p['h'] ?? 3)) ?? 0;

            $tabs[$index]['layout'][] = [
                'widget_id' => $widget->id,
                'x' => 0,
                'y' => $maxY,
                'w' => max((int) $widget->min_w, 4),
                'h' => max((int) $widget->min_h, 3),
                'overrides' => [],
            ];

            $position = count($tabs[$index]['layout']) - 1;
        }

        if ($position === -1) {
            throw ValidationException::withMessages(['tab' => 'That tab does not exist.']);
        }

        $dashboard->update(['tabs' => $tabs]);

        return $position;
    }

    public function removeWidget(Dashboard $dashboard, string $tabCode, int $position): void
    {
        $dashboard = $this->editable($dashboard);
        $tabs = $dashboard->tabList();

        foreach ($tabs as $index => $tab) {
            if ($tab['code'] === $tabCode && isset($tab['layout'][$position])) {
                unset($tabs[$index]['layout'][$position]);
                $tabs[$index]['layout'] = array_values($tabs[$index]['layout']);
            }
        }

        $dashboard->update(['tabs' => $tabs]);
    }

    public function overrideTitle(Dashboard $dashboard, string $tabCode, int $position, string $title): void
    {
        $dashboard = $this->editable($dashboard);
        $tabs = $dashboard->tabList();

        foreach ($tabs as $index => $tab) {
            if ($tab['code'] === $tabCode && isset($tab['layout'][$position])) {
                $overrides = $tabs[$index]['layout'][$position]['overrides'] ?? [];

                if (trim($title) === '') {
                    unset($overrides['title']);
                } else {
                    $overrides['title'] = trim($title);
                }

                $tabs[$index]['layout'][$position]['overrides'] = $overrides;
            }
        }

        $dashboard->update(['tabs' => $tabs]);
    }

    /**
     * GridStack's report of one tab's arrangement: [{position, x, y, w, h}].
     * Bounds are clamped exactly as the Livewire component clamped them.
     *
     * @param  list<array{position: int, x: int, y: int, w: int, h: int}>  $items
     */
    public function updateLayout(Dashboard $dashboard, string $tabCode, array $items): void
    {
        $dashboard = $this->editable($dashboard);
        $tabs = $dashboard->tabList();

        foreach ($tabs as $index => $tab) {
            if ($tab['code'] !== $tabCode) {
                continue;
            }

            foreach ($items as $item) {
                $position = (int) ($item['position'] ?? -1);

                if (! isset($tab['layout'][$position])) {
                    continue;
                }

                $tabs[$index]['layout'][$position]['x'] = max(0, min(11, (int) ($item['x'] ?? 0)));
                $tabs[$index]['layout'][$position]['y'] = max(0, (int) ($item['y'] ?? 0));
                $tabs[$index]['layout'][$position]['w'] = max(1, min(12, (int) ($item['w'] ?? 4)));
                $tabs[$index]['layout'][$position]['h'] = max(1, (int) ($item['h'] ?? 3));
            }
        }

        $dashboard->update(['tabs' => $tabs]);
    }

    /* ------------------------------------------------------------------ */
    /*  Publish, unpublish, discard, duplicate */
    /* ------------------------------------------------------------------ */

    /**
     * Copy the draft over the live layout.
     *
     * The refusal is not validation for its own sake: a dashboard bound to a
     * type with no nodes publishes cleanly, reports success, and appears
     * nowhere — the exact failure that made Business HQ look broken. The
     * only place to catch it is the moment someone claims the composition is
     * finished.
     *
     * @return array{published: bool, message: string}
     */
    public function publish(Dashboard $dashboard): array
    {
        $dashboard = $this->editable($dashboard);
        $option = $this->bindingOption($dashboard);

        if ($option !== null && ! $option['renderable']) {
            return [
                'published' => false,
                'message' => $option['is_node_type']
                    ? 'Not published: no '.$option['name'].' nodes exist, so nobody would see it.'
                    : 'Not published: '.$option['name'].' is not a node type, so Business HQ has no page to render it on.',
            ];
        }

        $wasPublished = $dashboard->is_published;

        $dashboard->publish();

        return [
            'published' => true,
            'message' => $wasPublished
                ? 'Published v'.$dashboard->version.' — Business HQ now shows your changes.'
                : 'Published. It is now live on every '.($option['name'] ?? 'matching').' node.',
        ];
    }

    public function unpublish(Dashboard $dashboard): void
    {
        $this->editable($dashboard)->unpublish();
    }

    /**
     * Throw away the draft and go back to what is live — the way back out of
     * a rearrangement you thought better of.
     */
    public function discardChanges(Dashboard $dashboard): bool
    {
        $dashboard = $this->editable($dashboard);

        if (! $dashboard->is_published) {
            return false;
        }

        $dashboard->forceFill(['tabs' => $dashboard->publishedTabList()])->save();

        return true;
    }

    /**
     * Save-as-template: a draft copy the team can rework without touching
     * this one. The copy belongs to the tenant making it, never to nobody —
     * copying a SYSTEM dashboard must produce an editable tenant row.
     */
    public function duplicate(Dashboard $source, User $user): Dashboard
    {
        return Dashboard::create([
            'organization_id' => TenantContext::organizationId(),
            'code' => 'dashboard-'.str()->lower(str()->random(8)),
            'name' => $source->name.' (copy)',
            'object_type_id' => $source->object_type_id,
            'role_ids' => $source->role_ids,
            // Copy the DRAFT: the point of a template is to rework it.
            'tabs' => $source->tabList(),
            'published_tabs' => null,
            'is_published' => false,
            'version' => 1,
        ]);
    }

    /**
     * The annotated binding for a dashboard's current object type — the same
     * option row the selector shows, so the header and the publish guard
     * cannot disagree about whether a binding reaches anything.
     *
     * @return array<string, mixed>|null
     */
    public function bindingOption(Dashboard $dashboard): ?array
    {
        foreach ($this->binding->objectTypeOptions($dashboard->id) as $option) {
            if ((int) ($option['id'] ?? 0) === (int) ($dashboard->object_type_id ?? 0)) {
                return $option;
            }
        }

        return null;
    }

    /**
     * widget_type → the category a risk manager would look under. Three
     * buckets is what the library needs to be browsable; anything unmapped
     * falls to "Other" rather than disappearing.
     */
    public const CATEGORIES = [
        'kpi_tile' => 'Numbers',
        'gauge' => 'Numbers',
        'donut' => 'Numbers',
        'heatmap' => 'Charts',
        'opportunity_heatmap' => 'Charts',
        'bar_by_type' => 'Charts',
        'grouped_bar_3' => 'Charts',
        'stacked_bar_bands' => 'Charts',
        'trend_stacked_bar' => 'Charts',
        'stacked_area' => 'Charts',
        'cumulative_line' => 'Charts',
        'lec_curve' => 'Charts',
        'pareto' => 'Charts',
        'tornado' => 'Charts',
        'bubble' => 'Charts',
        'treemap' => 'Charts',
        'network' => 'Charts',
        'timeline' => 'Charts',
        'register' => 'Tables',
        'activity_table' => 'Tables',
        'measure_table' => 'Tables',
    ];

    /**
     * The widget library for the builder's palette: every definition the
     * tenant can see, grouped by category.
     *
     * @return list<array{category: string, widgets: list<array<string, mixed>>}>
     */
    public function palette(): array
    {
        return WidgetDefinition::query()
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'widget_type', 'min_w', 'min_h'])
            ->map(fn (WidgetDefinition $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'description' => $w->description ?: str_replace('_', ' ', $w->widget_type),
                'type' => $w->widget_type,
                'category' => self::CATEGORIES[$w->widget_type] ?? 'Other',
                'min_w' => (int) $w->min_w,
                'min_h' => (int) $w->min_h,
            ])
            ->groupBy('category')
            ->sortKeys()
            ->map(fn ($widgets, $category) => ['category' => $category, 'widgets' => $widgets->values()->all()])
            ->values()
            ->all();
    }
}
