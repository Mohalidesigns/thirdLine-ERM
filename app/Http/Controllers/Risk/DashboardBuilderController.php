<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\ObjectType;
use Illuminate\Http\Request;

/**
 * WP-08 TASK 3 — the dashboard builder's page shell. The actual composition
 * (tabs, drag-drop grid, publishing) is the DashboardBuilder Livewire
 * component; these actions just list, create and host.
 *
 * Route-level guard: dashboard.manage on the whole group in routes/web.php.
 */
class DashboardBuilderController extends Controller
{
    public function index()
    {
        return view('risk.dashboards.index', [
            'dashboards' => Dashboard::query()
                ->with('objectType')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Request $request)
    {
        $dashboard = Dashboard::create([
            'organization_id' => $request->user()->organization_id,
            'code' => 'dashboard-'.str()->lower(str()->random(8)),
            'name' => 'Untitled dashboard',
            'tabs' => [['code' => 'main', 'label' => 'Dashboard', 'layout' => []]],
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
