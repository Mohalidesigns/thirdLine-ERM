<?php

namespace App\Http\Requests\Rcsa;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Close a cycle (rcsa.cycles.close).
 *
 * `reason` is optional — the screen does not yet ask for one, and a closer
 * moving a whole cycle at the end of its period is the ordinary case, not one
 * that needs justifying the way a return or an escalation does. When one is
 * given it is carried onto every transition row the close writes, the same as
 * the rest of §9.1's workflow.
 */
class CloseCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('close', $this->route('cycle'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
