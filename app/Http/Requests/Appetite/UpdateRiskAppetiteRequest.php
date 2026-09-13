<?php

namespace App\Http\Requests\Appetite;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRiskAppetiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('appetite'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('unit_of_measure') === null || $this->input('unit_of_measure') === '') {
            $this->merge(['unit_of_measure' => 'percentage']);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return StoreRiskAppetiteRequest::statementRules();
    }
}
