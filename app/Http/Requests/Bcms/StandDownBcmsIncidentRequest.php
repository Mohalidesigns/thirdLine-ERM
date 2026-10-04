<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `incident-stand-down.md` §2 — the all-clear message and the resolution
 * reason.
 *
 * GAP 4: `dispatch_all_clear` IS DELIBERATELY REMOVED. It was accepted here
 * and never read by `IncidentService::standDown()` — a checkbox the screen
 * offered that did nothing. Composing and dispatching an ALLCLEAR alert
 * belongs with the EMNS work (`Alert`, `AlertController`, `AudienceRule`) —
 * out of this change's scope — so rather than leave a field that silently
 * does nothing, it is refused outright: a caller who still sends it gets a
 * validation error naming the field, not a quiet no-op. See this change's
 * handoff for the screen copy and control to remove alongside it.
 */
class StandDownBcmsIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.incident.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'all_clear_message' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'],
            'dispatch_all_clear' => ['prohibited'],
            'plans_remaining_active' => ['nullable', 'array'],
            'plans_remaining_active.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
