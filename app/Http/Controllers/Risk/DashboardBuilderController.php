<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\ObjectType;
use App\Services\Widgets\DashboardBinding;
use Illuminate\Http\Request;

/**
 * WP-08 TASK 3 — the dashboard builder's page shell. The actual composition
 * (tabs, drag-drop grid, publishing) is the DashboardBuilder Livewire
 * component; these actions just list, create and host.
 *
 * Route-level guard: dashboard.manage on the whole group in routes/web.php.
 *
 * WP-13 — the list is no longer a flat roll of names. A dashboard's binding
 * decides whether it is ever seen, and the old list showed the binding as a
 * bare type name with nothing to say that "Obligation" meant "nowhere". Live
 * dashboards are now separated from drafts, and each row carries the count of
 * nodes it reaches (see DashboardBinding).
 */
class DashboardBuilderController extends Controller
{
    public function __construct(private readonly DashboardBinding $binding) {}

    public function index()
    {
        $dashboards = Dashboard::query()
            ->with('objectType')
            ->orderBy('name')
            ->get();

        $options = collect($this->binding->objectTypeOptions())
            ->keyBy(fn (array $option) => (int) ($option['id'] ?? 0));

        // Annotate each row once, here, rather than making the Blade template
        // re-derive reach and warnings per row.
        $rows = $dashboards->map(function (Dashboard $dashboard) use ($options) {
            $option = $options[(int) ($dashboard->object_type_id ?? 0)] ?? null;

            return [
                'dashboard' => $dashboard,
                'option' => $option,
                'warning' => $option === null ? null : $this->binding->warningFor($dashboard, $option),
            ];
        });

        return view('risk.dashboards.index', [
            // Published first: those are the ones with consequences.
            'live' => $rows->filter(fn (array $row) => $row['dashboard']->is_published)->values(),
            'drafts' => $rows->reject(fn (array $row) => $row['dashboard']->is_published)->values(),
            'objectTypes' => $options->values(),
        ]);
    }

    /**
     * Create a dashboard, optionally pre-bound to a type.
     *
     * `object_type_id` arrives from Business HQ's empty state — an admin who
     * lands on an Enterprise node with nothing published clicks "Create an
     * Enterprise dashboard" and gets one already bound to Enterprise. Sending
     * them to a blank untyped dashboard is how the binding gets left on
     * whatever the selector happened to default to, which is the mistake this
     * whole work package exists to stop.
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
            : (ObjectType::query()->find($objectTypeId)?->name ?? 'Untitled').' dashboard';

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

    public function edit(Dashboard $dashboard)
    {
        return view('risk.dashboards.edit', [
            'dashboard' => $dashboard,
            'objectTypes' => ObjectType::query()->orderBy('name')->get(['id', 'name', 'is_node_type']),
        ]);
    }
}
