<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\DrTestType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBcmsDrTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.dr.test.record') === true;
    }

    /**
     * Gate 2 review #1 defect 12. Two gaps, both against real cross-tenant
     * and future-dated data rather than a report:
     *
     * - `test_date` had no upper bound, so a future date recorded here would
     *   move `next_test_due` forward from a test that has not happened yet.
     * - `occurrence_id` was a bare `['nullable', 'integer']` — no `exists`
     *   check at all, let alone one scoped to the organisation, so this
     *   accepted any integer, including another tenant's occurrence id.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'test_type' => ['required', Rule::enum(DrTestType::class)],
            'test_date' => ['required', 'date', 'before_or_equal:today'],
            'rto_actual_minutes' => ['nullable', 'integer', 'min:0'],
            'rpo_actual_minutes' => ['nullable', 'integer', 'min:0'],
            'rollback_required' => ['nullable', 'boolean'],
            'issues' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'occurrence_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_exercise_occurrences', 'id')->where('organization_id', $organizationId),
            ],
        ];
    }
}
