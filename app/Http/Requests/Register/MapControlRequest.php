<?php

namespace App\Http\Requests\Register;

use App\Models\Risk;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST risk/register/{register}/map-control — the inline "Map Existing
 * Control" form on the Controls tab (migration Phase 3.2).
 *
 * The control has to be one of the tenant's own. The controller used to
 * check that with a second query after a bare `exists:` rule; the scoped
 * rule makes the validator say so, as a 422 on control_id, instead.
 */
class MapControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        $risk = $this->route('register');

        return $risk instanceof Risk && ($this->user()?->can('mapControl', $risk) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'control_id' => ['required', Rule::exists('controls', 'id')->where('organization_id', TenantContext::organizationId())],
            'is_key_control' => ['nullable', 'boolean'],
            'control_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'mapping_rationale' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
