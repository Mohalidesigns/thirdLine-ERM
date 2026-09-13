<?php

namespace App\Http\Requests\Approvals;

use Illuminate\Foundation\Http\FormRequest;

class RejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reject', $this->route('approval'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['rejection_reason' => ['required', 'string', 'max:1000']];
    }
}
