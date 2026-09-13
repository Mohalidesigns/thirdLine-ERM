<?php

namespace App\Http\Requests\Issues;

use App\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;

/** Record progress against an issue (migration Phase 4.4). */
class StoreProgressUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $issue = $this->route('issue');

        return $issue instanceof Issue && $this->user()->can('recordProgress', $issue);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:3000'],
            'update_type' => ['required', 'in:progress,milestone,escalation,note'],
            'progress_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }
}
