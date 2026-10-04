<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Logging a timeline entry during an exercise (execution-workspace spec §4).
 *
 * `entry_type` IS RESTRICTED TO THE FIVE THE SCREEN SHOWS. `system` is
 * reserved for entries the product writes itself (start, complete, abort,
 * reopen) — a facilitator posting `system` by hand would let a hand-typed
 * entry masquerade as one the platform generated, which is exactly the kind
 * of provenance confusion an examiner's "who logged this, and how" question
 * exists to catch.
 */
class StoreTimelineEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.exercise.facilitate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entry_type' => ['required', Rule::in(['manual', 'decision', 'milestone'])],
            'content' => ['required', 'string', 'max:2000'],
        ];
    }
}
