<?php

namespace App\Http\Requests\Scoping;

use App\Models\Entity;
use App\Models\Risk;
use App\Models\RiskLibraryItem;
use App\Policies\EntityPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class AttachLibraryRisksToEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $entity = $this->route('scoping');

        if ($user === null || ! $entity instanceof Entity) {
            return false;
        }

        return $user->can('create', Risk::class)
            && EntityPolicy::withinReach($user, $entity);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = (int) ($this->user()?->organization_id ?? TenantContext::organizationId());

        return [
            'library_item_ids' => ['required', 'array', 'min:1'],
            'library_item_ids.*' => [
                'integer',
                Rule::exists('risk_library_items', 'id')
                    ->where('organization_id', $orgId)
                    ->where('status', RiskLibraryItem::STATUS_ACTIVE),
            ],
            'process_id' => [
                'nullable',
                'integer',
                Rule::exists('business_processes', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'risk_owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $orgId),
            ],
            'business_unit_id' => [
                'nullable',
                'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $orgId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'library_item_ids.required' => 'Select at least one active Risk Library item to attach.',
            'library_item_ids.min' => 'Select at least one active Risk Library item to attach.',
            'library_item_ids.*.exists' => 'One or more selected Risk Library items are retired or do not belong to your organization.',
        ];
    }
}
