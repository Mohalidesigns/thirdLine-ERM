<?php

namespace App\Http\Requests\Kri;

use App\Models\OrganizationHoliday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Add a holiday (ADR 0026 section 3): one row is one Africa/Lagos calendar date,
 * unique per organisation.
 */
class StoreOrganizationHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', OrganizationHoliday::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'holiday_date' => [
                'required', 'date_format:Y-m-d',
                Rule::unique('organization_holidays', 'holiday_date')->where('organization_id', TenantContext::organizationId()),
            ],
            'name' => ['required', 'string', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['holiday_date.unique' => 'That date is already on the holiday list.'];
    }
}
