<?php

namespace App\Http\Requests\Controls;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Control;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateControlRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        $control = $this->route('control');

        return $control instanceof Control && $this->user()->can('update', $control);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'control_type' => ['required', Rule::in(Control::TYPES)],
            'control_nature' => ['nullable', Rule::in(Control::NATURES)],
            'frequency' => ['nullable', Rule::in(Control::FREQUENCIES)],
            'owner_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'business_unit_id' => ['nullable', Rule::exists('business_units', 'id')->where('organization_id', $orgId)],
            'effectiveness_rating' => ['nullable', Rule::in(Control::EFFECTIVENESS_RATINGS)],
            'status' => ['nullable', Rule::in(Control::STATUSES)],
            ...$this->configuredAttributeRules('Control'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('Control');
    }
}
