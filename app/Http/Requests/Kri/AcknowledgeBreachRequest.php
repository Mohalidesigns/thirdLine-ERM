<?php

namespace App\Http\Requests\Kri;

use App\Models\MeasureBreach;
use Illuminate\Foundation\Http\FormRequest;

/** Acknowledge an open breach (migration Phase 4.1). */
class AcknowledgeBreachRequest extends FormRequest
{
    public function authorize(): bool
    {
        $breach = $this->route('breach');

        return $breach instanceof MeasureBreach && $this->user()->can('acknowledge', $breach);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
            'root_cause' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
