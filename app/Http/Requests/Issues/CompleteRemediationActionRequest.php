<?php

namespace App\Http\Requests\Issues;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST risk/issues/{issue}/actions/{action}/complete.
 */
class CompleteRemediationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue !== null && ($this->user()?->can('update', $issue) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'completion_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
