<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectRelationshipType;
use App\Support\Metadata\MetadataRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Define a typed edge (migration Phase 6.3).
 *
 * `from_type_ids.*` and `to_type_ids.*` were bare `exists:object_types,id`, so
 * an edge could be constrained to another institution's type — which makes the
 * edge unusable in a quiet, hard-to-diagnose way, and puts a foreign id in the
 * tenant's configuration. Both are tenant-bound now.
 *
 * The attribute schema is accepted as rows rather than as the "code|Label|type"
 * text the Livewire form asked an SME to type. The parse is the same; doing it
 * in the browser was the only thing that made the text shape necessary.
 */
class StoreRelationshipTypeRequest extends FormRequest
{
    public const DATA_TYPES = ['string', 'int', 'decimal', 'bool', 'date'];

    public function authorize(): bool
    {
        return $this->user()->can('create', ObjectRelationshipType::class);
    }

    protected function subject(): ?ObjectRelationshipType
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->subject();

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'inverse_code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'cardinality' => ['required', 'in:one_to_one,one_to_many,many_to_many'],
            'has_weight' => ['boolean'],
            'from_type_ids' => ['array'],
            'from_type_ids.*' => ['integer', MetadataRules::objectType()],
            'to_type_ids' => ['array'],
            'to_type_ids.*' => ['integer', MetadataRules::objectType()],
            'attribute_schema' => ['array'],
            'attribute_schema.*.code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'attribute_schema.*.label' => ['nullable', 'string', 'max:160'],
            'attribute_schema.*.type' => ['nullable', 'in:'.implode(',', self::DATA_TYPES)],
        ];

        if ($type === null || ! $type->is_system) {
            $rules['code'] = [
                'required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/',
                function (string $field, mixed $value, callable $fail) use ($type) {
                    $clash = ObjectRelationshipType::withoutGlobalScopes()
                        ->where('organization_id', TenantContext::organizationId())
                        ->where('code', $value)
                        ->when($type?->exists, fn ($query) => $query->whereKeyNot($type->id))
                        ->exists();

                    if ($clash) {
                        $fail('You already have a relationship type with this code.');
                    }
                },
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();
        $type = $this->subject();

        $payload = [
            'name' => $validated['name'],
            'inverse_code' => ($validated['inverse_code'] ?? '') ?: null,
            // NULL, not [], means "any type". An empty array would make the
            // edge unusable — see the ObjectRelationshipType docblock.
            'from_type_ids' => ($validated['from_type_ids'] ?? []) ?: null,
            'to_type_ids' => ($validated['to_type_ids'] ?? []) ?: null,
            'cardinality' => $validated['cardinality'],
            'has_weight' => (bool) ($validated['has_weight'] ?? false),
            'attribute_schema' => $this->schema() ?: null,
        ];

        if ($type === null || ! $type->is_system) {
            $payload['code'] = $validated['code'];
        }

        return $payload;
    }

    /**
     * @return array<string, array{label: string, type: string}>
     */
    private function schema(): array
    {
        $schema = [];

        foreach ((array) $this->input('attribute_schema', []) as $row) {
            $code = trim((string) ($row['code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $type = $row['type'] ?? null;

            $schema[$code] = [
                'label' => trim((string) ($row['label'] ?? '')) ?: ucfirst(str_replace('_', ' ', $code)),
                'type' => in_array($type, self::DATA_TYPES, true) ? $type : 'string',
            ];
        }

        return $schema;
    }
}
