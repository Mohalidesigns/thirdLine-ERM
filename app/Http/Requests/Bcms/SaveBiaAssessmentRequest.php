<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The recovery objectives form.
 *
 * THE FORM VALIDATES SHAPE; THE SERVICE VALIDATES MEANING. "An RTO is a
 * non-negative number under three years" belongs here. "An RTO longer than the
 * MTPD is a contradiction" belongs in `BiaValidator`, because the AI drafter and
 * the seeder reach the same rule without passing through a form — and a rule
 * only a form enforces is one three other paths can walk around.
 */
class SaveBiaAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.bia.complete') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Three years in hours. Not a business rule — a typo guard: an MTPD
            // of 40000 is somebody entering minutes in an hours field, and
            // catching it here is kinder than deriving a tier from it.
            'mtpd_hours' => ['nullable', 'numeric', 'min:0', 'max:26280'],
            'rto_hours' => ['nullable', 'numeric', 'min:0', 'max:26280'],
            'rpo_minutes' => ['nullable', 'integer', 'min:0', 'max:1576800'],
            'mbco_description' => ['nullable', 'string', 'max:5000'],
            'min_staff_required' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'peak_periods' => ['nullable', 'array'],
            'peak_periods.*' => ['string', 'max:120'],
            'workaround_available' => ['nullable', 'boolean'],
            'workaround_max_duration_hours' => ['nullable', 'numeric', 'min:0', 'max:26280'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mtpd_hours.max' => 'That is more than three years. Recovery objectives are in HOURS — check whether you meant minutes.',
            'rto_hours.max' => 'That is more than three years. Recovery objectives are in HOURS — check whether you meant minutes.',
        ];
    }
}
