<?php

namespace App\Http\Controllers\Admin\Metadata;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Metadata\StoreRelationshipTypeRequest;
use App\Http\Requests\Admin\Metadata\UpdateRelationshipTypeRequest;
use App\Models\ObjectRelationshipType;
use App\Models\ObjectType;
use App\Services\Metadata\MetadataGuard;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Typed edges (migration Phase 6.3, from Livewire RelationshipTypeBuilder).
 *
 * `weight` is the field that matters most and is easiest to overlook: it is
 * what makes graph-derived roll-up possible at all, because it is where "this
 * control covers 40% of this risk" is recorded.
 *
 * DELETING A TYPE WITH INSTANCES ARCHIVES THEM rather than cascading —
 * MetadataGuard::deleteRelationshipType() explains why, and the count is sent
 * to the page so the confirmation can say how many.
 */
class RelationshipTypeController extends Controller
{
    public function __construct(private readonly MetadataGuard $guard) {}

    public function index()
    {
        Gate::authorize('viewAny', ObjectRelationshipType::class);

        return Inertia::render('Admin/Builder/RelationshipTypes/Index', [
            'relationshipTypes' => ObjectRelationshipType::query()
                ->orderBy('name')
                ->get()
                ->map(fn (ObjectRelationshipType $type) => array_merge($type->only([
                    'id', 'code', 'name', 'inverse_code', 'cardinality', 'has_weight', 'is_system',
                ]), [
                    'from_type_ids' => array_map('intval', $type->from_type_ids ?? []),
                    'to_type_ids' => array_map('intval', $type->to_type_ids ?? []),
                    'attribute_schema' => $this->schemaRows($type),
                    'instance_count' => $this->guard->relationshipInstanceCount($type),
                    'can_delete' => Gate::allows('delete', $type),
                    'can_edit_identity' => Gate::allows('updateIdentity', $type),
                ]))->values(),
            'options' => [
                'types' => ObjectType::query()
                    ->orderBy('category')->orderBy('name')
                    ->get(['id', 'name', 'category'])->values(),
                'cardinalities' => ['one_to_one', 'one_to_many', 'many_to_many'],
                'dataTypes' => StoreRelationshipTypeRequest::DATA_TYPES,
            ],
        ]);
    }

    public function store(StoreRelationshipTypeRequest $request)
    {
        $type = new ObjectRelationshipType;

        $type->fill(array_merge($request->payload(), [
            'organization_id' => TenantContext::organizationId(),
            'is_system' => false,
        ]))->save();

        return back()->with('success', "Saved “{$type->name}”.");
    }

    public function update(UpdateRelationshipTypeRequest $request, ObjectRelationshipType $relationshipType)
    {
        $relationshipType->fill($request->payload())->save();

        return back()->with('success', "Saved “{$relationshipType->name}”.");
    }

    public function destroy(ObjectRelationshipType $relationshipType)
    {
        Gate::authorize('delete', $relationshipType);

        $name = $relationshipType->name;
        $archived = $this->guard->deleteRelationshipType($relationshipType, confirmed: true);

        return back()->with('success', $archived > 0
            ? "Archived “{$name}” and {$archived} relationship(s). They are retained for audit but no longer traversed."
            : "Archived “{$name}”.");
    }

    /**
     * The stored schema as the form's repeating rows.
     *
     * @return list<array{code: string, label: string, type: string}>
     */
    private function schemaRows(ObjectRelationshipType $type): array
    {
        $rows = [];

        foreach ((array) ($type->attribute_schema ?? []) as $code => $definition) {
            $rows[] = [
                'code' => (string) $code,
                'label' => (string) ($definition['label'] ?? $code),
                'type' => (string) ($definition['type'] ?? 'string'),
            ];
        }

        return $rows;
    }
}
