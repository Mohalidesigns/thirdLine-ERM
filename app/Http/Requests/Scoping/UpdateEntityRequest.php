<?php

namespace App\Http\Requests\Scoping;

use App\Models\Entity;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class UpdateEntityRequest extends StoreEntityRequest
{
    public const STATUSES = ['active', 'inactive', 'archived'];

    public function authorize(): bool
    {
        $entity = $this->entity();

        return $entity !== null && $this->user()->can('update', $entity);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $entity = $this->entity();
        $orgId = TenantContext::organizationId();

        $rules = parent::rules();

        if ($entity !== null) {
            $ownPath = $entity->hierarchy_path ?: '/'.$entity->getKey().'/';

            // Not itself, and not anything beneath it — either would make the
            // hierarchy a cycle and refreshHierarchyPath() would never return.
            $rules['parent_id'] = [
                'nullable',
                Rule::notIn([$entity->getKey()]),
                Rule::exists('entities', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query->whereNull('hierarchy_path')->orWhere('hierarchy_path', 'not like', $ownPath.'%')),
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'parent_id.not_in' => 'An entity cannot be its own parent.',
            'parent_id.exists' => 'The parent must be an entity in this organization that is not one of this entity\'s own sub-entities.',
        ];
    }

    private function entity(): ?Entity
    {
        $entity = $this->route('scoping');

        return $entity instanceof Entity ? $entity : null;
    }
}
