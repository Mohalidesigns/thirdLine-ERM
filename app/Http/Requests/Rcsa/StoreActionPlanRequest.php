<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaAssessmentLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use ThirdLine\Platform\Tenancy\TenantContext;

/**
 * The workbook's columns U, V and W — what will be done about a risk above
 * appetite, by whom, and by when.
 *
 * ALL THREE ARE REQUIRED TOGETHER, which is rule 6 of the process flow. A
 * control with no owner is a wish; an owner with no date is a wish with a name
 * on it. The submission gate checks the same three through
 * RcsaActionPlan::isComplete(), so a plan cannot be saved incomplete here and
 * then quietly pass there.
 *
 * THE DATE MUST BE IN THE FUTURE ON CREATE. A remediation due last month is not
 * a plan, and accepting one at the moment of assessment is how an action-plan
 * register fills up with items that were overdue before they existed. It is
 * NOT re-checked on update: a plan whose date has since passed is genuinely
 * overdue, and refusing to let anyone edit it would make the overdue item
 * uneditable — which is exactly when somebody needs to change it.
 */
class StoreActionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $line = $this->route('line');

        return $line instanceof RcsaAssessmentLine
            && $this->user()->can('complete', $line->assessment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $orgId = TenantContext::organizationId();
        $isCreate = $this->route('plan') === null;

        return [
            'control_to_implement' => ['required', 'string', 'min:10', 'max:2000'],
            'owner_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $orgId)->where('is_active', true),
            ],
            'target_date' => array_filter([
                'required',
                'date',
                $isCreate ? 'after:today' : null,
            ]),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'control_to_implement.required' => 'Say what will be put in place.',
            'control_to_implement.min' => 'Describe the control in a little more detail — enough for the owner to act on.',
            'owner_id.required' => 'Name the person accountable for doing it.',
            'owner_id.exists' => 'That person is not an active user here.',
            'target_date.required' => 'Give a date by which it will be in place.',
            'target_date.after' => 'The implementation date needs to be in the future.',
        ];
    }
}
