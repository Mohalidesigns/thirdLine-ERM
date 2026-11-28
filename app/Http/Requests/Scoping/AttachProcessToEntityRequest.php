<?php

namespace App\Http\Requests\Scoping;

use App\Models\Entity;
use App\Policies\EntityPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class AttachProcessToEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $entity = $this->route('scoping');

        if ($user === null || ! $entity instanceof Entity) {
            return false;
        }

        return $user->can('process.update')
            && EntityPolicy::withinReach($user, $entity);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = (int) ($this->user()?->organization_id ?? TenantContext::organizationId());

        return [
            'business_process_id' => [
                'required_without:business_process_ids',
                'nullable',
                'integer',
                Rule::exists('business_processes', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'business_process_ids' => [
                'required_without:business_process_id',
                'nullable',
                'array',
                'min:1',
            ],
            'business_process_ids.*' => [
                'integer',
                Rule::exists('business_processes', 'id')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_process_id.required_without' => 'Select at least one business process to attach.',
            'business_process_ids.required_without' => 'Select at least one business process to attach.',
            'business_process_id.exists' => 'Selected business process does not belong to your organization.',
            'business_process_ids.*.exists' => 'One or more selected business processes do not belong to your organization.',
        ];
    }

    /**
     * Normalise single or multi-process input into a deduplicated list of IDs.
     *
     * @return list<int>
     */
    public function resolvedProcessIds(): array
    {
        $ids = [];

        if ($this->filled('business_process_id')) {
            $ids[] = (int) $this->input('business_process_id');
        }

        foreach ((array) $this->input('business_process_ids', []) as $id) {
            if ($id !== null && $id !== '') {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
