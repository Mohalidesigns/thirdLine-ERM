<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\DrStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBcmsDrSystemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.dr.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;
        $system = $this->route('system');

        return [
            'name' => ['required', 'string', 'max:200'],
            'application_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_applications', 'id')->where('organization_id', $organizationId),
            ],
            'recovery_tier' => ['nullable', 'integer', 'min:1', 'max:10'],
            'rto_target_hours' => ['nullable', 'numeric', 'min:0'],
            'rpo_target_minutes' => ['nullable', 'integer', 'min:0'],
            'dr_strategy' => ['nullable', Rule::enum(DrStrategy::class)],
            'dr_site_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_sites', 'id')->where('organization_id', $organizationId),
            ],
            'failover_runbook_plan_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_plans', 'id')->where('organization_id', $organizationId),
            ],
            'backup_frequency' => ['nullable', 'string', 'max:40'],
            'replication_type' => ['nullable', 'string', 'max:40'],
        ];
    }
}
