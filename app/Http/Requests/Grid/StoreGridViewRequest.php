<?php

namespace App\Http\Requests\Grid;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/grids/{grid}/views — save the current filters as a named view.
 */
class StoreGridViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'as_default' => ['nullable', 'boolean'],
        ];
    }
}
