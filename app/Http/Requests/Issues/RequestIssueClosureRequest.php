<?php

namespace App\Http\Requests\Issues;

use App\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ask for an issue to be closed (migration Phase 4.4).
 *
 * The justification is required and always was: closing a regulatory finding
 * without one leaves an examiner nothing to read.
 */
class RequestIssueClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()->can('update', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'closure_justification' => ['required', 'string', 'max:3000'],
            'evidence_of_resolution' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
