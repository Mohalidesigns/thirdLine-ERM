<?php

namespace App\Http\Requests\RiskLibrary;

use App\Models\Risk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewLibraryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $risk = $this->route('register');

        return $risk instanceof Risk
            && ($this->user()?->can('update', $risk) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'dismiss'])],
        ];
    }
}
