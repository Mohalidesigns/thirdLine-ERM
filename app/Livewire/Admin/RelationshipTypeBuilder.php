<?php

namespace App\Livewire\Admin;

use App\Models\ObjectRelationshipType;
use App\Models\ObjectType;
use App\Services\Metadata\MetadataGuard;
use App\Support\Tenancy\TenantContext;
use Livewire\Component;

/**
 * WP-05 TASK 1 — CRUD for typed edges.
 *
 * from/to constraints, cardinality, and the attribute schema an edge of this
 * type carries. `weight` is the field that matters most and is easiest to
 * overlook: it is what makes graph-derived roll-up possible at all, because it
 * is where "this control covers 40% of this risk" is recorded.
 *
 * DELETING A TYPE WITH INSTANCES ARCHIVES THEM rather than cascading. See
 * MetadataGuard::deleteRelationshipType() for why.
 */
class RelationshipTypeBuilder extends Component
{
    public ?int $editingId = null;

    public bool $showForm = false;

    public ?int $deletingId = null;

    public int $deletingInstanceCount = 0;

    /* Form state ------------------------------------------------------- */

    public string $code = '';

    public string $name = '';

    public string $inverse_code = '';

    /** @var list<int> */
    public array $from_type_ids = [];

    /** @var list<int> */
    public array $to_type_ids = [];

    public string $cardinality = 'many_to_many';

    public bool $has_weight = false;

    /** One "code|Label|type" per line — the shape an SME can type without JSON. */
    public string $attribute_schema = '';

    public function render()
    {
        return view('livewire.admin.relationship-type-builder', [
            'relationshipTypes' => ObjectRelationshipType::query()
                ->orderBy('name')
                ->get()
                ->each(fn (ObjectRelationshipType $type) => $type->setAttribute(
                    'instance_count',
                    app(MetadataGuard::class)->relationshipInstanceCount($type)
                )),
            'typeOptions' => ObjectType::orderBy('category')->orderBy('name')->get(['id', 'name', 'category']),
        ]);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $type = ObjectRelationshipType::findOrFail($id);

        $this->editingId = $type->id;
        $this->code = $type->code;
        $this->name = $type->name;
        $this->inverse_code = $type->inverse_code ?? '';
        $this->from_type_ids = array_map('intval', $type->from_type_ids ?? []);
        $this->to_type_ids = array_map('intval', $type->to_type_ids ?? []);
        $this->cardinality = $type->cardinality;
        $this->has_weight = (bool) $type->has_weight;
        $this->attribute_schema = $this->schemaToText($type->attribute_schema ?? []);

        $this->showForm = true;
    }

    public function save(): void
    {
        $type = $this->editingId === null
            ? new ObjectRelationshipType
            : ObjectRelationshipType::findOrFail($this->editingId);

        $isSystem = $type->exists && $type->is_system;

        $rules = [
            'name' => 'required|string|max:120',
            'inverse_code' => 'nullable|string|max:64|regex:/^[a-z][a-z0-9_]*$/',
            'cardinality' => 'required|in:one_to_one,one_to_many,many_to_many',
            'from_type_ids' => 'array',
            'from_type_ids.*' => 'integer|exists:object_types,id',
            'to_type_ids' => 'array',
            'to_type_ids.*' => 'integer|exists:object_types,id',
        ];

        if (! $isSystem) {
            $rules['code'] = [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                function ($field, $value, $fail) use ($type) {
                    $clash = ObjectRelationshipType::withoutGlobalScopes()
                        ->where('organization_id', TenantContext::organizationId())
                        ->where('code', $value)
                        ->when($type->exists, fn ($query) => $query->whereKeyNot($type->id))
                        ->exists();

                    if ($clash) {
                        $fail('You already have a relationship type with this code.');
                    }
                },
            ];
        }

        $validated = $this->validate($rules);

        $payload = [
            'name' => $validated['name'],
            'inverse_code' => $this->inverse_code ?: null,
            // NULL, not [], means "any type". An empty array would make the
            // edge unusable — see the ObjectRelationshipType docblock.
            'from_type_ids' => $this->from_type_ids ?: null,
            'to_type_ids' => $this->to_type_ids ?: null,
            'cardinality' => $validated['cardinality'],
            'has_weight' => $this->has_weight,
            'attribute_schema' => $this->parsedSchema() ?: null,
        ];

        if (! $isSystem) {
            $payload['code'] = $validated['code'];
        }

        if (! $type->exists) {
            $payload['organization_id'] = TenantContext::organizationId();
            $payload['is_system'] = false;
        }

        $type->fill($payload)->save();

        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('metadata-changed');
        session()->flash('builder-status', "Saved “{$payload['name']}”.");
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->deletingInstanceCount = app(MetadataGuard::class)
            ->relationshipInstanceCount(ObjectRelationshipType::findOrFail($id));
    }

    public function delete(): void
    {
        $type = ObjectRelationshipType::findOrFail($this->deletingId);
        $name = $type->name;

        $archived = app(MetadataGuard::class)->deleteRelationshipType($type, confirmed: true);

        $this->deletingId = null;
        $this->deletingInstanceCount = 0;

        $this->dispatch('metadata-changed');
        session()->flash('builder-status', $archived > 0
            ? "Archived “{$name}” and {$archived} relationship(s). They are retained for audit but no longer traversed."
            : "Archived “{$name}”.");
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->deletingInstanceCount = 0;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    /* ------------------------------------------------------------------ */
    /*  The edge attribute schema, as lines rather than JSON */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array<string, string>> */
    private function parsedSchema(): array
    {
        $schema = [];

        foreach (preg_split('/\r\n|\r|\n/', $this->attribute_schema) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$code, $label, $dataType] = array_pad(array_map('trim', explode('|', $line, 3)), 3, null);

            if ($code === '' || $code === null) {
                continue;
            }

            $schema[$code] = [
                'label' => $label ?: ucfirst(str_replace('_', ' ', $code)),
                'type' => in_array($dataType, ['string', 'int', 'decimal', 'bool', 'date'], true)
                    ? $dataType
                    : 'string',
            ];
        }

        return $schema;
    }

    /** @param array<string, mixed> $schema */
    private function schemaToText(array $schema): string
    {
        $lines = [];

        foreach ($schema as $code => $definition) {
            $lines[] = implode('|', [
                $code,
                $definition['label'] ?? $code,
                $definition['type'] ?? 'string',
            ]);
        }

        return implode("\n", $lines);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'code', 'name', 'inverse_code', 'from_type_ids', 'to_type_ids',
            'has_weight', 'attribute_schema',
        ]);

        $this->cardinality = 'many_to_many';
        $this->resetErrorBag();
    }
}
