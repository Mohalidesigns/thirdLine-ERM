<?php

namespace App\Http\Requests\Bcms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finalising a post-incident review — the realised-loss confirmation
 * (Gate 1 re-gate, code review #2, defect 7). The pinned contract: a
 * REQUIRED top-level `realised_loss_minor` (integer, minor units, 0 = no
 * realised loss). `PirService::finalise()` stores it on the review and, when
 * greater than zero on a non-exercise incident, mirrors it into the ERM
 * loss-event register through `LossEventService`.
 *
 * `bcms.incident.manage` + `bcms.aar.approve` are both required (defect 5) —
 * enforced again here in addition to the route's own conjunction middleware,
 * the same belt-and-braces the export routes already carry.
 */
class FinaliseBcmsPirRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('bcms.incident.manage') && $user->can('bcms.aar.approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'realised_loss_minor' => ['required', 'integer', 'min:0'],
        ];
    }
}
