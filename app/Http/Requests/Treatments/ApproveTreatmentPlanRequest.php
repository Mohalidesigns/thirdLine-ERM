<?php

namespace App\Http\Requests\Treatments;

use App\Models\TreatmentPlan;
use Illuminate\Foundation\Http\FormRequest;

/** Approve a plan pending review (risk.treatments.approve). */
class ApproveTreatmentPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('treatment');

        return $plan instanceof TreatmentPlan && $this->user()->can('approve', $plan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comments' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
