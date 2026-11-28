<?php

namespace App\Http\Requests\RiskLibrary;

use App\Models\RiskLibraryItem;
use Illuminate\Foundation\Http\FormRequest;

class RetireRiskLibraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof RiskLibraryItem
            && ($this->user()?->can('retire', $item) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'retirement_reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
