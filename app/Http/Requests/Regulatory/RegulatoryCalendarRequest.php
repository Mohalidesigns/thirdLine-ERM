<?php

namespace App\Http\Requests\Regulatory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET risk/regulatory/calendar — filing deadlines for a month.
 */
class RegulatoryCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('regulatory.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
