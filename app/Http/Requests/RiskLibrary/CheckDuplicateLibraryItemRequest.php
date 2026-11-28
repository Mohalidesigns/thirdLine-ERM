<?php

namespace App\Http\Requests\RiskLibrary;

use App\Models\RiskLibraryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

class CheckDuplicateLibraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', RiskLibraryItem::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'title' => ['nullable', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('risk_categories', 'id')->where('organization_id', $orgId),
            ],
            'exclude_id' => [
                'nullable',
                'integer',
                Rule::exists('risk_library_items', 'id')->where('organization_id', $orgId),
            ],
        ];
    }
}
