<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET api/v1/measures/{measure}/series — recorded values over a period range.
 */
class MeasureSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'object_id' => ['required', 'integer'],
            'scenario' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'period_type' => ['nullable', 'string', 'max:20'],
        ];
    }
}
