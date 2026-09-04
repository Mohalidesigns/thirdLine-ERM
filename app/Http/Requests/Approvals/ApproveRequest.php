<?php

namespace App\Http\Requests\Approvals;

use Illuminate\Foundation\Http\FormRequest;

class ApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('approve', $this->route('approval'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['comments' => ['nullable', 'string', 'max:1000']];
    }
}
