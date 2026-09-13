<?php

namespace App\Http\Requests\Ai;

use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Shared shape of the AI drafting endpoints.
 *
 * All six take free text a model is about to be prompted with, and all six sit
 * behind `permission:ai.use` — a separate grant from `ai.view` because reading
 * a generated analysis and spending money on a live model call are different
 * acts.
 *
 * THE `risk_id` RULE IS TENANT-BOUND HERE, AND WAS NOT BEFORE. Four of these
 * endpoints validated it as `nullable|exists:risks,id` — a bare exists across
 * a tenant boundary, the defect family Phase 6.3 cleared out of the Form
 * Requests. It was not a leak: the controller's lookup is scoped to the
 * organisation, so a foreign id resolves to null and the posted text is used
 * instead. It was an existence ORACLE — validation passed for another
 * organisation's risk id and failed for one that does not exist anywhere, which
 * answers a question the caller is not entitled to ask.
 */
abstract class AiToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ai.use') ?? false;
    }

    /**
     * A risk in the CALLER'S organisation, or nothing.
     */
    protected function tenantRiskRule(): array
    {
        return [
            'nullable',
            Rule::exists(Risk::class, 'id')->where('organization_id', TenantContext::organizationIdOrNull()),
        ];
    }
}
