<?php

namespace App\Http\Requests\Issues;

use App\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Send a closure request back (migration Phase 4.4).
 *
 * Authorised with `close` rather than `update`: accepting or refusing a closure
 * is the reviewer's act, not the owner's.
 */
class RejectIssueClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()->can('close', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
