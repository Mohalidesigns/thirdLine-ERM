<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaActionPlan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The owner's own updates to a plan in the register (§9.3) — progress,
 * completion with evidence, and an extension request.
 *
 * COMPLETION REQUIRES EVIDENCE, and the rule lives here rather than only in the
 * service so the owner is told at the moment they press the button. "Done" with
 * nothing behind it is what makes a remediation register worthless: the ORM
 * verifying closure has to have something to verify.
 *
 * AN EXTENSION NEEDS A FUTURE DATE AND A REASON. Asking to move a deadline into
 * the past is not an extension, and an extension with no argument is a deadline
 * that was never real.
 */
class UpdateActionPlanProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof RcsaActionPlan && $this->user()->can('update', $plan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('rcsa.action-plans.complete') => [
                'completion_evidence' => ['required', 'string', 'min:10', 'max:4000'],
            ],
            $this->routeIs('rcsa.action-plans.extension') => [
                'proposed_target_date' => ['required', 'date', 'after:today'],
                'extension_reason' => ['required', 'string', 'min:10', 'max:2000'],
            ],
            default => [
                'progress_pct' => ['required', 'integer', 'min:0', 'max:100'],
                'note' => ['nullable', 'string', 'max:2000'],
            ],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'completion_evidence.required' => 'Say what was put in place, and how somebody else can check it.',
            'completion_evidence.min' => 'Enough detail for the ORM to verify it without asking you.',
            'proposed_target_date.required' => 'Give the new date you are asking for.',
            'proposed_target_date.after' => 'The new date needs to be in the future.',
            'extension_reason.required' => 'Say why the original date cannot be met.',
        ];
    }
}
