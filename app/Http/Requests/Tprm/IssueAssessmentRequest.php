<?php

namespace App\Http\Requests\Tprm;

use App\Models\Tprm\Assessment;
use App\Models\Tprm\Engagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * Issuing a questionnaire against an engagement.
 *
 * The template rule is the interesting one: a template is valid if it belongs
 * to this tenant OR to no tenant at all — the shipped packs carry a null
 * organisation and are readable by everybody. A plain tenant-bound `exists`
 * would make every shipped pack un-issuable.
 */
class IssueAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tprm.assessment.issue') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = TenantContext::organizationIdOrNull();

        return [
            'engagement_id' => [
                'required',
                Rule::exists('tp_engagements', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            'template_id' => [
                'required',
                Rule::exists('tp_questionnaire_templates', 'id')
                    ->where('status', 'published')
                    ->whereNull('deleted_at')
                    // Either the tenant's own or a shipped pack.
                    ->where(fn ($query) => $query
                        ->where('organization_id', $organizationId)
                        ->orWhereNull('organization_id')),
            ],

            'assessment_type' => ['nullable', Rule::in(Assessment::TYPES)],
            'due_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template_id.exists' => 'That questionnaire is not published, or does not belong to your organisation.',
            'due_at.after' => 'A due date in the past would make the assessment overdue the moment it is issued.',
        ];
    }
}
