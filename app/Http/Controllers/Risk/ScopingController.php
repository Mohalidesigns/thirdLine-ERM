<?php

namespace App\Http\Controllers\Risk;

use App\Grids\GridRegistry;
use App\Http\Controllers\Concerns\PersistsConfiguredAttributes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scoping\StoreEntityRequest;
use App\Http\Requests\Scoping\UpdateEntityRequest;
use App\Models\Entity;
use App\Models\EntityType;
use App\Presenters\FormSchemaPresenter;
use App\Presenters\GridPresenter;
use App\Services\Scoping\EntityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

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

        return Inertia::render('Scoping/Show', array_merge(
            $this->entities->detail($scoping),
            [
                'configured' => $this->schemas->detail($scoping, $scoping->resolveObjectTypeCode(), hideEmpty: true),
                'can' => [
                    'update' => $user->can('update', $scoping),
                    'delete' => $user->can('delete', $scoping),
                    'create' => $user->can('create', Entity::class),
                ],
            ],
        ));
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

        foreach (EntityType::query()->where('is_active', true)->get() as $type) {
            $code = StoreEntityRequest::objectTypeCodeFor($type);

            $byCode[$code] ??= $this->configuredOnly($this->schemas->form($code, $record));

            $out[$type->id] = $byCode[$code];
        }

        return $out;
    }

    /**
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
}
