<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class ActOnTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('act', $this->route('task'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', 'max:30'],
            'comments' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
