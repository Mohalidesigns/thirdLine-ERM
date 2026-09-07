<?php

namespace App\Http\Requests\Tprm;

use App\Enums\Tprm\ThirdPartyStatus;
use App\Models\Tprm\Category;
use App\Models\Tprm\ThirdParty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Registering a third party — FR-TPR-01.
 *
 * EVERY FOREIGN KEY IS TENANT-BOUND. Development standard §4 forbids a bare
 * `exists:table,id` because across a tenant boundary it is an existence
 * oracle: validation passing for another organisation's category id and
 * failing for one that exists nowhere answers a question the caller is not
 * entitled to ask, one id at a time.
 */
class StoreThirdPartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Asserts what actually guards the route, per standard §3.
        return $this->user()?->can('create', ThirdParty::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationIdOrNull();

        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],

            // Not unique-validated. FR-TPR-02 is explicit that a duplicate
            // identifier surfaces merge candidates rather than blocking, and a
            // `unique` rule here would be exactly the silent block it forbids.
            'registration_number' => ['nullable', 'string', 'max:60'],
            'tax_id' => ['nullable', 'string', 'max:60'],
            'lei' => ['nullable', 'string', 'size:20'],

            'entity_type' => ['nullable', Rule::in(ThirdParty::ENTITY_TYPES)],
            'ownership_type' => ['nullable', 'string', 'max:40'],
            'country_of_incorporation' => ['nullable', 'string', 'size:2'],
            'country_of_hq' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'url', 'max:255'],
            'year_established' => ['nullable', 'integer', 'min:1800', 'max:'.date('Y')],
            'employee_band' => ['nullable', 'string', 'max:30'],

            'ultimate_parent_id' => [
                'nullable',
                Rule::exists('tp_third_parties', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'is_intra_group' => ['boolean'],

            'category_id' => [
                'nullable',
                Rule::exists('tp_categories', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            'relationship_owner_id' => ['nullable', $this->userInTenant($organizationId)],
            'oversight_owner_id' => ['nullable', $this->userInTenant($organizationId)],

            'status' => ['nullable', Rule::in(ThirdPartyStatus::values())],
            'notes' => ['nullable', 'string', 'max:5000'],

            // The confirmation that the merge-candidate panel was looked at.
            'accept_duplicate' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lei.size' => 'A Legal Entity Identifier is exactly 20 characters.',
            'category_id.exists' => 'That category does not belong to your organisation.',
        ];
    }

    private function userInTenant(?int $organizationId): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('users', 'id')
            ->where('organization_id', $organizationId)
            ->where('is_active', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'legal_name' => 'legal name',
            'registration_number' => 'RC number',
            'tax_id' => 'TIN',
            'lei' => 'LEI',
        ];
    }
}
