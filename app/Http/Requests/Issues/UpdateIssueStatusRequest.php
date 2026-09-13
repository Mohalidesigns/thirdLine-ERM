<?php

namespace App\Http\Requests\Issues;

use App\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move an issue along its lifecycle (migration Phase 4.4).
 *
 * WHICH transition is legal stays in the controller — an illegal one is
 * answered with a flash message rather than a 403, as everywhere else. This
 * only says the target is a status the product has.
 */
class UpdateIssueStatusRequest extends FormRequest
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
            'issue_status' => ['required', Rule::in(Issue::STATUSES)],
            'status_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
