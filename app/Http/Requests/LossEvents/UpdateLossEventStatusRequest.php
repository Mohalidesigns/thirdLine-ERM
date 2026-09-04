<?php

namespace App\Http\Requests\LossEvents;

use App\Models\LossEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move a loss event along its lifecycle (migration Phase 4.3).
 *
 * WHICH transition is legal stays in the controller, where an illegal one is
 * answered with a flash message rather than a 403 — the same rule Phase 3
 * settled for every approval screen. This only says the target is a status the
 * product has.
 */
class UpdateLossEventStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('lossEvent');

        return $event instanceof LossEvent && $this->user()->can('update', $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(LossEvent::STATUSES)],
            'status_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
