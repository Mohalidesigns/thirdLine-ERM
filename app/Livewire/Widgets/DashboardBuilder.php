<?php

namespace App\Livewire\Widgets;

use App\Models\Dashboard;
use App\Models\WidgetDefinition;
use App\Services\Widgets\DashboardBinding;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * WP-08 TASK 3 — the dashboard builder.
 *
 * State is the dashboard's `tabs` JSON held as a Livewire property; the
 * 12-column drag-drop surface is GridStack running client-side over a
 * wire:ignore container (resources/js/widgets/builder.js), which reports
 * every move/resize back through updateLayout(). Livewire owns the truth;
 * GridStack is only the hands.
 *
 * Publishing bumps `version`, which is what invalidates every user's saved
 * layout override (see DashboardUserPref) — a republish is a statement that
 * the published arrangement matters again.
 *
 * WP-13 — persist() writes the DRAFT (`tabs`). It used to write the only
 * layout there was, so every drag on a published dashboard was instantly on
 * the board's HQ page. Publishing now copies the draft over the live layout
 * (Dashboard::publish()), which is what makes "Publish changes" a real action
 * and "Unpublished changes" a state worth showing.
 *
 * Authorization: the route group already demands dashboard.manage; mount()
 * re-checks so the component cannot be mounted from elsewhere without it.
 */
class DashboardBuilder extends Component
{
    public int $dashboardId;

    public string $name = '';

    public ?int $objectTypeId = null;

    /** @var list<int> */
    public array $roleIds = [];

    public bool $isDefaultForRole = false;

    /** @var list<array{code: string, label: string, layout: array}> */
    public array $tabs = [];

    public string $activeTab = '';

    public bool $isPublished = false;

    /** Library search box. */
    public string $search = '';

    /** Library category filter: '' = all. See widgetCategory(). */
    public string $category = '';

    public function mount(int $dashboardId): void
    {
        abort_unless(auth()->user()?->can('dashboard.manage'), 403);

        $dashboard = Dashboard::query()->findOrFail($dashboardId);

        $this->dashboardId = $dashboard->id;
        $this->name = $dashboard->name;
        $this->objectTypeId = $dashboard->object_type_id;
        $this->roleIds = array_map('intval', $dashboard->role_ids ?? []);
        $this->isDefaultForRole = (bool) $dashboard->is_default_for_role;
        $this->tabs = $dashboard->tabList();
        $this->activeTab = $this->tabs[0]['code'] ?? '';
        $this->isPublished = (bool) $dashboard->is_published;
    }

    /* ------------------------------------------------------------------ */
    /*  Tabs */
    /* ------------------------------------------------------------------ */

    public function addTab(): void
    {
        $code = 'tab-'.str()->lower(str()->random(6));

        $this->tabs[] = ['code' => $code, 'label' => 'New tab', 'layout' => []];
        $this->activeTab = $code;
        $this->persist();
    }

    public function renameTab(string $code, string $label): void
    {
        foreach ($this->tabs as $index => $tab) {
            if ($tab['code'] === $code) {
                $this->tabs[$index]['label'] = trim($label) ?: $tab['label'];
            }
        }

        $this->persist();
    }

    public function removeTab(string $code): void
    {
        if (count($this->tabs) <= 1) {
            return; // a dashboard with zero tabs renders nothing anywhere
        }

        $this->tabs = array_values(array_filter($this->tabs, fn (array $tab) => $tab['code'] !== $code));

        if ($this->activeTab === $code) {
            $this->activeTab = $this->tabs[0]['code'];
        }

        $this->persist();
    }

    public function selectTab(string $code): void
    {
        $this->activeTab = $code;
        $this->dispatch('builder-grid-reload');
    }

    /* ------------------------------------------------------------------ */
    /*  Widgets */
    /* ------------------------------------------------------------------ */

    public function addWidget(int $widgetId): void
    {
        $widget = WidgetDefinition::query()->find($widgetId);

        if ($widget === null) {
            return;
        }

        foreach ($this->tabs as $index => $tab) {
            if ($tab['code'] !== $this->activeTab) {
                continue;
            }

            // Place at the bottom; GridStack compacts on the next paint.
            $maxY = collect($tab['layout'])->max(fn (array $p) => (int) ($p['y'] ?? 0) + (int) ($p['h'] ?? 3)) ?? 0;

            $this->tabs[$index]['layout'][] = [
                'widget_id' => $widget->id,
                'x' => 0,
                'y' => $maxY,
                'w' => max((int) $widget->min_w, 4),
                'h' => max((int) $widget->min_h, 3),
                'overrides' => [],
            ];
        }

        $this->persist();
        $this->dispatch('builder-grid-reload');
    }

    public function removeWidget(string $tabCode, int $position): void
    {
        foreach ($this->tabs as $index => $tab) {
            if ($tab['code'] === $tabCode && isset($tab['layout'][$position])) {
                unset($this->tabs[$index]['layout'][$position]);
                $this->tabs[$index]['layout'] = array_values($this->tabs[$index]['layout']);
            }
        }

        $this->persist();
        $this->dispatch('builder-grid-reload');
    }

    public function overrideTitle(string $tabCode, int $position, string $title): void
    {
        foreach ($this->tabs as $index => $tab) {
            if ($tab['code'] === $tabCode && isset($tab['layout'][$position])) {
                $overrides = $this->tabs[$index]['layout'][$position]['overrides'] ?? [];

                if (trim($title) === '') {
                    unset($overrides['title']);
                } else {
                    $overrides['title'] = trim($title);
                }

                $this->tabs[$index]['layout'][$position]['overrides'] = $overrides;
            }
        }

        $this->persist();
    }

    /**
     * GridStack's report of the active tab's arrangement:
     * [{position, x, y, w, h}], keyed by the placement's index.
     *
     * @param  list<array{position: int, x: int, y: int, w: int, h: int}>  $items
     */
    public function updateLayout(array $items): void
    {
        foreach ($this->tabs as $index => $tab) {
            if ($tab['code'] !== $this->activeTab) {
                continue;
            }

            foreach ($items as $item) {
                $position = (int) ($item['position'] ?? -1);

                if (! isset($tab['layout'][$position])) {
                    continue;
                }

                $this->tabs[$index]['layout'][$position]['x'] = max(0, min(11, (int) ($item['x'] ?? 0)));
                $this->tabs[$index]['layout'][$position]['y'] = max(0, (int) ($item['y'] ?? 0));
                $this->tabs[$index]['layout'][$position]['w'] = max(1, min(12, (int) ($item['w'] ?? 4)));
                $this->tabs[$index]['layout'][$position]['h'] = max(1, (int) ($item['h'] ?? 3));
            }
        }

        $this->persist();
    }

    /* ------------------------------------------------------------------ */
    /*  Meta, publish, clone */
    /* ------------------------------------------------------------------ */

    public function updatedName(): void
    {
        $this->persist();
    }

    public function updatedObjectTypeId($value): void
    {
        $this->objectTypeId = $value === '' || $value === null ? null : (int) $value;
        $this->persist();
    }

    public function toggleRole(int $roleId): void
    {
        $this->roleIds = in_array($roleId, $this->roleIds, true)
            ? array_values(array_diff($this->roleIds, [$roleId]))
            : [...$this->roleIds, $roleId];

        $this->persist();
    }

    /**
     * Copy the draft over the live layout.
     *
     * The refusal below is not validation for its own sake. A dashboard bound
     * to a type with no nodes — Obligation, in the tenant this was written
     * for — publishes cleanly, reports success, and appears nowhere at all.
     * That is the exact failure that made Business HQ look broken, and the
     * only place to catch it is the moment someone claims the composition is
     * finished.
     */
    public function publish(): void
    {
        $dashboard = $this->dashboard();
        $option = $this->bindingOption();

        if ($option !== null && ! $option['renderable']) {
            $this->dispatch('builder-saved', message: $option['is_node_type']
                ? 'Not published: no '.$option['name'].' nodes exist, so nobody would see it.'
                : 'Not published: '.$option['name'].' is not a node type, so Business HQ has no page to render it on.');

            return;
        }

        $wasPublished = $dashboard->is_published;

        $dashboard->publish();

        $this->isPublished = true;
        $this->dispatch('builder-saved', message: $wasPublished
            ? 'Published v'.$dashboard->version.' — Business HQ now shows your changes.'
            : 'Published. It is now live on every '.($option['name'] ?? 'matching').' node.');
    }

    public function unpublish(): void
    {
        $this->dashboard()->unpublish();
        $this->isPublished = false;
        $this->dispatch('builder-saved', message: 'Unpublished — Business HQ will fall back to the default dashboard, if there is one.');
    }

    /**
     * Throw away the draft and go back to what is live.
     *
     * The counterpart to the draft/live split: without it, an admin who has
     * rearranged a published dashboard for twenty minutes and thought better
     * of it has no way back short of redoing it by hand.
     */
    public function discardChanges(): void
    {
        $dashboard = $this->dashboard();

        if (! $dashboard->is_published) {
            return;
        }

        $dashboard->forceFill(['tabs' => $dashboard->publishedTabList()])->save();

        $this->tabs = $dashboard->tabList();
        $this->activeTab = $this->tabs[0]['code'] ?? '';

        $this->dispatch('builder-grid-reload');
        $this->dispatch('builder-saved', message: 'Draft reset to the published v'.$dashboard->version.'.');
    }

    /** Save-as-template: a draft copy the team can rework without touching this one. */
    public function duplicate()
    {
        $source = $this->dashboard();

        $copy = Dashboard::create([
            // The copy belongs to the tenant making it, never to nobody.
            // Inheriting $source->organization_id meant copying a SYSTEM
            // dashboard (organization_id NULL, legal since WP-12) produced
            // another system dashboard — which dashboard() then refused to
            // let anyone edit, because its tenant assertion cannot be
            // satisfied by NULL. "Save as template" produced an unopenable row.
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

        return redirect()->route('risk.dashboards.edit', $copy);
    }

    public function render()
    {
        $dashboard = $this->dashboard();
        $option = $this->bindingOption();

        return view('livewire.widgets.dashboard-builder', [
            'palette' => $this->palette(),
            'categories' => $this->categories(),
            'placedWidgetIds' => collect($this->tabs)
                ->flatMap(fn ($t) => collect($t['layout'])->pluck('widget_id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->flip(),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'widgetsById' => WidgetDefinition::query()
                ->whereIn('id', collect($this->tabs)->flatMap(fn ($t) => collect($t['layout'])->pluck('widget_id'))->unique())
                ->get()
                ->keyBy('id'),

            // WP-13 — everything the header needs to say where this will show
            // up, whether the draft is ahead of the live version, and where to
            // preview it.
            'binding' => $option,
            'objectTypes' => app(DashboardBinding::class)->objectTypeOptions($this->dashboardId),
            'hasUnpublishedChanges' => $dashboard->hasUnpublishedChanges(),
            'version' => (int) $dashboard->version,
            'publishedWidgetCount' => $dashboard->publishedWidgetCount(),
        ]);
    }

    /**
     * The widget library, filtered by the search box and category chips.
     *
     * Filtering in SQL rather than in Blade because a tenant with a large
     * library would otherwise ship every row to the browser on every
     * keystroke, and Livewire re-renders the whole component per keystroke.
     */
    private function palette()
    {
        $types = $this->category === ''
            ? null
            : array_keys(array_filter(self::CATEGORIES, fn (string $c) => $c === $this->category));

        return WidgetDefinition::query()
            ->when($types !== null, fn ($q) => $q->whereIn('widget_type', $types))
            ->when(trim($this->search) !== '', function ($q) {
                $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';

                $q->where(fn ($w) => $w
                    ->where('name', 'like', $needle)
                    ->orWhere('description', 'like', $needle)
                    ->orWhere('widget_type', 'like', $needle));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'widget_type', 'min_w', 'min_h'])
            ->groupBy(fn (WidgetDefinition $w) => self::CATEGORIES[$w->widget_type] ?? 'Other');
    }

    /** @return list<string> */
    private function categories(): array
    {
        return collect(self::CATEGORIES)->values()->unique()->sort()->values()->all();
    }

    /**
     * The annotated binding for this dashboard's current object type.
     *
     * Read from the live selector options so the header and the publish guard
     * cannot disagree about whether a binding reaches anything.
     *
     * @return array{id:?int,name:string,is_node_type:bool,node_count:int,first_node_id:?int,renderable:bool,published:?Dashboard}|null
     */
    private function bindingOption(): ?array
    {
        foreach (app(DashboardBinding::class)->objectTypeOptions($this->dashboardId) as $option) {
            if ((int) ($option['id'] ?? 0) === (int) ($this->objectTypeId ?? 0)) {
                return $option;
            }
        }

        return null;
    }

    private function dashboard(): Dashboard
    {
        $dashboard = Dashboard::query()->findOrFail($this->dashboardId);

        // Belt and braces: the global scope already prevents a cross-tenant
        // id from resolving, but the builder writes, so assert anyway.
        //
        // A SYSTEM dashboard (organization_id NULL, legal since WP-12) is
        // shared by every tenant and must never be edited in place — one
        // bank's rearrangement would land on every other bank's HQ page.
        // Refusing it here is deliberate; "Save as template" is the way to
        // take a copy. The comparison is written against NULL explicitly
        // because the old `(int) null === (int) $orgId` said the same thing
        // by accident, through a cast, and read like a bug.
        abort_if($dashboard->organization_id === null, 403, 'System dashboards cannot be edited. Save a copy first.');
        abort_unless((int) $dashboard->organization_id === (int) TenantContext::organizationId(), 403);

        return $dashboard;
    }

    /**
     * widget_type → the category a risk manager would look under.
     *
     * The engine's ~21 renderer codes are an implementation vocabulary
     * ("grouped_bar_3", "lec_curve"); nobody composing a board pack thinks in
     * them. Three buckets is what the library needs to be browsable, and
     * anything unmapped falls to "Other" rather than disappearing.
     */
    private const CATEGORIES = [
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

    private function persist(): void
    {
        $this->dashboard()->update([
            'name' => trim($this->name) ?: 'Untitled dashboard',
            'object_type_id' => $this->objectTypeId,
            'role_ids' => $this->roleIds === [] ? null : array_values($this->roleIds),
            'is_default_for_role' => $this->isDefaultForRole,
            'tabs' => $this->tabs,
        ]);
    }
}
