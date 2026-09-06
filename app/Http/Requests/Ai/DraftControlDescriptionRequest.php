<?php

namespace App\Http\Requests\Ai;

/** Draft a control description from a scenario. */
class DraftControlDescriptionRequest extends AiToolRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:200'],
            'scenario' => ['required', 'string', 'min:3', 'max:2000'],
            'control_type' => ['nullable', 'string', 'max:50'],
            'control_nature' => ['nullable', 'string', 'max:50'],
            'frequency' => ['nullable', 'string', 'max:50'],
        ];
    }
}
