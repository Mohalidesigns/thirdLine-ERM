<?php

namespace App\Livewire\Admin;

use App\Models\ObjectLifecycle;
use App\Models\ObjectType;
use App\Models\WorkflowDefinition;
use App\Services\Metadata\MetadataGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Livewire\Component;
use Spatie\Permission\Models\Permission;

/**
 * WP-05 TASK 1 — the visual state machine editor.
 *
 * States, transitions, the permission each transition demands and the workflow
 * it must run through. The editor draws the machine as a matrix of "from" rows
 * against "to" columns, which is the one layout that makes the mistake people
 * actually make visible at a glance: a state with no way out.
 *
 * COHERENCE IS ENFORCED ON SAVE, not merely encouraged. MetadataGuard checks
 * exactly one initial state, at least one terminal state, no transition to a
 * state that does not exist, and no state unreachable from the initial one.
 * That last check is the one that earns its keep — renaming a state without
 * updating the transitions that pointed at it leaves a machine that refuses a
 * move every user is certain should work, and nothing in the UI would
 * otherwise say why.
 *
 * WHY THE SEEDED LIFECYCLES LOOK INCONSISTENT. They use the state codes
 * already in the domain tables, which is why the issue lifecycle is uppercase
 * and the risk lifecycle is not. A lifecycle that disagrees with its own table
 * validates nothing. Renaming live states is a data migration, not an edit
 * here, and the editor says so rather than letting somebody try.
 */
class LifecycleBuilder extends Component
{
    public ?int $objectTypeId = null;

    public ?int $editingId = null;

    public bool $showForm = false;

    public ?int $deletingId = null;

    public string $code = '';

    public string $name = '';

    /**
     * @var list<array{code:string,name:string,color:string,is_initial:bool,
     *                 is_terminal:bool,allowed_transitions:list<string>,
     *                 required_permission:?string,required_workflow_id:?int}>
     */
    public array $states = [];

    public function mount(?int $objectTypeId = null): void
    {
        $this->objectTypeId = $objectTypeId;
    }

    public function render()
    {
        return view('livewire.admin.lifecycle-builder', [
            'lifecycles' => ObjectLifecycle::query()
                ->when($this->objectTypeId, fn ($query) => $query->where('object_type_id', $this->objectTypeId))
                ->with('objectType')
                ->orderBy('name')
                ->get(),
            'typeOptions' => ObjectType::orderBy('name')->get(['id', 'name']),
            'permissionOptions' => Permission::orderBy('name')->pluck('name'),
            'workflowOptions' => WorkflowDefinition::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Form */
    /* ------------------------------------------------------------------ */

    public function create(): void
    {
        $this->resetForm();

        // A usable starting machine rather than a blank page: two states and
        // the one transition between them already satisfies every coherence
        // rule, so the first save cannot fail on a technicality.
        $this->states = [
            $this->blankState(['code' => 'draft', 'name' => 'Draft', 'is_initial' => true, 'allowed_transitions' => ['closed']]),
            $this->blankState(['code' => 'closed', 'name' => 'Closed', 'is_terminal' => true, 'color' => '#6b7280']),
        ];

        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $lifecycle = ObjectLifecycle::findOrFail($id);

        $this->editingId = $lifecycle->id;
        $this->objectTypeId = $lifecycle->object_type_id;
        $this->code = $lifecycle->code;
        $this->name = $lifecycle->name;
        $this->states = array_map(fn (array $state) => $this->blankState($state), $lifecycle->states ?? []);

        $this->showForm = true;
    }

    public function addState(): void
    {
        $this->states[] = $this->blankState([]);
    }

    public function removeState(int $index): void
    {
        $removed = $this->states[$index]['code'] ?? null;

        unset($this->states[$index]);
        $this->states = array_values($this->states);

        // Drop transitions that pointed at it, rather than leaving a dangling
        // target that the coherence check would reject at save with a message
        // about a state the user has already deleted.
        if ($removed !== null) {
            foreach ($this->states as $index => $state) {
                $this->states[$index]['allowed_transitions'] = array_values(
                    array_diff($state['allowed_transitions'] ?? [], [$removed])
                );
            }
        }
    }

    /** Toggle the transition from $fromIndex to the state coded $toCode. */
    public function toggleTransition(int $fromIndex, string $toCode): void
    {
        $current = $this->states[$fromIndex]['allowed_transitions'] ?? [];

        $this->states[$fromIndex]['allowed_transitions'] = in_array($toCode, $current, true)
            ? array_values(array_diff($current, [$toCode]))
            : array_values(array_merge($current, [$toCode]));
    }

    /** Only one state can be the initial one; setting it clears the others. */
    public function setInitial(int $index): void
    {
        foreach ($this->states as $i => $state) {
            $this->states[$i]['is_initial'] = $i === $index;
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'objectTypeId' => 'required|integer|exists:object_types,id',
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:64|regex:/^[a-z][a-z0-9_\-]*$/',
            'states' => 'required|array|min:1',
            'states.*.code' => 'required|string|max:64',
            'states.*.name' => 'required|string|max:120',
        ]);

        $states = array_map(function (array $state) {
            return [
                'code' => trim($state['code']),
                'name' => trim($state['name']),
                'color' => $state['color'] ?: '#94a3b8',
                'is_initial' => (bool) ($state['is_initial'] ?? false),
                'is_terminal' => (bool) ($state['is_terminal'] ?? false),
                'allowed_transitions' => array_values(array_filter($state['allowed_transitions'] ?? [])),
                'required_permission' => $state['required_permission'] ?: null,
                'required_workflow_id' => $state['required_workflow_id'] ?: null,
            ];
        }, $this->states);

        // Throws a ValidationException keyed on `states`, which the editor
        // renders above the matrix.
        app(MetadataGuard::class)->assertLifecycleIsCoherent($states);

        $lifecycle = $this->editingId === null
            ? new ObjectLifecycle
            : ObjectLifecycle::findOrFail($this->editingId);

        if ($lifecycle->exists && $lifecycle->is_system) {
            // The seeded machines are what the domain tables' existing state
            // strings conform to. Editing one in place would silently invalidate
            // live rows; cloning it into the tenant's own namespace does not.
            $lifecycle = new ObjectLifecycle;
            $validated['code'] = $validated['code'].'-'.Str::lower(Str::random(4));
            $this->editingId = null;
        }

        $lifecycle->fill([
            'organization_id' => $lifecycle->exists ? $lifecycle->organization_id : TenantContext::organizationId(),
            'object_type_id' => $validated['objectTypeId'],
            'code' => $validated['code'],
            'name' => $validated['name'],
            'states' => $states,
            'is_system' => false,
        ])->save();

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('metadata-changed');
        session()->flash('builder-status', "Saved lifecycle “{$validated['name']}”.");
    }

    public function delete(int $id): void
    {
        $lifecycle = ObjectLifecycle::findOrFail($id);

        app(MetadataGuard::class)->assertLifecycleDeletable($lifecycle);

        $name = $lifecycle->name;
        $lifecycle->delete();

        $this->dispatch('metadata-changed');
        session()->flash('builder-status', "Deleted lifecycle “{$name}”.");
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function blankState(array $state): array
    {
        return [
            'code' => $state['code'] ?? '',
            'name' => $state['name'] ?? '',
            'color' => $state['color'] ?? '#3b82f6',
            'is_initial' => (bool) ($state['is_initial'] ?? false),
            'is_terminal' => (bool) ($state['is_terminal'] ?? false),
            'allowed_transitions' => array_values((array) ($state['allowed_transitions'] ?? [])),
            'required_permission' => $state['required_permission'] ?? null,
            'required_workflow_id' => $state['required_workflow_id'] ?? null,
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'states']);
        $this->resetErrorBag();
    }
}
