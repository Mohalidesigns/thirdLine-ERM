<?php

namespace App\Http\Controllers\Admin\Metadata;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Metadata\StoreLifecycleRequest;
use App\Http\Requests\Admin\Metadata\UpdateLifecycleRequest;
use App\Models\ObjectLifecycle;
use App\Models\ObjectType;
use App\Models\WorkflowDefinition;
use App\Services\Metadata\MetadataGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * State machines (migration Phase 6.3, from Livewire LifecycleBuilder).
 *
 * The editor draws the machine as a matrix of "from" rows against "to"
 * columns, which is the one layout that makes the mistake people actually make
 * visible at a glance: a state with no way out.
 *
 * COHERENCE IS ENFORCED ON SAVE, not merely encouraged — exactly one initial
 * state, at least one terminal state, no transition to a state that does not
 * exist, and no state unreachable from the initial one. That last check earns
 * its keep: renaming a state without updating the transitions that pointed at
 * it leaves a machine that refuses a move every user is certain should work,
 * and nothing in the UI would otherwise say why.
 *
 * WHY THE SEEDED LIFECYCLES LOOK INCONSISTENT: they use the state codes
 * already in the domain tables, which is why the issue lifecycle is uppercase
 * and the risk lifecycle is not. A lifecycle that disagrees with its own table
 * validates nothing.
 */
class LifecycleController extends Controller
{
    public function __construct(private readonly MetadataGuard $guard) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', ObjectLifecycle::class);

        $objectTypeId = $request->integer('object_type_id') ?: null;

        return Inertia::render('Admin/Builder/Lifecycles/Edit', [
            'lifecycles' => ObjectLifecycle::query()
                ->when($objectTypeId, fn ($query) => $query->where('object_type_id', $objectTypeId))
                ->with('objectType:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (ObjectLifecycle $lifecycle) => array_merge($lifecycle->only([
                    'id', 'code', 'name', 'object_type_id', 'is_system',
                ]), [
                    'object_type_name' => $lifecycle->getRelationValue('objectType')?->name,
                    'states' => array_values($lifecycle->states ?? []),
                    'can_delete' => Gate::allows('delete', $lifecycle),
                ]))->values(),
            'filters' => ['object_type_id' => $objectTypeId],
            'options' => [
                'types' => ObjectType::query()->orderBy('name')->get(['id', 'name'])->values(),
                'permissions' => Permission::query()->orderBy('name')->pluck('name')->values(),
                'workflows' => WorkflowDefinition::query()->orderBy('name')->get(['id', 'name'])->values(),
            ],
        ]);
    }

    public function store(StoreLifecycleRequest $request)
    {
        $states = $request->states();

        // Throws a ValidationException keyed on `states`, which the editor
        // renders above the matrix.
        $this->guard->assertLifecycleIsCoherent($states);

        $lifecycle = new ObjectLifecycle;
        $validated = $request->validated();

        $lifecycle->fill([
            'organization_id' => TenantContext::organizationId(),
            'object_type_id' => $validated['object_type_id'],
            'code' => $validated['code'],
            'name' => $validated['name'],
            'states' => $states,
            'is_system' => false,
        ])->save();

        return back()->with('success', "Saved lifecycle “{$lifecycle->name}”.");
    }

    public function update(UpdateLifecycleRequest $request, ObjectLifecycle $lifecycle)
    {
        $states = $request->states();
        $this->guard->assertLifecycleIsCoherent($states);

        $validated = $request->validated();
        $code = $validated['code'];

        if ($lifecycle->is_system) {
            // The seeded machines are what the domain tables' existing state
            // strings conform to. Editing one in place would silently
            // invalidate live rows; cloning it into the tenant's own namespace
            // does not.
            $lifecycle = new ObjectLifecycle;
            $code = $code.'-'.Str::lower(Str::random(4));
        }

        $lifecycle->fill([
            'organization_id' => $lifecycle->exists ? $lifecycle->organization_id : TenantContext::organizationId(),
            'object_type_id' => $validated['object_type_id'],
            'code' => $code,
            'name' => $validated['name'],
            'states' => $states,
            'is_system' => false,
        ])->save();

        return back()->with('success', "Saved lifecycle “{$lifecycle->name}”.");
    }

    public function destroy(ObjectLifecycle $lifecycle)
    {
        Gate::authorize('delete', $lifecycle);

        // Throws if a type still defaults to it.
        $this->guard->assertLifecycleDeletable($lifecycle);

        $name = $lifecycle->name;
        $lifecycle->delete();

        return back()->with('success', "Deleted lifecycle “{$name}”.");
    }
}
