<?php

namespace App\Http\Requests\Admin\Metadata;

use App\Models\ObjectType;
use App\Support\Metadata\MetadataRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Define a governed kind of thing (migration Phase 6.3).
 *
 * Every rule here was in ObjectTypeBuilder::save(); three of them are stricter
 * now. `parent_type_id`, `default_lifecycle_id` and `allowed_child_type_ids.*`
 * were bare `exists:` on tables that are tenant-scoped-with-system-rows, so a
 * tenant could graft their type onto another institution's — see
 * App\Support\Metadata\MetadataRules.
 *
 * The code uniqueness check is deliberately scoped to the tenant rather than
 * the table: a tenant's type may share a code with a system type and shadow
 * it, which is the supported way to extend Risk rather than mutate it
 * (ObjectType::resolve()).
 */
class StoreObjectTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ObjectType::class);
    }

    protected function subject(): ?ObjectType
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->subject();

        return array_merge([
            'name' => ['required', 'string', 'max:120'],
            'plural_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:20'],
            'parent_type_id' => ['nullable', 'integer', MetadataRules::objectType()],
            'default_lifecycle_id' => ['nullable', 'integer', MetadataRules::objectLifecycle()],
            'code_prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z0-9\-]*$/'],
            'sort_order' => ['integer', 'min:0'],
            'allowed_child_type_ids' => ['array'],
            'allowed_child_type_ids.*' => ['integer', MetadataRules::objectType()],
        ], $this->identityRules($type));
    }

    /**
     * The fields the rest of the platform resolves a type by.
     *
     * Locked on a system type — ObjectTypePolicy::updateIdentity says why —
     * so they are simply absent from the rules and from the payload.
     *
     * @return array<string, mixed>
     */
    protected function identityRules(?ObjectType $type): array
    {
        if ($type !== null && $type->is_system) {
            return [];
        }

        return [
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/',
                function (string $attribute, mixed $value, callable $fail) use ($type) {
                    $clash = ObjectType::withoutGlobalScopes()
                        ->where('organization_id', TenantContext::organizationId())
                        ->where('code', $value)
                        ->when($type?->exists, fn ($query) => $query->whereKeyNot($type->id))
                        ->exists();

                    if ($clash) {
                        $fail('You already have a type with this code.');
                    }
                },
            ],
            'category' => ['required', 'in:org_node,governance,assessment,reference'],
            'is_node_type' => ['boolean'],
        ];
    }

    /**
     * The attributes to write, with the identity fields present only when this
     * actor may set them.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();
        $type = $this->subject();

        // Every read is defaulted: validated() omits a nullable key the caller
        // did not send, and a payload built from bare subscripts would fatal
        // on the first partial form post.
        $payload = [
            'name' => $validated['name'],
            'plural_name' => ($validated['plural_name'] ?? '') ?: Str::plural($validated['name']),
            'description' => ($validated['description'] ?? '') ?: null,
            'parent_type_id' => $validated['parent_type_id'] ?? null,
            'icon' => ($validated['icon'] ?? '') ?: null,
            'color' => ($validated['color'] ?? '') ?: null,
            'allowed_child_type_ids' => ($validated['allowed_child_type_ids'] ?? []) ?: null,
            'default_lifecycle_id' => $validated['default_lifecycle_id'] ?? null,
            'code_prefix' => ($validated['code_prefix'] ?? '') !== '' ? strtoupper($validated['code_prefix']) : null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];

        if (! ($type !== null && $type->is_system)) {
            $payload['code'] = $validated['code'];
            $payload['category'] = $validated['category'];
            $payload['is_node_type'] = (bool) ($validated['is_node_type'] ?? false);
        }

        return $payload;
    }
}
