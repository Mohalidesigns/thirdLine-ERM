<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appetite\StoreRiskAppetiteRequest;
use App\Http\Requests\Appetite\UpdateRiskAppetiteRequest;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use App\Services\Appetite\AppetiteFrameworkService;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The risk appetite framework (migration Phase 3.6): one page, with the
 * create and edit forms in modals posting to the two existing routes.
 */
class RiskAppetiteController extends Controller
{
    public function __construct(private readonly AppetiteFrameworkService $framework) {}

    public function index()
    {
        Gate::authorize('viewAny', RiskAppetite::class);

        $organizationId = (int) TenantContext::organizationId();
        $statements = $this->framework->statements($organizationId);
        $metrics = $this->framework->metrics($statements);

        return Inertia::render('Appetite/Index', [
            'metrics' => $metrics,
            'summary' => $this->framework->summary($metrics, $statements),
            'chart' => $this->framework->chart($metrics),
            'categoriesWithoutStatement' => $this->framework
                ->categoriesWithoutStatement($organizationId, $statements)
                ->map(fn (RiskCategory $c) => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'levels' => AppetiteFrameworkService::LEVELS,
            'canManage' => Gate::allows('create', RiskAppetite::class),
            'exportUrl' => route('risk.export.appetite'),
        ]);
    }

    public function store(StoreRiskAppetiteRequest $request)
    {
        $appetite = RiskAppetite::create(array_merge($request->validated(), [
            'organization_id' => TenantContext::organizationId(),
        ]));

        AuditTrailService::record($appetite, 'create');

        return redirect()->route('risk.appetite.index')
            ->with('success', 'Risk appetite statement has been created.');
    }

    public function update(UpdateRiskAppetiteRequest $request, RiskAppetite $appetite)
    {
        $original = $appetite->getAttributes();

        $appetite->update($request->validated());

        AuditTrailService::recordChanges($appetite, $original);

        return redirect()->route('risk.appetite.index')
            ->with('success', 'Risk appetite statement has been updated.');
    }
}
