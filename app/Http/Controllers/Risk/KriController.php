<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kri\AcknowledgeBreachRequest;
use App\Http\Requests\Kri\RecordKriMeasurementRequest;
use App\Http\Requests\Kri\ResolveBreachRequest;
use App\Http\Requests\Kri\StoreKriRequest;
use App\Http\Requests\Kri\UpdateKriRequest;
use App\Http\Requests\Kri\UpdateKriThresholdsRequest;
use App\Models\BusinessUnit;
use App\Models\KeyRiskIndicator;
use App\Models\MeasureBreach;
use App\Models\MeasureValue;
use App\Models\Risk;
use App\Models\User;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Presenters\Kri\KriLibraryPresenter;
use App\Presenters\Kri\KriReturnPresenter;
use App\Services\AuditTrailService;
use App\Services\Kri\KriRedBandService;
use App\Services\Kri\KriService;
use App\Services\Kri\RedBandExplanation;
use App\Services\KriMeasureBridge;
use App\Services\MeasureService;
use App\Services\PeriodService;
use App\Support\Kri\KriBandRule;
use App\Support\Kri\KriWorkingCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Key risk indicators (migration Phase 4.1).
 *
 * Figures come from KriService, authorisation from KeyRiskIndicatorPolicy, and
 * every write body from a Form Request in App\Http\Requests\Kri. The node-scope
 * checks the trait used to make are the policy's job now, so EnforcesNodeScope
 * and its six hand-written organization_id comparisons are gone.
 */
class KriController extends Controller
{
    // WP-05 TASK 2 — receives the fields a tenant added through the builder.
    use PersistsConfiguredAttributes;

    private const OBJECT_TYPE = 'KeyRiskIndicator';

    public function __construct(
        private readonly KriService $kris,
        private readonly FormSchemaPresenter $schemas,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    public function dashboard(Request $request, KriReturnPresenter $returns)
    {
        Gate::authorize('viewAny', KeyRiskIndicator::class);

        $kris = $this->kris->trafficLights();

        return Inertia::render('Kri/Dashboard', [
            'kpis' => $this->kris->kpis($kris),
            'statusDistribution' => $this->kris->statusDistribution($kris),
            'breachTrend' => $this->kris->breachTrend(),
            'breaching' => $this->kris->breaching($kris),
            'trafficLights' => $kris->map(fn (KeyRiskIndicator $kri) => [
                'id' => $kri->id,
                'code' => $kri->kri_code,
                'name' => $kri->name,
                'status' => $kri->current_status,
                'value' => $kri->current_value,
                'unit' => $kri->unit_of_measure,
                'trend' => $kri->trend_direction,
                'url' => route('risk.kri.show', $kri),
            ])->all(),
            'canCreate' => Gate::allows('create', KeyRiskIndicator::class),
            // ADR 0026 section 16. A closure, so a partial reload with
            // `only: ['returnsCard']` and `?period=` re-computes just this.
            'returnsCard' => fn () => $returns->card($request->user(), $request->integer('period') ?: null),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  List / breach register */
    /* ------------------------------------------------------------------ */

    public function index(Request $request, GridPresenter $presenter, KriLibraryPresenter $library)
    {
        // ADR 0026 section 15: departments by default; the flat grid for
        // `?layout=flat` or any grid-state parameter, so every existing link that
        // carries grid state keeps working.
        $gridState = ['search', 'filters', 'sort', 'direction', 'dir', 'page', 'per_page', 'view', 'columns'];

        if ($request->query('layout') !== 'flat' && ! $request->hasAny($gridState)) {
            return Inertia::render('Kri/Index', $library->landing($request->user()));
        }

        // WP-09: search, filters, sorting and export live inside the shared
        // data grid (App\Grids\Definitions\KrisGrid); the controller computes
        // only what the page header still needs.
        // WP-00: scoped like KrisGrid, so the header total counts the rows the
        // grid beneath it will show.
        $scoped = fn () => KeyRiskIndicator::where('organization_id', TenantContext::organizationId())->visibleTo();

        return Inertia::render('Kri/Index', [
            'layout' => 'flat',
            'total' => $scoped()->count(),
            'activeBreachCount' => $scoped()->where('current_status', 'red')->count(),
            'grid' => fn () => $presenter->present(GridRegistry::resolve('kris'), $request, $request->user()),
        ]);
    }

    /**
     * The breach REGISTER, not a filtered list of readings. The table itself —
     * search, filters, sorting, bulk acknowledge/resolve, export — lives inside
     * KriBreachesGrid, which this action opens with status=active so the
     * default stays the work list.
     */
    public function breaches(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', KeyRiskIndicator::class);

        if (! $request->has('filters') && ! $request->filled('view')) {
            $request->query->set('filters', ['status' => 'active']);
        }

        $orgId = TenantContext::organizationId();
        $open = MeasureBreach::query()->where('organization_id', $orgId)->open();

        $openBreaches = (clone $open)->get(['breached_at']);
        $mttrHours = MeasureBreach::meanTimeToResolveHours(
            MeasureBreach::query()->where('organization_id', $orgId)
        );

        return Inertia::render('Kri/Breaches', [
            'activeBreaches' => (clone $open)->count(),
            'redBreaches' => (clone $open)->where('band_to', 'red')->count(),
            'amberBreaches' => (clone $open)->where('band_to', 'amber')->count(),
            'avgDaysInBreach' => $openBreaches->isEmpty()
                ? 0
                : (int) round($openBreaches->avg(fn (MeasureBreach $breach) => abs(now()->diffInDays($breach->breached_at)))),
            'mttrDays' => $mttrHours === null ? null : number_format($mttrHours / 24, 1),
            'unacknowledged' => (clone $open)->where('measure_breaches.status', 'open')->count(),
            'grid' => fn () => $presenter->present(GridRegistry::resolve('kri_breaches'), $request, $request->user()),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create / store */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        Gate::authorize('create', KeyRiskIndicator::class);

        return Inertia::render('Kri/Create', array_merge($this->formOptions(), [
            'departments' => $this->departmentOptions(),
            'schema' => $this->schemas->configuredOnly($this->schemas->form(self::OBJECT_TYPE)),
            // "New KRI" on a risk's KRIs tab links here with ?risk_id; the
            // form selects it only if it is one of the risks it offers.
            'riskId' => $request->integer('risk_id') ?: null,
        ]));
    }

    public function store(StoreKriRequest $request)
    {
        $kri = $this->kris->create($request->validated(), $request->user()?->id);

        $this->saveConfiguredAttributes($request, $kri);

        return redirect()->route('risk.kri.show', $kri)
            ->with('success', "KRI {$kri->kri_code} has been created.");
    }

    /* ------------------------------------------------------------------ */
    /*  Show / edit / update / destroy */
    /* ------------------------------------------------------------------ */

    public function show(KeyRiskIndicator $kri, KriRedBandService $redBand, KriBandRule $bandRule, KriMeasureBridge $bridge, PeriodService $periods)
    {
        Gate::authorize('view', $kri);

        $kri->load(['risk', 'owner']);
        $readings = $this->kris->readings($kri);
        $redInfo = $this->redReadingInfo($kri, collect($readings->items()));
        $defaultPeriod = $periods->resolve(KriWorkingCalendar::today(), $bridge->periodTypeFor($kri), TenantContext::organizationId());

        return Inertia::render('Kri/Show', [
            'kri' => $this->detail($kri),
            'history' => $this->kris->history($kri),
            'readings' => [
                'data' => collect($readings->items())->map(fn (MeasureValue $value) => [
                    'id' => $value->id,
                    'period' => $value->period?->name,
                    'value' => $value->value === null ? null : (float) $value->value,
                    'band' => $value->rag_band,
                    'enteredBy' => $value->enteredBy?->name,
                    'recordedAt' => $value->entered_at?->format('d M Y, H:i'),
                    'root_cause' => $redInfo[$value->period_id]['root_cause'] ?? null,
                    'issue' => $redInfo[$value->period_id]['issue'] ?? null,
                ])->all(),
                'links' => $readings->linkCollection()->toArray(),
                'meta' => [
                    'from' => $readings->firstItem(),
                    'to' => $readings->lastItem(),
                    'total' => $readings->total(),
                ],
            ],
            'breaches' => $this->kris->breachesFor($kri)->map(fn (MeasureBreach $breach) => [
                'id' => $breach->id,
                'period' => $breach->period?->name,
                'from' => $breach->band_from,
                'to' => $breach->band_to,
                'status' => $breach->status,
                'breachedAt' => $breach->breached_at?->format('d M Y'),
                'acknowledgedBy' => $breach->acknowledgedBy?->name,
            ])->all(),
            'bandRule' => KriBandRule::withPeriod($bandRule->forReading($kri, $defaultPeriod), $defaultPeriod),
            'openKriIssue' => $this->openIssuePayload($redBand->openIssueFor($kri)),
            'ownerOptions' => $this->userOptions(),
            'redBandDefaults' => ['owner_id' => $kri->kri_owner_id ?? $kri->owner_id],
            'can' => [
                'update' => Gate::allows('update', $kri),
                'delete' => Gate::allows('delete', $kri),
                'recordMeasurement' => Gate::allows('recordMeasurement', $kri),
                'acknowledgeBreach' => $kri->id && Gate::allows('kri.acknowledge_breach'),
            ],
        ]);
    }

    public function edit(KeyRiskIndicator $kri, KriRedBandService $redBand, KriBandRule $bandRule)
    {
        Gate::authorize('update', $kri);

        $formDirection = $kri->threshold_direction === 'lower_worse' ? 'lower_is_worse' : 'higher_is_worse';
        $breach = $this->latestOpenRedBreach($kri);

        return Inertia::render('Kri/Edit', array_merge($this->formOptions(), [
            'departments' => $this->departmentOptions(),
            'bandRule' => $bandRule->forBoundaries($formDirection, $kri->green_threshold, $kri->red_threshold),
            'openKriIssue' => $this->openIssuePayload($redBand->openIssueFor($kri)),
            'openRedBreach' => $breach === null ? null : [
                'id' => (int) $breach->id,
                'period_label' => $breach->period?->name,
                'root_cause' => $breach->root_cause,
            ],
            'redBandDefaults' => ['owner_id' => $kri->kri_owner_id ?? $kri->owner_id],
            'kri' => [
                'id' => $kri->id,
                'code' => $kri->kri_code,
                'kri_name' => $kri->name,
                'description' => $kri->description,
                'measurement_unit' => $kri->unit_of_measure,
                'measurement_frequency' => $kri->measurement_frequency,
                'data_source' => $kri->data_source,
                'kri_owner_id' => $kri->owner_id,
                'target_value' => $kri->target_value,
                'formula' => $kri->formula,
                'is_active' => (bool) $kri->is_active,
                'business_unit_id' => $kri->business_unit_id,
                'direction' => $kri->threshold_direction === 'lower_worse' ? 'lower_is_worse' : 'higher_is_worse',
                'green_threshold' => $kri->green_threshold,
                'red_threshold' => $kri->red_threshold,
            ],
            'schema' => $this->schemas->configuredOnly($this->schemas->form(self::OBJECT_TYPE, $kri, omit: ['risk_id'])),
        ]));
    }

    public function update(UpdateKriRequest $request, KeyRiskIndicator $kri, KriBandRule $bands, KriRedBandService $redBand)
    {
        $validated = $request->validated();

        // The KRI update and the explanation share ONE transaction (ADR 0026 6.3).
        MeasureService::deferringAnnouncements(fn () => DB::transaction(function () use ($kri, $validated, $request, $bands, $redBand) {
            $this->kris->update($kri, $validated, $request->user()?->id);

            $target = $validated['target_value'] ?? null;
            $explanation = RedBandExplanation::fromPayload($validated['red_band'] ?? null);

            if ($explanation !== null && $target !== null && $bands->isRed(
                $bands->forBoundaries((string) $validated['direction'], $validated['green_threshold'] ?? null, $validated['red_threshold'] ?? null),
                (float) $target,
            )) {
                $redBand->applyToTarget($kri, $explanation, $request->user());
            }
        }));

        $this->saveConfiguredAttributes($request, $kri);

        return redirect()->route('risk.kri.show', $kri)
            ->with('success', "KRI {$kri->kri_code} has been updated.");
    }

    public function destroy(KeyRiskIndicator $kri)
    {
        Gate::authorize('delete', $kri);

        $code = $kri->kri_code;
        $kri->delete();

        return redirect()->route('risk.kri.index')->with('success', "KRI {$code} has been deleted.");
    }

    /* ------------------------------------------------------------------ */
    /*  Measurements */
    /* ------------------------------------------------------------------ */

    public function recordMeasurement(RecordKriMeasurementRequest $request, KeyRiskIndicator $kri)
    {
        $validated = $request->validated();

        $result = MeasureService::deferringAnnouncements(fn () => DB::transaction(function () use ($kri, $validated, $request) {
            $result = $this->kris->recordMeasurement($kri, $validated, $request->user()?->id);

            $explanation = RedBandExplanation::fromPayload($validated['red_band'] ?? null);

            if ($result['band'] === 'red' && $explanation !== null) {
                app(KriRedBandService::class)->applyToReading($kri, $result['period'], $result['value'], $explanation, $request->user());
            }

            return $result;
        }));

        $band = $result['band'];
        $message = "Measurement recorded for {$result['period']->name}.";

        if ($band !== null) {
            $message .= ' Status: '.strtoupper($band).'.';
        }

        if ($result['breach'] !== null && $result['breach']->wasRecentlyCreated) {
            $message .= ' A breach has been opened for acknowledgement.';
        }

        return back()->with($band === 'red' ? 'warning' : 'success', $message);
    }

    /* ------------------------------------------------------------------ */
    /*  Thresholds */
    /* ------------------------------------------------------------------ */

    public function thresholds(Request $request)
    {
        Gate::authorize('viewAny', KeyRiskIndicator::class);

        $kris = KeyRiskIndicator::where('organization_id', TenantContext::organizationId())
            ->with('risk')
            ->orderBy('kri_code')
            ->paginate(25);

        return Inertia::render('Kri/Thresholds', [
            'kris' => [
                'data' => collect($kris->items())->map(fn (KeyRiskIndicator $kri) => [
                    'id' => $kri->id,
                    'code' => $kri->kri_code,
                    'name' => $kri->name,
                    'unit' => $kri->unit_of_measure,
                    'status' => $kri->current_status,
                    'currentValue' => $kri->current_value === null ? null : (float) $kri->current_value,
                    'direction' => $kri->threshold_direction === 'lower_worse' ? 'lower_is_worse' : 'higher_is_worse',
                    'green_threshold' => $kri->green_threshold,
                    'red_threshold' => $kri->red_threshold,
                    'riskCode' => $kri->risk?->risk_code,
                ])->all(),
                'links' => $kris->linkCollection()->toArray(),
                'meta' => ['from' => $kris->firstItem(), 'to' => $kris->lastItem(), 'total' => $kris->total()],
            ],
            'canManage' => Gate::allows('manageThresholds', KeyRiskIndicator::class),
        ]);
    }

    public function updateThresholds(UpdateKriThresholdsRequest $request)
    {
        $changed = $this->kris->updateThresholds($request->validated()['kris']);

        return redirect()->route('risk.kri.thresholds')->with(
            'success',
            $changed === 0
                ? 'No threshold changes to save.'
                : $changed.' '.($changed === 1 ? 'indicator' : 'indicators').' updated.',
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Breach lifecycle */
    /* ------------------------------------------------------------------ */

    public function acknowledgeBreach(AcknowledgeBreachRequest $request, MeasureBreach $breach)
    {
        if ($breach->status !== 'open') {
            return back()->with('error', 'Only an open breach can be acknowledged.');
        }

        $validated = $request->validated();

        $breach->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
            'note' => $validated['note'] ?? $breach->note,
            'root_cause' => $validated['root_cause'] ?? $breach->root_cause,
        ]);

        AuditTrailService::record($breach, 'breach_acknowledged', 'status', 'open', 'acknowledged');

        return back()->with('success', 'Breach acknowledged.');
    }

    public function resolveBreach(ResolveBreachRequest $request, MeasureBreach $breach)
    {
        if (in_array($breach->status, ['resolved', 'false_positive'], true)) {
            return back()->with('error', 'This breach is already closed.');
        }

        $validated = $request->validated();
        $previous = $breach->status;

        $breach->update([
            'status' => $validated['outcome'],
            'resolved_at' => now(),
            'root_cause' => $validated['root_cause'] ?? $breach->root_cause,
            'note' => $validated['note'] ?? $breach->note,
        ]);

        AuditTrailService::record($breach, 'breach_closed', 'status', $previous, $validated['outcome']);

        return back()->with('success', 'Breach closed.');
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation */
    /* ------------------------------------------------------------------ */

    /** @return list<array{id: int, name: string}> */
    private function departmentOptions(): array
    {
        return BusinessUnit::query()
            ->where('organization_id', TenantContext::organizationId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BusinessUnit $unit) => ['id' => (int) $unit->id, 'name' => $unit->name])
            ->all();
    }

    /** @return list<array{id: int, name: string}> */
    private function userOptions(): array
    {
        return User::where('organization_id', TenantContext::organizationId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => $user->name])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function openIssuePayload(?\App\Models\Issue $issue): ?array
    {
        return $issue === null ? null : [
            'id' => (int) $issue->id,
            'reference' => $issue->issue_reference,
            'status' => $issue->issue_status,
            'due_date' => $issue->remediation_due_date?->toDateString(),
            'action_plan' => $issue->action_plan,
            'observation' => $issue->observation,
            'responsible_owner_id' => $issue->responsible_owner_id,
            'url' => Gate::allows('view', $issue) ? route('risk.issues.show', $issue) : null,
        ];
    }

    private function latestOpenRedBreach(KeyRiskIndicator $kri): ?MeasureBreach
    {
        $measure = app(KriMeasureBridge::class)->measureFor($kri);

        return $measure === null ? null : MeasureBreach::query()
            ->where('measure_id', $measure->id)
            ->where('object_id', app(\App\Services\MeasureService::class)->objectIdFor($kri))
            ->where('band_to', 'red')
            ->whereIn('status', ['open', 'acknowledged'])
            ->with('period')
            ->orderByDesc('breached_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Root cause and Issue for the red readings on a page: one breach query,
     * keyed by period.
     *
     * @param  \Illuminate\Support\Collection<int, MeasureValue>  $values
     * @return array<int, array{root_cause: ?string, issue: ?array<string, mixed>}>
     */
    private function redReadingInfo(KeyRiskIndicator $kri, $values): array
    {
        $periodIds = $values->where('rag_band', 'red')->pluck('period_id')->unique()->all();
        $measure = app(KriMeasureBridge::class)->measureFor($kri);

        if ($periodIds === [] || $measure === null) {
            return [];
        }

        return MeasureBreach::query()
            ->where('measure_id', $measure->id)
            ->where('object_id', app(\App\Services\MeasureService::class)->objectIdFor($kri))
            ->where('band_to', 'red')
            ->whereIn('period_id', $periodIds)
            ->with('linkedIssue')
            ->orderBy('id')
            ->get()
            ->keyBy('period_id')
            ->map(fn (MeasureBreach $breach) => [
                'root_cause' => $breach->root_cause,
                'issue' => $breach->linkedIssue === null ? null : [
                    'reference' => $breach->linkedIssue->issue_reference,
                    'status' => $breach->linkedIssue->issue_status,
                    'due_date' => $breach->linkedIssue->remediation_due_date?->toDateString(),
                    'url' => Gate::allows('view', $breach->linkedIssue) ? route('risk.issues.show', $breach->linkedIssue) : null,
                ],
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'risks' => Risk::where('organization_id', $orgId)
                ->orderBy('risk_code')
                ->get()
                ->map(fn (Risk $risk) => ['id' => $risk->id, 'code' => $risk->risk_code, 'title' => $risk->title])
                ->all(),
            'users' => User::where('organization_id', $orgId)
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->all(),
            'frequencies' => KeyRiskIndicator::FREQUENCIES,
            'directions' => KeyRiskIndicator::DIRECTIONS,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(KeyRiskIndicator $kri): array
    {
        return [
            'id' => $kri->id,
            'code' => $kri->kri_code,
            'name' => $kri->name,
            'description' => $kri->description,
            'unit' => $kri->unit_of_measure,
            'frequency' => $kri->measurement_frequency,
            'dataSource' => $kri->data_source,
            'formula' => $kri->formula,
            'status' => $kri->current_status,
            'currentValue' => $kri->current_value === null ? null : (float) $kri->current_value,
            'targetValue' => $kri->target_value === null ? null : (float) $kri->target_value,
            'trend' => $kri->trend_direction,
            'isActive' => (bool) $kri->is_active,
            'direction' => $kri->threshold_direction,
            'greenThreshold' => $kri->green_threshold,
            'redThreshold' => $kri->red_threshold,
            'owner' => $kri->owner?->name,
            'risk' => $kri->risk ? [
                'code' => $kri->risk->risk_code,
                'title' => $kri->risk->title,
                'url' => route('risk.register.show', $kri->risk),
            ] : null,
        ];
    }
}
