<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Process\StoreProcessRequest;
use App\Http\Requests\Scoping\AttachLibraryRisksToEntityRequest;
use App\Http\Requests\Scoping\AttachProcessToEntityRequest;
use App\Http\Requests\Scoping\StoreEntityRequest;
use App\Http\Requests\Scoping\UpdateEntityRequest;
use App\Models\BusinessProcess;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Risk;
use App\Policies\EntityPolicy;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Presenters\ProcessPresenter;
use App\Services\ProcessService;
use App\Services\RiskLibraryService;
use App\Services\Scoping\EntityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Scoping / entities (migration Phase 3.1). Each action authorises through
 * EntityPolicy, hands the work to EntityService, and renders a page.
 */
class ScopingController extends Controller
{
    use PersistsConfiguredAttributes;

    public function __construct(
        private readonly EntityService $entities,
        private readonly FormSchemaPresenter $schemas,
        private readonly ProcessPresenter $processPresenter,
        private readonly ProcessService $processes,
        private readonly RiskLibraryService $riskLibrary,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Dashboard */
    /* ------------------------------------------------------------------ */

    public function dashboard(Request $request)
    {
        Gate::authorize('viewAny', Entity::class);

        return Inertia::render('Scoping/Dashboard', $this->entities->dashboard($request->user()));
    }

    /* ------------------------------------------------------------------ */
    /*  Index (Entity Register) */
    /* ------------------------------------------------------------------ */

    /**
     * Entity register. Search, filters, sorting and pagination all moved
     * into the shared data grid (WP-09) — see
     * App\Grids\Definitions\EntitiesGrid. The controller now only feeds
     * the header count and the quick-filter pill row.
     */
    public function index(Request $request, GridPresenter $presenter)
    {
        Gate::authorize('viewAny', Entity::class);

        $orgId = TenantContext::organizationId();

        $entityTypes = EntityType::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Type counts for quick-filter pills
        $typeCounts = Entity::where('organization_id', $orgId)
            ->selectRaw('entity_type_id, COUNT(*) as count')
            ->groupBy('entity_type_id')
            ->pluck('count', 'entity_type_id');

        $totalCount = Entity::where('organization_id', $orgId)->count();

        // Migration Phase 2 — the pilot grid flip. The grid prop is a closure
        // so a partial reload (`only: ['grid']`) re-presents the grid without
        // recomputing the pill counts.
        return Inertia::render('Scoping/Index', [
            'entityTypes' => $entityTypes->map(fn (EntityType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'count' => (int) ($typeCounts[$type->id] ?? 0),
            ])->values()->all(),
            'totalCount' => $totalCount,
            'grid' => fn () => $presenter->present(GridRegistry::resolve('entities'), $request, $request->user()),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create / Store */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        Gate::authorize('create', Entity::class);

        return Inertia::render('Scoping/Create', array_merge(
            $this->entities->formOptions($request->user()),
            ['schemas' => $this->schemasByEntityType()],
        ));
    }

    public function store(StoreEntityRequest $request)
    {
        $entity = $this->entities->create($request->validated(), $request->user());

        $this->saveConfiguredAttributes($request, $entity, StoreEntityRequest::objectTypeCodeFor($entity->entityType));

        return redirect()
            ->route('risk.scoping.show', $entity)
            ->with('success', "Entity {$entity->entity_code} — {$entity->name} has been created successfully.");
    }

    /* ------------------------------------------------------------------ */
    /*  Show */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, Entity $scoping)
    {
        Gate::authorize('view', $scoping);

        $user = $request->user();
        $canViewProcesses = $user->can('process.view');
        $canCreateProcess = $user->can('process.create');
        $canUpdateProcess = $user->can('process.update');
        $canCreateRisk = $user->can('create', Risk::class);

        $processOptions = ($canViewProcesses || $canCreateProcess) && $user->organization !== null
            ? $this->processPresenter->formOptions(
                $user->organization,
                null,
                ['default_entity_id' => $scoping->id]
            )['options']
            : null;

        return Inertia::render('Scoping/Show', array_merge(
            $this->entities->detail($scoping),
            [
                'configured' => $this->schemas->detail($scoping, $scoping->resolveObjectTypeCode(), hideEmpty: true),
                'processOptions' => $processOptions,
                'can' => [
                    'update' => $user->can('update', $scoping),
                    'delete' => $user->can('delete', $scoping),
                    'create' => $user->can('create', Entity::class),
                    'attachRiskFromLibrary' => $canCreateRisk,
                    'createRisk' => $canCreateRisk,
                    'viewLibrary' => $user->can('risk_library.view'),
                    'viewProcesses' => $canViewProcesses,
                    'createProcess' => $canCreateProcess,
                    'updateProcess' => $canUpdateProcess,
                    'attachProcess' => $canUpdateProcess,
                ],
            ],
        ));
    }

    /* ------------------------------------------------------------------ */
    /*  Entity Risks (Add from Risk Library) & Processes Tab (ADR 0028 P3) */
    /* ------------------------------------------------------------------ */

    public function attachLibraryRisks(AttachLibraryRisksToEntityRequest $request, Entity $scoping): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();

        $result = $this->riskLibrary->attachManyToEntity(
            $scoping,
            array_map('intval', $validated['library_item_ids']),
            [
                'process_id' => $validated['process_id'] ?? null,
                'risk_owner_id' => $validated['risk_owner_id'] ?? null,
                'business_unit_id' => $validated['business_unit_id'] ?? null,
            ],
            $request->user(),
        );

        $message = $result['skipped_count'] > 0
            ? "Attached {$result['created_count']} risk(s) from the Risk Library ({$result['skipped_count']} already attached, skipped)."
            : "Attached {$result['created_count']} risk(s) from the Risk Library.";

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($result + ['message' => $message], 201);
        }

        return redirect()
            ->route('risk.scoping.show', ['scoping' => $scoping, 'tab' => 'risks'])
            ->with('success', $message);
    }

    public function attachableProcesses(Request $request, Entity $scoping): JsonResponse
    {
        Gate::authorize('viewAny', BusinessProcess::class);
        abort_unless(EntityPolicy::withinReach($request->user(), $scoping), 403);

        return response()->json($this->processPresenter->attachableForEntity($scoping, $request));
    }

    public function attachProcess(AttachProcessToEntityRequest $request, Entity $scoping): JsonResponse|RedirectResponse
    {
        $processIds = $request->resolvedProcessIds();
        $processes = BusinessProcess::query()
            ->where('organization_id', $scoping->organization_id)
            ->whereIn('id', $processIds)
            ->get();

        foreach ($processes as $process) {
            $this->processes->attachToEntity($scoping, $process, $request->user());
        }

        $count = $processes->count();
        $message = $count === 1
            ? "Attached process {$processes->first()->code} — {$processes->first()->name} to {$scoping->name}."
            : "Attached {$count} process(es) to {$scoping->name}.";

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['attached_count' => $count, 'message' => $message], 201);
        }

        return redirect()
            ->route('risk.scoping.show', ['scoping' => $scoping, 'tab' => 'processes'])
            ->with('success', $message);
    }

    public function storeProcess(StoreProcessRequest $request, Entity $scoping): JsonResponse|RedirectResponse
    {
        abort_unless(EntityPolicy::withinReach($request->user(), $scoping), 403);

        $process = $this->processes->create(
            array_merge($request->validated(), ['entity_id' => $scoping->id]),
            $request->user(),
        );

        $message = "Created and attached process {$process->code} — {$process->name} to {$scoping->name}.";

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => $process->id, 'code' => $process->code, 'message' => $message], 201);
        }

        return redirect()
            ->route('risk.scoping.show', ['scoping' => $scoping, 'tab' => 'processes'])
            ->with('success', $message);
    }

    public function detachProcess(Request $request, Entity $scoping, BusinessProcess $process): JsonResponse|RedirectResponse
    {
        Gate::authorize('update', $process);
        abort_unless(EntityPolicy::withinReach($request->user(), $scoping), 403);

        $this->processes->detachFromEntity($scoping, $process, $request->user());

        $message = "Detached process {$process->code} — {$process->name} from {$scoping->name}.";

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message]);
        }

        return redirect()
            ->route('risk.scoping.show', ['scoping' => $scoping, 'tab' => 'processes'])
            ->with('success', $message);
    }

    /* ------------------------------------------------------------------ */
    /*  Edit / Update */
    /* ------------------------------------------------------------------ */

    public function edit(Request $request, Entity $scoping)
    {
        Gate::authorize('update', $scoping);

        $scoping->load(['entityType', 'parent']);

        return Inertia::render('Scoping/Edit', array_merge(
            $this->entities->formOptions($request->user(), $scoping),
            [
                'entity' => $this->entities->present($scoping),
                'schemas' => $this->schemasByEntityType($scoping),
            ],
        ));
    }

    public function update(UpdateEntityRequest $request, Entity $scoping)
    {
        $entity = $this->entities->update($scoping, $request->validated());

        $this->saveConfiguredAttributes($request, $entity, StoreEntityRequest::objectTypeCodeFor($entity->entityType));

        return redirect()
            ->route('risk.scoping.show', $entity)
            ->with('success', "Entity {$entity->entity_code} has been updated successfully.");
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    public function destroy(Entity $scoping)
    {
        Gate::authorize('delete', $scoping);

        if (($refusal = $this->entities->delete($scoping)) !== null) {
            return back()->with('error', $refusal);
        }

        return redirect()
            ->route('risk.scoping.index')
            ->with('success', "Entity {$scoping->entity_code} — {$scoping->name} has been deleted.");
    }

    /* ------------------------------------------------------------------ */

    /**
     * The tenant-configured fields for each entity type the form can choose,
     * keyed by entity type id. An entity is a Group or a Branch or a Process
     * depending on that choice, so the page swaps the DynamicForm section as
     * the type changes. Column-backed fields are dropped: the bespoke form
     * already owns those inputs, and one input per column is the rule.
     *
     * @return array<int, array{objectType: array{id:int,code:string,name:string}|null, sections: list<array<string,mixed>>}>
     */
    private function schemasByEntityType(?Entity $record = null): array
    {
        $out = [];
        $byCode = [];

        $types = EntityType::query()
            ->where(function ($query) use ($record): void {
                $query->where('is_active', true)
                    ->where('code', '!=', 'PROCESS');

                if ($record !== null && $record->entity_type_id !== null) {
                    $query->orWhere('id', $record->entity_type_id);
                }
            })
            ->get();

        foreach ($types as $type) {
            $code = StoreEntityRequest::objectTypeCodeFor($type);

            $byCode[$code] ??= $this->schemas->configuredOnly($this->schemas->form($code, $record));

            $out[$type->id] = $byCode[$code];
        }

        return $out;
    }
}
