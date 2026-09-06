<?php

namespace App\Http\Requests\Regulatory;

use App\Models\RiskTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Add a node to the risk taxonomy (migration Phase 5.3).
 *
 * `parent_id` HAD NO RULE. The controller took it from the request body,
 * looked it up with `RiskTaxonomy::find()` to read its depth, and wrote it —
 * so a node could be parented under another institution's tree. The global
 * tenant scope on the model saved the depth lookup (find() returned null,
 * silently giving depth 0), but the id itself still went into the column.
 */
class StoreTaxonomyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', RiskTaxonomy::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'framework' => ['nullable', 'string', 'max:50'],
            'parent_id' => ['nullable', 'integer', Rule::exists('risk_taxonomies', 'id')->where('organization_id', $orgId)],
        ];
    }
}
