<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Regulatory\RegulatoryCalendarRequest;
use App\Http\Requests\Regulatory\StoreCircularRequest;
use App\Http\Requests\Regulatory\StoreDeadlineRequest;
use App\Http\Requests\Regulatory\StoreTaxonomyRequest;
use App\Http\Requests\Regulatory\SubmitFilingRequest;
use App\Http\Requests\Regulatory\UpdateComplianceRequest;
use App\Models\RegulatoryCircular;
use App\Models\RegulatoryDeadline;
use App\Models\RegulatoryFiling;
use App\Models\Risk;
use App\Models\RiskTaxonomy;
use App\Models\User;
use App\Presenters\GridPresenter;
use App\Services\Regulatory\RegulatoryDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Regulatory compliance — the circular register, the filing calendar and the
 * risk taxonomy (migration Phase 5.3).
 *
 * Authorisation is by policy per model (RegulatoryCircularPolicy,
 * RegulatoryDeadlinePolicy, RiskTaxonomyPolicy) and every foreign key goes
 * through a Form Request carrying a tenant-bound Rule::exists — neither of
 * which this controller had. The figures are RegulatoryDashboardService's.
 */
class RegulatoryComplianceController extends Controller
{
    public function __construct(private readonly RegulatoryDashboardService $dashboards) {}

    public function dashboard()
    {
        Gate::authorize('viewAny', RegulatoryCircular::class);

        return Inertia::render('Regulatory/Dashboard', $this->dashboards->figures());
    }

    /**
     * A month of the filing calendar.
     *
     * The month and year are validated rather than taken raw: they reach
     * whereMonth/whereYear, and the page's own prev/next links are built from
     * them.
     */
    public function calendar(RegulatoryCalendarRequest $request)
    {
        Gate::authorize('viewAny', RegulatoryDeadline::class);

        $validated = $request->validated();

        $month = (int) ($validated['month'] ?? now()->month);
        $year = (int) ($validated['year'] ?? now()->year);

        $deadlines = RegulatoryDeadline::where('organization_id', TenantContext::organizationId())
            ->whereMonth('deadline_date', $month)
            ->whereYear('deadline_date', $year)
            ->with('responsible')
            ->orderBy('deadline_date')
            ->get()
            ->map(fn (RegulatoryDeadline $deadline) => $this->presentDeadline($deadline))
            ->values();

        return Inertia::render('Regulatory/Calendar', [
            'deadlines' => $deadlines,
            'month' => $month,
            'year' => $year,
        ]);
    }

    public function deadlines(Request $request)
    {
        Gate::authorize('viewAny', RegulatoryDeadline::class);

        $query = RegulatoryDeadline::where('organization_id', TenantContext::organizationId())
            ->with('responsible');

        if ($request->filled('regulator')) {
            $query->where('regulator', $request->string('regulator'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $deadlines = $query->orderBy('deadline_date')->paginate(20)->withQueryString();

        $deadlines->through(fn (RegulatoryDeadline $deadline) => $this->presentDeadline($deadline));

        return Inertia::render('Regulatory/Deadlines/Index', [
            'deadlines' => $deadlines,
            'filters' => $request->only(['regulator', 'status']),
            'regulators' => $this->regulators(),
            'statuses' => RegulatoryDeadline::STATUSES,
            'canFile' => Gate::allows('create', RegulatoryDeadline::class),
        ]);
    }

    public function createDeadline()
    {
        Gate::authorize('create', RegulatoryDeadline::class);

        return Inertia::render('Regulatory/Deadlines/Create', [
            'users' => $this->assignableUsers(),
            'frequencies' => StoreDeadlineRequest::FREQUENCIES,
        ]);
    }

    public function storeDeadline(StoreDeadlineRequest $request)
    {
        $deadline = RegulatoryDeadline::create(array_merge($request->validated(), [
            'organization_id' => TenantContext::organizationId(),
            'status' => 'upcoming',
        ]));

        return redirect()->route('risk.regulatory.deadlines')
            ->with('success', "Deadline \"{$deadline->title}\" added to the calendar.");
    }

    /**
     * WP-09: the circular register is the shared data grid
     * (App\Grids\Definitions\RegulatoryCircularsGrid), which owns the query,
     * the regulator/compliance filters and the pagination. Nothing on the page
     * outside the grid needs data.
     */
    public function circulars(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', RegulatoryCircular::class);

        return Inertia::render('Regulatory/Circulars', [
            'grid' => fn () => $presenter->present(GridRegistry::resolve('circulars'), $request, $request->user()),
        ]);
    }

    public function createCircular()
    {
        Gate::authorize('create', RegulatoryCircular::class);

        return Inertia::render('Regulatory/Circulars/Create', [
            'users' => $this->assignableUsers(),
            // The form now RENDERS these. createCircular() has always loaded
            // every risk in the organisation and the Blade form showed none of
            // them, so the query ran on every page load and
            // `affected_risk_ids` — a column the store path writes and the
            // model casts — could never be set through the interface.
            'risks' => Risk::where('organization_id', TenantContext::organizationId())
                ->where('status', 'active')
                ->orderBy('risk_code')
                ->get(['id', 'risk_code', 'title'])
                ->values()
                ->all(),
            'impactLevels' => StoreCircularRequest::IMPACT_LEVELS,
        ]);
    }

    public function storeCircular(StoreCircularRequest $request)
    {
        $circular = RegulatoryCircular::create(array_merge($request->validated(), [
            'organization_id' => TenantContext::organizationId(),
            'impact_level' => $request->validated('impact_level') ?? 'medium',
            'compliance_status' => 'not_assessed',
        ]));

        return redirect()->route('risk.regulatory.show-circular', $circular)
            ->with('success', "Circular {$circular->circular_ref} recorded.");
    }

    public function showCircular(RegulatoryCircular $circular)
    {
        Gate::authorize('view', $circular);

        $circular->load('assignee');

        return Inertia::render('Regulatory/Circulars/Show', [
            'circular' => array_merge($circular->only([
                'id', 'regulator', 'circular_ref', 'title', 'date_issued', 'effective_date',
                'summary', 'impact_level', 'compliance_status', 'compliance_pct', 'action_required',
            ]), [
                'assignee' => $circular->assignee?->only(['id', 'name', 'email']),
            ]),
            'affectedRisks' => $this->affectedRisks($circular),
            'statuses' => UpdateComplianceRequest::STATUSES,
            'canAssess' => Gate::allows('assessCompliance', $circular),
        ]);
    }

    public function updateCompliance(UpdateComplianceRequest $request, RegulatoryCircular $circular)
    {
        $circular->update($request->validated());

        return back()->with('success', 'Compliance status updated.');
    }

    public function submitFiling(SubmitFilingRequest $request, RegulatoryDeadline $deadline)
    {
        RegulatoryFiling::create(array_merge($request->validated(), [
            'deadline_id' => $deadline->id,
            'filed_by' => $request->user()->id,
            'status' => 'submitted',
        ]));

        $deadline->update(['status' => 'submitted']);

        return back()->with('success', "Filing recorded against \"{$deadline->title}\".");
    }

    public function taxonomyIndex()
    {
        Gate::authorize('viewAny', RiskTaxonomy::class);

        $orgId = TenantContext::organizationId();

        // The whole tree in one query, assembled in memory. The Blade version
        // eager-loaded `children.children` while its partial recursed to any
        // depth, so every node below the second level cost its own query.
        $nodes = RiskTaxonomy::where('organization_id', $orgId)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'description', 'framework', 'parent_id', 'depth']);

        return Inertia::render('Regulatory/Taxonomy', [
            'tree' => $this->taxonomyTree($nodes),
            // The parent picker. The Blade template ran this query inside the
            // view itself.
            'parentOptions' => $nodes->map(fn ($node) => [
                'id' => $node->id,
                'name' => $node->name,
                'depth' => (int) $node->depth,
            ])->values(),
            'frameworks' => RiskTaxonomy::FRAMEWORKS,
            'canManage' => Gate::allows('create', RiskTaxonomy::class),
        ]);
    }

    public function storeTaxonomy(StoreTaxonomyRequest $request)
    {
        $validated = $request->validated();

        $parent = isset($validated['parent_id'])
            ? RiskTaxonomy::find($validated['parent_id'])
            : null;

        RiskTaxonomy::create(array_merge($validated, [
            'organization_id' => TenantContext::organizationId(),
            'depth' => $parent ? $parent->depth + 1 : 0,
        ]));

        return back()->with('success', 'Taxonomy node added.');
    }

    /* ------------------------------------------------------------------ */

    /**
     * A deadline as every screen in this module lists it.
     *
     * @return array<string, mixed>
     */
    private function presentDeadline(RegulatoryDeadline $deadline): array
    {
        return array_merge($deadline->only([
            'id', 'regulator', 'report_type', 'title', 'description', 'deadline_date', 'frequency', 'status',
        ]), [
            'is_overdue' => $deadline->isOverdue(),
            'responsible' => $deadline->responsible?->only(['id', 'name']),
        ]);
    }

    /**
     * The risks a circular names, resolved for display.
     *
     * `affected_risk_ids` is a json array of ids; the show page listed nothing
     * from it.
     *
     * @return list<mixed>
     */
    private function affectedRisks(RegulatoryCircular $circular): array
    {
        $ids = array_filter((array) ($circular->affected_risk_ids ?? []));

        if ($ids === []) {
            return [];
        }

        return Risk::where('organization_id', $circular->organization_id)
            ->whereIn('id', $ids)
            ->orderBy('risk_code')
            ->get(['id', 'risk_code', 'title'])
            ->values()
            ->all();
    }

    /** @return list<mixed> */
    private function assignableUsers(): array
    {
        return User::where('organization_id', TenantContext::organizationId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->values()
            ->all();
    }

    /** The regulators this organisation's own calendar names. @return list<string> */
    private function regulators(): array
    {
        return RegulatoryDeadline::where('organization_id', TenantContext::organizationId())
            ->distinct()->orderBy('regulator')->pluck('regulator')->all();
    }

    /**
     * Nest a flat node list into a tree.
     *
     * @param  \Illuminate\Support\Collection<int, RiskTaxonomy>  $nodes
     * @return list<array<string, mixed>>
     */
    private function taxonomyTree(\Illuminate\Support\Collection $nodes): array
    {
        $byParent = $nodes->groupBy(fn ($node) => $node->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())
                ->map(fn ($node) => [
                    'id' => $node->id,
                    'name' => $node->name,
                    'description' => $node->description,
                    'framework' => $node->framework,
                    'children' => $build($node->id),
                ])->values()->all();
        };

        return $build(0);
    }
}
