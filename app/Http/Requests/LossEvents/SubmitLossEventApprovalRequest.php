<?php

namespace App\Http\Requests\LossEvents;

use App\Models\LossEvent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Record an approval decision on a loss event (migration Phase 4.3).
 *
 * Authorised through the policy's `approve`, which is the absorbed
 * `approve-loss-event` closure — the assigned handler or an approver-class
 * role. The route middleware still requires `loss_event.approve`.
 */
class SubmitLossEventApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('lossEvent');

        return $event instanceof LossEvent && $this->user()->can('approve', $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approved,rejected,escalated'],
            'comments' => ['nullable', 'string', 'max:2000'],
            'rejection_reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000'],
            'approval_level' => ['nullable', 'in:level_1,level_2,level_3'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rejection_reason.required_if' => 'A reason is required to reject a loss event.',
        ];
    }
}
