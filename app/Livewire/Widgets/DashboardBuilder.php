<?php

namespace App\Livewire\Widgets;

use App\Models\Dashboard;
use App\Models\WidgetDefinition;
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

    public function publish(): void
    {
        $dashboard = $this->dashboard();

        $dashboard->update([
            'is_published' => true,
            // The bump is the point: it retires every stale user override.
            'version' => $dashboard->version + 1,
        ]);

        $this->isPublished = true;
        $this->dispatch('builder-saved', message: 'Published as version '.($dashboard->version));
    }

    public function unpublish(): void
    {
        $this->dashboard()->update(['is_published' => false]);
        $this->isPublished = false;
        $this->dispatch('builder-saved', message: 'Unpublished');
    }

    /** Save-as-template: a draft copy the team can rework without touching this one. */
    public function duplicate()
    {
        $source = $this->dashboard();

        $copy = Dashboard::create([
            'organization_id' => $source->organization_id,
            'code' => $source->code.'-copy-'.str()->lower(str()->random(4)),
            'name' => $source->name.' (copy)',
            'object_type_id' => $source->object_type_id,
            'role_ids' => $source->role_ids,
            'tabs' => $source->tabs,
            'is_published' => false,
            'version' => 1,
        ]);

        return redirect()->route('risk.dashboards.edit', $copy);
    }

    public function render()
    {
        return view('livewire.widgets.dashboard-builder', [
            'palette' => WidgetDefinition::query()->orderBy('name')->get(['id', 'name', 'widget_type', 'min_w', 'min_h']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'widgetsById' => WidgetDefinition::query()
                ->whereIn('id', collect($this->tabs)->flatMap(fn ($t) => collect($t['layout'])->pluck('widget_id'))->unique())
                ->get()
                ->keyBy('id'),
        ]);
    }

    private function dashboard(): Dashboard
    {
        $dashboard = Dashboard::query()->findOrFail($this->dashboardId);

        // Belt and braces: the global scope already prevents a cross-tenant
        // id from resolving, but the builder writes, so assert anyway.
        abort_unless((int) $dashboard->organization_id === (int) TenantContext::organizationId(), 403);

        return $dashboard;
    }

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
