<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class ReturnTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('return', $this->route('task'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'to_node' => ['nullable', 'string', 'max:80'],
        ];
    }
}
