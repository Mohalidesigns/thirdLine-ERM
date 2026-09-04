<?php

namespace App\Http\Requests\Controls;

use App\Models\ControlTest;
use Illuminate\Foundation\Http\FormRequest;

/** Approve or reject a test pending review (risk.control-tests.review). */
class ReviewControlTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $test = $this->route('controlTest');

        return $test instanceof ControlTest && $this->user()->can('review', $test);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject'],
            'reviewer_notes' => ['nullable', 'string', 'max:3000'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rejection_reason.required_if' => 'A reason is required to reject a test.',
        ];
    }

    public function approves(): bool
    {
        return $this->validated('action') === 'approve';
    }

    /** The comment the workflow records: notes on approval, the reason on rejection. */
    public function comments(): ?string
    {
        return $this->approves()
            ? $this->validated('reviewer_notes')
            : ($this->validated('rejection_reason') ?? $this->validated('reviewer_notes'));
    }
}
