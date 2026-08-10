<?php

namespace App\Livewire\Admin;

use App\Models\GraphObject;
use App\Models\ObjectLifecycle;
use App\Models\ObjectType;
use App\Services\Metadata\MetadataGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * WP-05 TASK 1 — CRUD for object types.
 *
 * A domain SME adds "Third Party" as a governed kind of thing here, gives it a
 * parent to inherit from, an icon, a code prefix and a lifecycle, and it exists.
 * No migration, no deploy.
 *
 * SYSTEM TYPES ARE EDITABLE BUT NOT DELETABLE, AND ONLY COSMETICALLY. The
 * seeded registry is resolved by CODE from a dozen places in the platform
 * (ObjectTypeRegistry::modelTypeMap(), the graph backfill, the unification
 * migration), so letting a tenant rename Risk's code to RISK would break every
 * one of them at once and only at runtime. The form therefore locks `code`,
 * `category` and `is_node_type` on a system type and leaves the presentation
 * fields open — which is what a tenant actually wants: their own icon, their
 * own colour, their own reference prefix.
 *
 * A TENANT'S TYPE SHADOWS A SYSTEM TYPE OF THE SAME CODE, by design, through
 * ObjectType::resolve(). That is the supported way to extend Risk rather than
 * to mutate it.
 */
class ObjectTypeBuilder extends Component
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public bool $confirmingDelete = false;

    public ?int $deletingId = null;

    public string $search = '';

    public string $categoryFilter = '';

    /* Form state ------------------------------------------------------- */

    public string $code = '';

    public string $name = '';

    public string $plural_name = '';

    public string $description = '';

    public string $category = 'governance';

    public ?int $parent_type_id = null;

    public string $icon = 'category';

    public string $color = '#1A365D';

    public bool $is_node_type = false;

    /** @var list<int> */
    public array $allowed_child_type_ids = [];

    public ?int $default_lifecycle_id = null;

    public string $code_prefix = '';

    public int $sort_order = 0;

    public function render()
    {
        return view('livewire.admin.object-type-builder', [
            'types' => $this->types(),
            'parentOptions' => $this->parentOptions(),
            'lifecycleOptions' => $this->lifecycleOptions(),
            'editing' => $this->editing(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Listing */
    /* ------------------------------------------------------------------ */

    private function types()
    {
        return ObjectType::query()
            ->when($this->search !== '', fn ($query) => $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%");
            }))
            ->when($this->categoryFilter !== '', fn ($query) => $query->where('category', $this->categoryFilter))
            ->with('parentType')
            ->withCount('attributeDefinitions')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            // The record count is not a relation count: objects are the graph
            // rows, and a type with none is safe to delete, which is what the
            // list is really communicating.
            ->each(fn (ObjectType $type) => $type->setAttribute(
                'record_count',
                GraphObject::withoutGlobalScopes()->where('object_type_id', $type->id)->count()
            ));
    }

    private function parentOptions()
    {
        return ObjectType::query()
            ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    private function lifecycleOptions()
    {
        return ObjectLifecycle::query()
            ->when($this->editingId !== null, fn ($query) => $query->where('object_type_id', $this->editingId))
            ->orderBy('name')
            ->get(['id', 'name', 'object_type_id']);
    }

    private function editing(): ?ObjectType
    {
        return $this->editingId === null ? null : ObjectType::find($this->editingId);
    }

    /* ------------------------------------------------------------------ */
    /*  Form */
    /* ------------------------------------------------------------------ */

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $type = ObjectType::findOrFail($id);

        $this->editingId = $type->id;
        $this->code = $type->code;
        $this->name = $type->name;
        $this->plural_name = $type->plural_name ?? '';
        $this->description = $type->description ?? '';
        $this->category = $type->category;
        $this->parent_type_id = $type->parent_type_id;
        $this->icon = $type->icon ?? 'category';
        $this->color = $type->color ?? '#1A365D';
        $this->is_node_type = (bool) $type->is_node_type;
        $this->allowed_child_type_ids = array_map('intval', $type->allowed_child_type_ids ?? []);
        $this->default_lifecycle_id = $type->default_lifecycle_id;
        $this->code_prefix = $type->code_prefix ?? '';
        $this->sort_order = (int) $type->sort_order;

        $this->showForm = true;
    }

    public function updatedName(string $value): void
    {
        // Only while creating: renaming an existing type must never move its
        // code, because the code is what everything else resolves it by.
        if ($this->editingId === null && $this->code === '') {
            $this->code = Str::studly($value);
        }
    }

    public function save(): void
    {
        $type = $this->editing() ?? new ObjectType;
        $isSystem = $type->exists && $type->is_system;

        $rules = [
            'name' => 'required|string|max:120',
            'plural_name' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:20',
            'parent_type_id' => 'nullable|integer|exists:object_types,id',
            'default_lifecycle_id' => 'nullable|integer|exists:object_lifecycles,id',
            'code_prefix' => 'nullable|string|max:12|regex:/^[A-Z0-9\-]*$/',
            'sort_order' => 'integer|min:0',
            'allowed_child_type_ids' => 'array',
            'allowed_child_type_ids.*' => 'integer|exists:object_types,id',
        ];

        if (! $isSystem) {
            $rules['code'] = [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/',
                // Scoped to the tenant, so a tenant may define a type whose
                // code matches a system type's and shadow it.
                function ($attribute, $value, $fail) use ($type) {
                    $clash = ObjectType::withoutGlobalScopes()
                        ->where('organization_id', TenantContext::organizationId())
                        ->where('code', $value)
                        ->when($type->exists, fn ($query) => $query->whereKeyNot($type->id))
                        ->exists();

                    if ($clash) {
                        $fail('You already have a type with this code.');
                    }
                },
            ];
            $rules['category'] = 'required|in:org_node,governance,assessment,reference';
        }

        $validated = $this->validate($rules);

        app(MetadataGuard::class)->assertNoInheritanceCycle($type, $this->parent_type_id);

        $payload = [
            'name' => $validated['name'],
            'plural_name' => $this->plural_name ?: Str::plural($validated['name']),
            'description' => $this->description ?: null,
            'parent_type_id' => $this->parent_type_id,
            'icon' => $this->icon ?: null,
            'color' => $this->color ?: null,
            'allowed_child_type_ids' => $this->allowed_child_type_ids ?: null,
            'default_lifecycle_id' => $this->default_lifecycle_id,
            'code_prefix' => $this->code_prefix ? strtoupper($this->code_prefix) : null,
            'sort_order' => $this->sort_order,
        ];

        if (! $isSystem) {
            $payload['code'] = $validated['code'];
            $payload['category'] = $validated['category'];
            $payload['is_node_type'] = $this->is_node_type;
        }

        if (! $type->exists) {
            // A type created here belongs to the tenant that created it. It is
            // never a system type: only the seeded registry is.
            $payload['organization_id'] = TenantContext::organizationId();
            $payload['is_system'] = false;
        }

        $type->fill($payload)->save();

        $this->showForm = false;
        $this->resetForm();

        $this->dispatch('metadata-changed');
        session()->flash('builder-status', "Saved “{$payload['name']}”.");
    }

    /* ------------------------------------------------------------------ */
    /*  Deletion */
    /* ------------------------------------------------------------------ */

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->confirmingDelete = true;
    }

    public function delete(): void
    {
        $type = ObjectType::findOrFail($this->deletingId);

        // Throws a ValidationException that Livewire surfaces on the form.
        app(MetadataGuard::class)->assertTypeDeletable($type);

        $name = $type->name;
        $type->delete();

        $this->confirmingDelete = false;
        $this->deletingId = null;

        $this->dispatch('metadata-changed');
        session()->flash('builder-status', "Deleted “{$name}”.");
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->deletingId = null;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'plural_name', 'description', 'category',
            'parent_type_id', 'icon', 'color', 'is_node_type', 'allowed_child_type_ids',
            'default_lifecycle_id', 'code_prefix', 'sort_order',
        ]);

        $this->category = 'governance';
        $this->icon = 'category';
        $this->color = '#1A365D';
        $this->resetErrorBag();
    }
}
