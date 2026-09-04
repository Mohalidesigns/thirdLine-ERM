<?php

namespace App\Http\Requests\Register;

use App\Http\Requests\Concerns\ValidatesConfiguredAttributes;
use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH risk/register/{register}/attributes — the Attributes tab, which
 * edits only the tenant-configured fields (migration Phase 3.2).
 *
 * This is the HTTP form of what the Livewire DynamicForm component's save()
 * did. The rules are derived from the attribute definitions by the shared
 * trait, so a tenant's `min:3` is enforced by the server rather than by the
 * input's `minlength`; a field the caller may not see gets no rule, so
 * nothing posted under its name is validated into existence.
 */
class UpdateRiskAttributesRequest extends FormRequest
{
    use ValidatesConfiguredAttributes;

    public function authorize(): bool
    {
        $risk = $this->route('register');

        return $risk instanceof Risk && ($this->user()?->can('updateAttributes', $risk) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'configured_attributes' => ['nullable', 'array'],
            ...$this->configuredAttributeRules('Risk'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->configuredAttributeLabels('Risk');
    }
}
