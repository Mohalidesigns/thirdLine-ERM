<?php

namespace App\Http\Requests\Ai;

/** Draft a KRI description from a scenario. */
class DraftKriDescriptionRequest extends AiToolRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scenario' => ['required', 'string', 'min:3', 'max:2000'],
            'name' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'measurement_unit' => ['nullable', 'string', 'max:50'],
        ];
    }
}
