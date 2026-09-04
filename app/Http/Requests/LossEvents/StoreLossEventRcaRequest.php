<?php

namespace App\Http\Requests\LossEvents;

use App\Models\LossEvent;
use App\Models\LossEventRca;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Record or revise the root cause analysis (migration Phase 4.3). */
class StoreLossEventRcaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('lossEvent');

        return $event instanceof LossEvent && $this->user()->can('recordRca', $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'root_cause_category' => ['required', Rule::in(LossEventRca::CATEGORIES)],
            'root_cause_description' => ['required', 'string', 'max:5000'],
            'contributing_factors' => ['nullable', 'string', 'max:3000'],
            'methodology' => ['required', Rule::in(LossEventRca::METHODOLOGIES)],
            'analysis_details' => ['nullable', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'string', 'max:3000'],
            'lessons_learned' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
