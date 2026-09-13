<?php

namespace App\Http\Requests\Ai;

/**
 * Draft controls for a risk — either one on the register, or a title and
 * description typed into the builder before the risk exists.
 *
 * `required_without:risk_id` is what makes both paths work: the model needs
 * something to reason about, and it can come from the record or from the form.
 */
class SuggestControlsRequest extends AiToolRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'risk_id' => $this->tenantRiskRule(),
            'title' => ['required_without:risk_id', 'string', 'max:500'],
            'description' => ['required_without:risk_id', 'string', 'max:3000'],
            'category' => ['nullable', 'string', 'max:100'],
        ];
    }
}
