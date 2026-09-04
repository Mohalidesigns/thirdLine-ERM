<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Register\MapControlRequest;
use App\Http\Requests\Register\StoreRiskRequest;
use App\Http\Requests\Register\UpdateRiskAttributesRequest;
use App\Http\Requests\Register\UpdateRiskRequest;
use App\Models\Period;
use App\Models\Risk;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Repositories\RiskRepository;
use App\Services\Register\RiskRegisterService;
use App\Support\Authorization\GraphScope;
use App\Support\Periods\PeriodContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The risk register (migration Phase 3.2). Each action authorises through
 * RiskPolicy, hands the work to RiskRegisterService, and renders a page.
 */
class RiskRegisterController extends Controller
{
    use PersistsConfiguredAttributes;

    public function __construct(
        private readonly RiskRegisterService $risks,
        private readonly FormSchemaPresenter $schemas,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index */
    /* ------------------------------------------------------------------ */

    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Risk::class);

        $orgId = TenantContext::organizationId();

        // WP-04: the register is an "as at" view. When the top bar is on a
        // period that has already ended, the scores come from the measure
        // engine as they stood at that period's close rather than from the
        // denormalised current columns.
        $selectedPeriod = PeriodContext::current();

        if ($selectedPeriod !== null && $selectedPeriod->end_date?->isPast()) {
            return $this->historicIndex($request, $selectedPeriod);
        }

        // WP-09: filtering, search, sorting and pagination live in the shared
        // data grid (App\Grids\Definitions\RisksGrid). The controller only
        // computes what the page header still needs: the total and the
        // quick-filter pill counts. The pills filter on residual_rating — the
        // same column the grid's rating filter targets — so a pill's count
        // always matches the rows it reveals.
        // WP-00: ->visibleTo() here as well as in RisksGrid, and for a reason
        // beyond tidiness — a header that says "14 Critical" above a grid
        // listing three of them tells a branch user exactly how many critical
        // risks the rest of the group is carrying. The count and the rows it
        // labels have to be filtered by the same rule.
        $ratingCounts = Risk::where('organization_id', $orgId)
            ->visibleTo()
            ->whereIn('residual_rating', ['Critical', 'High', 'Medium', 'Low'])
            ->selectRaw('residual_rating, COUNT(*) as aggregate')
            ->groupBy('residual_rating')
            ->pluck('aggregate', 'residual_rating');

        $ratingCounts = collect(['Critical', 'High', 'Medium', 'Low'])
            ->mapWithKeys(fn ($rating) => [$rating => (int) ($ratingCounts[$rating] ?? 0)])
            ->all();

        return Inertia::render('Register/Index', [
            'total' => Risk::where('organization_id', $orgId)->visibleTo()->count(),
            'ratingCounts' => $ratingCounts,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('risks'), $request, $request->user()),
        ]);
    }

    /**
     * The register as it stood at the close of a period that has ended.
     *
     * Built from the measure engine rather than from `risks`, so the scores,
     * ratings and exposures are the ones that were approved at the time — and
     * a risk identified after the period is absent rather than showing with a
     * score it did not then have.
     *
     * Sorting and paging happen in memory here, which is the trade for reading
     * a historic view: the sort key is a value that exists only after the
     * overlay. The register is bounded by the organisation's risk count, and
     * the underlying read is the single indexed query in RiskRepository::asOf.
     */
    private function historicIndex(Request $request, Period $period)
    {
        $orgId = TenantContext::organizationId();

        $filters = array_filter([
            'category_id' => $request->input('category'),
            'status' => $request->input('status'),
            'business_unit_id' => $request->input('business_unit'),
        ], fn ($value) => $value !== null && $value !== '');

        $risks = app(RiskRepository::class)->asOf($period, $filters, $orgId);

        // WP-00 node scoping, applied here rather than inside RiskRepository.
        // asOf() is shared with the dashboard widget resolvers (heat map,
        // trend, stacked area), which are roll-ups that must keep reading the
        // whole organization — narrowing the repository would quietly re-cut
        // the CRO's board pack to whoever happened to open it.
        //
        // The as-at register is a caller-facing list, so it is scoped at the
        // caller. The visible set is resolved with the same visibleTo() the
        // live grid uses, over just the ids asOf() returned, so the historic
        // and live views of the register never disagree about who may see what.
        if (GraphScope::isSubtreeLimited($request->user()) && $risks->isNotEmpty()) {
            $visible = Risk::query()
                ->whereKey($risks->pluck('id')->all())
                ->visibleTo()
                ->pluck('id')
                ->flip();

            $risks = $risks->filter(fn (Risk $risk) => $visible->has($risk->id))->values();
        }

        if ($request->filled('rating')) {
            $rating = $request->input('rating');
            $risks = $risks->filter(fn (Risk $risk) => $risk->inherent_rating === $rating)->values();
        }

        // WP-08: heat-map cell drill-through, against the as-at overlay values.
        foreach (['residual_likelihood', 'residual_impact', 'inherent_likelihood', 'inherent_impact'] as $cell) {
            if ($request->filled($cell)) {
                $value = (int) $request->input($cell);
                $risks = $risks->filter(fn (Risk $risk) => (int) $risk->{$cell} === $value)->values();
            }
        }

        if ($request->filled('search')) {
            $search = mb_strtolower($request->input('search'));
            $risks = $risks->filter(fn (Risk $risk) => str_contains(mb_strtolower((string) $risk->risk_code), $search)
                || str_contains(mb_strtolower((string) $risk->title), $search)
                || str_contains(mb_strtolower((string) $risk->description), $search)
            )->values();
        }

        $sortBy = $request->get('sort', 'inherent_score');
        $allowedSorts = ['risk_code', 'title', 'inherent_score', 'residual_score', 'status', 'created_at'];

        if (in_array($sortBy, $allowedSorts, true)) {
            $risks = $request->get('direction', 'desc') === 'asc'
                ? $risks->sortBy($sortBy)->values()
                : $risks->sortByDesc($sortBy)->values();
        }

        $perPage = 25;
        $page = max(1, (int) $request->input('page', 1));

        $paginated = new LengthAwarePaginator(
            $risks->forPage($page, $perPage)->values()->map(fn (Risk $risk) => [
                'id' => $risk->id,
                'risk_code' => $risk->risk_code,
                'title' => $risk->title,
                'category' => $risk->category?->name,
                'business_unit' => $risk->businessUnit?->name,
                'risk_owner' => $risk->riskOwner?->name,
                'inherent_score' => $risk->inherent_score,
                'inherent_rating' => $risk->inherent_rating,
                'residual_score' => $risk->residual_score,
                'residual_rating' => $risk->residual_rating,
                'status' => $risk->status,
            ]),
            $risks->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $options = $this->risks->formOptions();

        return Inertia::render('Register/Historic', [
            'risks' => $paginated->toArray(),
            'categories' => $options['categories'],
            'businessUnits' => $options['businessUnits'],
            'asOfPeriod' => [
                'id' => $period->id,
                'name' => $period->name,
                'end_date' => optional($period->end_date)->toDateString(),
            ],
            'filters' => [
                'category' => $request->input('category'),
                'status' => $request->input('status'),
                'business_unit' => $request->input('business_unit'),
                'search' => $request->input('search'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create / Store */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        Gate::authorize('create', Risk::class);

        return Inertia::render('Register/Create', array_merge(
            $this->risks->formOptions(),
            [
                'schema' => $this->configuredOnly($this->schemas->form('Risk')),
                // The AI Risk Statement Builder posts to a route behind
                // `feature:ai_intelligence` + `permission:ai.view`. The Blade
                // page drew the card unconditionally, so a tenant without the
                // feature got a button that 404'd.
                'canDraftWithAi' => config('features.ai_intelligence')
                    && ($request->user()?->can('ai.view') ?? false),
            ],
        ));
    }

    public function store(StoreRiskRequest $request)
    {
        $risk = $this->risks->create(
            $request->validated(),
            TenantContext::organizationId(),
            $request->user()?->id,
        );

        $this->saveConfiguredAttributes($request, $risk, 'Risk');

        return redirect()
            ->route('risk.register.show', $risk)
            ->with('success', "Risk {$risk->risk_code} has been created successfully.");
    }

    /* ------------------------------------------------------------------ */
    /*  Show */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, Risk $register)
    {
        Gate::authorize('view', $register);

        $user = $request->user();

        return Inertia::render('Register/Show', array_merge(
            $this->risks->detail($register),
            [
                'configured' => $this->configuredOnly($this->schemas->form('Risk', $register)),
                'configuredDetail' => $this->schemas->detail($register, 'Risk', omit: self::DETAIL_OMIT, hideEmpty: true),
                'can' => [
                    'update' => $user->can('update', $register),
                    'delete' => $user->can('delete', $register),
                    'mapControl' => $user->can('mapControl', $register),
                ],
            ],
        ));
    }

    /**
     * Codes the detail page already draws by hand, so the "Additional
     * Information" card does not repeat them. The Attributes tab remains the
     * place configured fields are edited.
     *
     * @var list<string>
     */
    private const DETAIL_OMIT = [
        // Risk Summary.
        'risk_code', 'title', 'description', 'risk_owner_id',
        'category_id', 'business_unit_id', 'entity_id', 'date_identified',
        // Key Metrics and the KPI cards.
        'inherent_score', 'inherent_rating', 'inherent_likelihood', 'inherent_impact',
        'residual_score', 'residual_rating', 'residual_likelihood', 'residual_impact',
        'control_effectiveness_pct', 'treatment_strategy', 'status',
        // Impact Dimensions.
        'inherent_impact_financial', 'inherent_impact_operational',
        'inherent_impact_reputational', 'inherent_impact_regulatory',
        // Additional Details.
        'risk_source', 'risk_velocity', 'review_frequency',
        'financial_exposure_ngn', 'regulatory_mapping',
    ];

    /* ------------------------------------------------------------------ */
    /*  Edit / Update */
    /* ------------------------------------------------------------------ */

    public function edit(Risk $register)
    {
        Gate::authorize('update', $register);

        return Inertia::render('Register/Edit', array_merge(
            $this->risks->formOptions(),
            [
                'risk' => $this->risks->present($register->load(['category', 'businessUnit', 'riskOwner'])),
                'schema' => $this->configuredOnly($this->schemas->form('Risk', $register)),
            ],
        ));
    }

    public function update(UpdateRiskRequest $request, Risk $register)
    {
        $risk = $this->risks->update($register, $request->validated(), $request->user()?->id);

        $this->saveConfiguredAttributes($request, $risk, 'Risk');

        return redirect()
            ->route('risk.register.show', $risk)
            ->with('success', "Risk {$risk->risk_code} has been updated successfully.");
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    public function destroy(Request $request, Risk $register)
    {
        Gate::authorize('delete', $register);

        $code = $register->risk_code;

        $this->risks->delete($register, $request->user()?->id);

        return redirect()
            ->route('risk.register.index')
            ->with('success', "Risk {$code} has been deleted.");
    }

    /**
     * The Attributes tab: only the tenant-configured fields, none of the
     * columns the bespoke form owns.
     */
    public function updateAttributes(UpdateRiskAttributesRequest $request, Risk $register)
    {
        $this->saveConfiguredAttributes($request, $register, 'Risk');

        return redirect()
            ->route('risk.register.show', $register)
            ->with('success', "Attributes for {$register->risk_code} have been saved.");
    }

    /**
     * The tenant-configured fields only. A column-backed attribute is already
     * an input on the bespoke form above; rendering the schema's copy as well
     * would put two controls on one column — the "attribute appears twice"
     * complaint from Wave 2.
     *
     * @param  array{objectType: mixed, sections: list<array{code:string,label:string,fields:list<array<string,mixed>>}>}  $schema
     * @return array{objectType: mixed, sections: list<array{code:string,label:string,fields:list<array<string,mixed>>}>}
     */
    private function configuredOnly(array $schema): array
    {
        $sections = [];

        foreach ($schema['sections'] as $section) {
            $fields = array_values(array_filter($section['fields'], fn (array $field) => ! $field['mapped']));

            if ($fields !== []) {
                $sections[] = array_merge($section, ['fields' => $fields]);
            }
        }

        return ['objectType' => $schema['objectType'], 'sections' => $sections];
    }

    /* ------------------------------------------------------------------ */
    /*  Map an existing control (inline form on the Controls tab) */
    /* ------------------------------------------------------------------ */

    /**
     * The route parameter is `{register}`, so the method parameter has to be
     * `$register` for the model to bind. It was `$risk`, which bound nothing:
     * the action received an empty Risk whose organization_id was null, failed
     * the tenancy check on the next line and returned 403 to every caller.
     * Mapping a control from the detail page has never worked.
     */
    public function mapControl(MapControlRequest $request, Risk $register)
    {
        ['control' => $control, 'mapped' => $mapped] = $this->risks->mapControl($register, $request->validated());

        return redirect()
            ->route('risk.register.show', $register)
            ->with(
                $mapped ? 'success' : 'error',
                $mapped
                    ? "Control {$control->control_code} mapped successfully."
                    : "{$control->control_code} is already mapped to this risk.",
            );
    }
}
