<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Presenters\GridPresenter;
use App\Presenters\Kri\KriLibraryPresenter;
use App\Support\Kri\KriDepartmentScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * One department's KRIs (ADR 0026 section 15): `Kri/Department`, for a business
 * unit or for the Unassigned group. The thin controller of the three: it
 * authorises and hands the shaping to KriLibraryPresenter.
 *
 * Out-of-tenant ids are 404 (the global scope); in-tenant, out-of-scope ids are
 * 403. The Unassigned group is visible to every `kri.view` holder.
 */
class KriDepartmentController extends Controller
{
    public function __construct(
        private readonly KriLibraryPresenter $library,
        private readonly KriDepartmentScope $scope,
    ) {}

    public function store(Request $request)
    {
        Gate::authorize('viewAny', KeyRiskIndicator::class);

        $orgId = TenantContext::organizationId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'parent_id' => ['nullable', 'integer', 'exists:business_units,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $code = trim((string) ($validated['code'] ?? ''));
        if ($code === '') {
            $baseCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $validated['name']), 0, 4));
            if (strlen($baseCode) < 2) {
                $baseCode = 'DEP';
            }
            $count = BusinessUnit::withoutGlobalScopes()
                ->where('organization_id', $orgId)
                ->where('code', 'like', "{$baseCode}%")
                ->count();
            $code = $count > 0 ? "{$baseCode}-" . str_pad($count + 1, 2, '0', STR_PAD_LEFT) : $baseCode;
        }

        $unit = BusinessUnit::create([
            'organization_id' => $orgId,
            'name' => $validated['name'],
            'code' => $code,
            'parent_id' => $validated['parent_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', "Department '{$unit->name}' ({$unit->code}) created and added to KRI Library successfully.");
    }

    public function show(Request $request, BusinessUnit $department, GridPresenter $grids)
    {
        Gate::authorize('viewAny', KeyRiskIndicator::class);
        abort_unless($this->scope->reaches($request->user(), (int) $department->id), 403);

        return $this->render($request, $grids, $department, (string) $department->id);
    }

    public function unassigned(Request $request, GridPresenter $grids)
    {
        Gate::authorize('viewAny', KeyRiskIndicator::class);

        return $this->render($request, $grids, null, 'unassigned');
    }

    private function render(Request $request, GridPresenter $grids, ?BusinessUnit $unit, string $filterValue)
    {
        // The department filter is LOCKED: whatever the client sent is replaced.
        $filters = (array) $request->query('filters', []);
        $filters['department'] = $filterValue;
        $request->query->set('filters', $filters);

        return Inertia::render('Kri/Department', $this->library->department($request->user(), $unit) + [
            'grid' => fn () => $grids->present(GridRegistry::resolve('kris'), $request, $request->user()),
        ]);
    }
}
