<?php

namespace App\Http\Controllers\Admin\Metadata;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Metadata\StoreObjectTypeRequest;
use App\Http\Requests\Admin\Metadata\UpdateObjectTypeRequest;
use App\Models\GraphObject;
use App\Models\ObjectLifecycle;
use App\Models\ObjectType;
use App\Services\Metadata\MetadataGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The object type registry (migration Phase 6.3, from Livewire ObjectTypeBuilder).
 *
 * A domain SME adds "Third Party" as a governed kind of thing here, gives it a
 * parent to inherit from, an icon, a code prefix and a lifecycle, and it
 * exists. No migration, no deploy.
 *
 * The rules the Livewire component held are now split by who owns them:
 * shape and tenancy in the Form Requests, "may this person" in ObjectTypePolicy,
 * and "is this change safe for the data" in MetadataGuard — which is where it
 * has to be, so a configuration bundle import is held to the same rules as
 * this screen.
 */
class ObjectTypeController extends Controller
{
    public function __construct(private readonly MetadataGuard $guard) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', ObjectType::class);

        return Inertia::render('Admin/Builder/ObjectTypes/Index', [
            'types' => $this->listing($request),
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'category' => (string) $request->query('category', ''),
            ],
            'options' => $this->options(),
        ]);
    }

    public function store(StoreObjectTypeRequest $request)
    {
        $type = new ObjectType;

        $this->guard->assertNoInheritanceCycle($type, $request->validated()['parent_type_id'] ?? null);

        $type->fill(array_merge($request->payload(), [
            // A type created here belongs to the tenant that created it. It is
            // never a system type: only the seeded registry is.
            'organization_id' => TenantContext::organizationId(),
            'is_system' => false,
        ]))->save();

        return back()->with('success', "Saved “{$type->name}”.");
    }

    public function update(UpdateObjectTypeRequest $request, ObjectType $objectType)
    {
        $this->guard->assertNoInheritanceCycle($objectType, $request->validated()['parent_type_id'] ?? null);

        $objectType->fill($request->payload())->save();

        return back()->with('success', "Saved “{$objectType->name}”.");
    }

    public function destroy(ObjectType $objectType)
    {
        Gate::authorize('delete', $objectType);

        // Throws a ValidationException naming what still depends on it.
        $this->guard->assertTypeDeletable($objectType);

        $name = $objectType->name;
        $objectType->delete();

        return back()->with('success', "Deleted “{$name}”.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $category = (string) $request->query('category', '');

        return ObjectType::query()
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%");
            }))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->with('parentType:id,name,code')
            ->withCount('attributeDefinitions')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ObjectType $type) => array_merge($type->only([
                'id', 'code', 'name', 'plural_name', 'description', 'category', 'parent_type_id',
                'icon', 'color', 'is_node_type', 'is_system', 'default_lifecycle_id',
                'code_prefix', 'sort_order', 'attribute_definitions_count',
            ]), [
                'allowed_child_type_ids' => array_map('intval', $type->allowed_child_type_ids ?? []),
                'parent_name' => $type->getRelationValue('parentType')?->name,
                // Not a relation count: objects are the graph rows, and a type
                // with none is safe to delete, which is what the list is really
                // communicating.
                'record_count' => GraphObject::withoutGlobalScopes()
                    ->where('object_type_id', $type->id)
                    ->count(),
                'can_delete' => Gate::allows('delete', $type),
                'can_edit_identity' => Gate::allows('updateIdentity', $type),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'types' => ObjectType::query()->orderBy('name')->get(['id', 'name', 'code'])->values(),
            'lifecycles' => ObjectLifecycle::query()
                ->orderBy('name')
                ->get(['id', 'name', 'object_type_id'])
                ->values(),
            'categories' => ['org_node', 'governance', 'assessment', 'reference'],
        ];
    }
}
