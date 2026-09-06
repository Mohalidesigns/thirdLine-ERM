<?php

namespace App\Http\Requests\Ai;

/**
 * Turn a scenario in the user's words into a structured risk statement.
 */
class DraftRiskStatementRequest extends AiToolRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scenario' => ['required', 'string', 'min:3', 'max:2000'],
            'category' => ['nullable', 'string', 'max:100'],
            'business_unit' => ['nullable', 'string', 'max:100'],
        ];
    }
}
