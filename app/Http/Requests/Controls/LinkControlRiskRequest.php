<?php

namespace App\Http\Requests\Controls;

use App\Models\Control;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Map a control onto a risk (risk.controls.link-risk). */
class LinkControlRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $control = $this->route('control');

        return $control instanceof Control && $this->user()->can('linkRisk', $control);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'risk_id' => ['required', Rule::exists('risks', 'id')->where('organization_id', TenantContext::organizationId())],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rationale' => ['nullable', 'string', 'max:1000'],
            'is_key_control' => ['nullable', 'boolean'],
        ];
    }
}
