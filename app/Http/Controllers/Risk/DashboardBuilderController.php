<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\ObjectType;
use App\Models\WidgetDefinition;
use App\Services\Widgets\DashboardBinding;
use App\Services\Widgets\DashboardEditor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

/**
 * WP-08 TASK 3 — the dashboard builder: its list, its editor page, and the
 * editor's actions (migration Phase 2: the Livewire DashboardBuilder
 * component became DashboardEditor + these endpoints + Pages/Dashboards).
 *
 * Route-level guard: dashboard.manage on the whole group in routes/web.php.
 * DashboardEditor::editable() re-asserts tenancy and refuses system rows.
 *
 * WP-13 — the list is not a flat roll of names. A dashboard's binding
 * decides whether it is ever seen; live dashboards are separated from drafts
 * and each row carries the count of nodes it reaches (DashboardBinding).
 */
class DashboardBuilderController extends Controller
{
    public function __construct(
        private readonly DashboardBinding $binding,
        private readonly DashboardEditor $editor,
    ) {}

    public function index()
    {
        $dashboards = Dashboard::query()
            ->with('objectType')
            ->orderBy('name')
            ->get();

        $options = collect($this->binding->objectTypeOptions())
            ->keyBy(fn (array $option) => (int) ($option['id'] ?? 0));

        $rows = $dashboards->map(function (Dashboard $dashboard) use ($options) {
            $option = $options[(int) ($dashboard->object_type_id ?? 0)] ?? null;

            return [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'editUrl' => route('risk.dashboards.edit', $dashboard),
                'isPublished' => (bool) $dashboard->is_published,
                'isSystem' => $dashboard->organization_id === null,
                'version' => (int) $dashboard->version,
                'tabCount' => count($dashboard->tabList()),
                'draftWidgetCount' => $dashboard->draftWidgetCount(),
                'publishedWidgetCount' => $dashboard->publishedWidgetCount(),
                'roleCount' => count($dashboard->role_ids ?? []),
                'hasUnpublishedChanges' => $dashboard->hasUnpublishedChanges(),
                'binding' => [
                    'name' => $option['name'] ?? ($dashboard->objectType?->name ?? 'any object type'),
                    'nodeCount' => $option['node_count'] ?? null,
                    'firstNodeId' => $option['first_node_id'] ?? null,
                ],
                'warning' => $option === null ? null : $this->binding->warningFor($dashboard, $option),
            ];
        });

        return Inertia::render('Dashboards/Index', [
            // Published first: those are the ones with consequences.
            'live' => $rows->filter(fn (array $row) => $row['isPublished'])->values()->all(),
            'drafts' => $rows->reject(fn (array $row) => $row['isPublished'])->values()->all(),
            'createUrl' => route('risk.dashboards.create'),
        ]);
    }

    /**
     * Create a dashboard, optionally pre-bound to a type.
     *
     * `object_type_id` arrives from Business HQ's empty state — an admin who
     * lands on an Enterprise node with nothing published clicks "Create an
     * Enterprise dashboard" and gets one already bound to Enterprise.
     */
    public function create(Request $request)
    {
        $requested = $request->integer('object_type_id') ?: null;

        // Trust nothing from the query string: a type from another tenant must
        // not become this dashboard's binding just because it was in the URL.
        $objectTypeId = $requested !== null && ObjectType::query()->whereKey($requested)->exists()
            ? $requested
            : null;

        $name = $objectTypeId === null
            ? 'Untitled dashboard'
            : (ObjectType::query()->whereKey($objectTypeId)->value('name') ?? 'Untitled').' dashboard';

        $dashboard = Dashboard::create([
            'organization_id' => $request->user()->organization_id,
            'code' => 'dashboard-'.str()->lower(str()->random(8)),
            'name' => $name,
            'object_type_id' => $objectTypeId,
            'tabs' => [['code' => 'main', 'label' => 'Dashboard', 'layout' => []]],
            // A new dashboard has never been live, so it has no published
            // layout. NULL, not a copy of the empty draft.
            'published_tabs' => null,
            'is_published' => false,
            'version' => 1,
        ]);

        return redirect()->route('risk.dashboards.edit', $dashboard);
    }

    public function edit(Request $request, Dashboard $dashboard)
    {
        return Inertia::render('Dashboards/Edit', $this->editorProps($dashboard, (string) $request->query('tab', '')));
    }

    /* ------------------------------------------------------------------ */
    /*  Editor actions — each returns to the editor with a flash */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, Dashboard $dashboard)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'object_type_id' => ['sometimes', 'nullable', 'integer'],
            'role_ids' => ['sometimes', 'nullable', 'array'],
            'role_ids.*' => ['integer'],
            'is_default_for_role' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('object_type_id', $validated) && $validated['object_type_id'] !== null
            && ! ObjectType::query()->whereKey($validated['object_type_id'])->exists()) {
            abort(422, 'Unknown object type.');
        }

        $this->editor->updateSettings($dashboard, $validated);

        return back();
    }

    public function storeTab(Request $request, Dashboard $dashboard)
    {
        $validated = $request->validate(['label' => ['nullable', 'string', 'max:60']]);

        $code = $this->editor->addTab($dashboard, (string) ($validated['label'] ?? 'New tab'));

        return redirect()->route('risk.dashboards.edit', [$dashboard, 'tab' => $code]);
    }

    public function updateTab(Request $request, Dashboard $dashboard, string $tab)
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:60']]);

        $this->editor->renameTab($dashboard, $tab, $validated['label']);

        return back();
    }

    public function destroyTab(Dashboard $dashboard, string $tab)
    {
        $this->editor->removeTab($dashboard, $tab);

        return redirect()->route('risk.dashboards.edit', $dashboard);
    }

    public function storeWidget(Request $request, Dashboard $dashboard, string $tab)
    {
        $validated = $request->validate(['widget_id' => ['required', 'integer']]);

        $this->editor->addWidget($dashboard, $tab, (int) $validated['widget_id']);

        return back();
    }

    public function destroyWidget(Dashboard $dashboard, string $tab, int $position)
    {
        $this->editor->removeWidget($dashboard, $tab, $position);

        return back();
    }

    public function updateWidget(Request $request, Dashboard $dashboard, string $tab, int $position)
    {
        $validated = $request->validate(['title' => ['nullable', 'string', 'max:120']]);

        $this->editor->overrideTitle($dashboard, $tab, $position, (string) ($validated['title'] ?? ''));

        return back();
    }

    /**
     * GridStack's serialisation of one tab: {tab, items: [{position,x,y,w,h}]}
     * — the same shape resources/js/widgets/builder.js always posted.
     */
    public function updateLayout(Request $request, Dashboard $dashboard)
    {
        $validated = $request->validate([
            'tab' => ['required', 'string'],
            'items' => ['present', 'array'],
            'items.*.position' => ['required', 'integer', 'min:0'],
            'items.*.x' => ['required', 'integer'],
            'items.*.y' => ['required', 'integer'],
            'items.*.w' => ['required', 'integer'],
            'items.*.h' => ['required', 'integer'],
        ]);

        $this->editor->updateLayout($dashboard, $validated['tab'], $validated['items']);

        // Autosave from a drag: nothing to say, nothing to re-render.
        return response()->noContent();
    }

    public function publish(Dashboard $dashboard)
    {
        $result = $this->editor->publish($dashboard);

        return back()->with($result['published'] ? 'success' : 'error', $result['message']);
    }

    public function unpublish(Dashboard $dashboard)
    {
        $this->editor->unpublish($dashboard);

        return back()->with('success', 'Unpublished — Business HQ will fall back to the default dashboard, if there is one.');
    }

    public function discard(Dashboard $dashboard)
    {
        $reset = $this->editor->discardChanges($dashboard);

        return back()->with($reset ? 'success' : 'info', $reset
            ? 'Draft reset to the published v'.$dashboard->fresh()->version.'.'
            : 'Nothing to discard — this dashboard has never been published.');
    }

    public function duplicate(Request $request, Dashboard $dashboard)
    {
        $copy = $this->editor->duplicate($dashboard, $request->user());

        return redirect()->route('risk.dashboards.edit', $copy)->with('success', 'Copy created. This draft is yours to rework.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function editorProps(Dashboard $dashboard, string $activeTab): array
    {
        $tabs = $dashboard->tabList();
        $active = collect($tabs)->firstWhere('code', $activeTab)['code'] ?? ($tabs[0]['code'] ?? '');

        $widgetsById = WidgetDefinition::query()
            ->whereIn('id', collect($tabs)->flatMap(fn ($t) => collect($t['layout'])->pluck('widget_id'))->unique())
            ->get(['id', 'name', 'widget_type', 'min_w', 'min_h'])
            ->keyBy('id');

        $binding = $this->editor->bindingOption($dashboard);
        $isSystem = $dashboard->organization_id === null;

        return [
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'objectTypeId' => $dashboard->object_type_id,
                'roleIds' => array_map('intval', $dashboard->role_ids ?? []),
                'isDefaultForRole' => (bool) $dashboard->is_default_for_role,
                'isPublished' => (bool) $dashboard->is_published,
                'isSystem' => $isSystem,
                'version' => (int) $dashboard->version,
                'hasUnpublishedChanges' => $dashboard->hasUnpublishedChanges(),
                'publishedWidgetCount' => $dashboard->publishedWidgetCount(),
            ],
            'tabs' => collect($tabs)->map(fn (array $tab) => [
                'code' => $tab['code'],
                'label' => $tab['label'],
                'layout' => collect($tab['layout'])->values()->map(function (array $placement, int $position) use ($widgetsById) {
                    $widget = $widgetsById->get((int) ($placement['widget_id'] ?? 0));

                    return [
                        'position' => $position,
                        'widget_id' => (int) ($placement['widget_id'] ?? 0),
                        'x' => (int) ($placement['x'] ?? 0),
                        'y' => (int) ($placement['y'] ?? 0),
                        'w' => (int) ($placement['w'] ?? 4),
                        'h' => (int) ($placement['h'] ?? 3),
                        'overrides' => (object) ($placement['overrides'] ?? []),
                        'widget' => $widget === null ? null : [
                            'name' => $widget->name,
                            'type' => $widget->widget_type,
                            'min_w' => (int) $widget->min_w,
                            'min_h' => (int) $widget->min_h,
                        ],
                    ];
                })->all(),
            ])->values()->all(),
            'activeTab' => $active,
            'palette' => $this->editor->palette(),
            'placedWidgetIds' => $dashboard->placedWidgetIds(),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name'])->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name])->all(),
            'objectTypes' => collect($this->binding->objectTypeOptions($dashboard->id))->map(fn (array $o) => [
                'id' => $o['id'],
                'name' => $o['name'],
                'is_node_type' => $o['is_node_type'],
                'node_count' => $o['node_count'],
                'first_node_id' => $o['first_node_id'],
                'renderable' => $o['renderable'],
                'published' => $o['published'] === null ? null : ['id' => $o['published']->id, 'name' => $o['published']->name],
            ])->values()->all(),
            'binding' => $binding === null ? null : [
                'id' => $binding['id'],
                'name' => $binding['name'],
                'is_node_type' => $binding['is_node_type'],
                'node_count' => $binding['node_count'],
                'first_node_id' => $binding['first_node_id'],
                'renderable' => $binding['renderable'],
                'published' => $binding['published'] === null ? null : ['id' => $binding['published']->id, 'name' => $binding['published']->name],
                'previewUrl' => $binding['first_node_id'] ? route('hq.show', ['object' => $binding['first_node_id'], 'preview' => $dashboard->id]) : null,
            ],
            'urls' => [
                'index' => route('risk.dashboards.index'),
                'update' => route('risk.dashboards.update', $dashboard),
                'layout' => route('risk.dashboards.layout', $dashboard),
                'tabs' => route('risk.dashboards.tabs.store', $dashboard),
                'publish' => route('risk.dashboards.publish', $dashboard),
                'unpublish' => route('risk.dashboards.unpublish', $dashboard),
                'discard' => route('risk.dashboards.discard', $dashboard),
                'duplicate' => route('risk.dashboards.duplicate', $dashboard),
            ],
        ];
    }
}
