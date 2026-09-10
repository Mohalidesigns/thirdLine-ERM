<?php

namespace App\Http\Requests\Scoping;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Entity;
use App\Models\EntityType;
use App\Support\Graph\ObjectTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class StoreEntityRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public const STATUSES = ['active', 'inactive'];

    public const APPETITE_LEVELS = ['averse', 'minimal', 'cautious', 'open', 'hungry'];

    public const APPETITE_CATEGORIES = ['credit', 'operational', 'market', 'compliance', 'technology'];

    public function authorize(): bool
    {
        return $this->user()->can('create', Entity::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'entity_type_id' => ['required', Rule::exists('entity_types', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', Rule::exists('entities', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:20000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'delegate_owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'status' => ['required', Rule::in(static::STATUSES)],
            'regulatory_frameworks' => ['nullable', 'array'],
            'regulatory_frameworks.*' => ['string', 'max:50'],
            'risk_appetite_level' => ['nullable', Rule::in(self::APPETITE_LEVELS)],
            'category_appetites' => ['nullable', 'array'],
            'category_appetites.*' => ['nullable', Rule::in(self::APPETITE_LEVELS)],
            ...$this->configuredAttributeRules($this->objectTypeCode()),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels($this->objectTypeCode());
    }

    /**
     * The object type whose configured fields apply. An entity is a Group or a
     * Branch or a Process depending on the entity type chosen on the form, so
     * it is read from the request rather than from the model class.
     */
    protected function objectTypeCode(): string
    {
        $typeId = $this->input('entity_type_id');

        $type = $typeId ? EntityType::query()->find($typeId) : null;

        return static::objectTypeCodeFor($type);
    }

    public static function objectTypeCodeFor(?EntityType $type): string
    {
        if ($type === null || $type->code === null) {
            return 'BusinessUnit';
        }

        return ObjectTypeRegistry::legacyEntityTypeMap()[$type->code] ?? 'BusinessUnit';
    }
}
