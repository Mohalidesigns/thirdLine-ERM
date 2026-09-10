<?php

namespace App\Http\Requests\Bcms;

use App\Enums\Bcms\PlanType;
use App\Support\Bcms\PlanTemplates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a plan.
 *
 * THE TEMPLATE IS VALIDATED AGAINST THE CODE PACK, not against a table. There is
 * no `bcms_plan_templates` table by design (ADR 0011), so `Rule::in` over
 * `PlanTemplates::keys()` is the check — and it is a check rather than a trusting
 * pass-through because `PlanAssembler::applyTemplate()` throws on an unknown key,
 * and a 500 is a worse answer to a stale bookmark than a validation message.
 *
 * A PLAN'S BUSINESS UNIT AND SITE DECIDE WHAT ITS BOUND SECTIONS RESOLVE TO, so
 * they are scoped to the tenant here rather than trusted: a departmental plan
 * pointed at another bank's business unit would render that bank's recovery
 * objectives.
 */
class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.plan.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->user()?->organization_id;

        return [
            'plan_type' => ['required', Rule::in(array_column(PlanType::cases(), 'value'))],
            'title' => ['required', 'string', 'max:250'],
            'template_key' => ['nullable', Rule::in(PlanTemplates::keys())],
            'business_unit_id' => [
                'nullable', 'integer',
                Rule::exists('business_units', 'id')->where('organization_id', $organizationId),
            ],
            'site_id' => [
                'nullable', 'integer',
                Rule::exists('bcms_sites', 'id')->where('organization_id', $organizationId),
            ],
            'owner_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
            ],
            'review_frequency_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'distribution_rule' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = PlanType::tryFrom((string) $this->input('plan_type'));
            $key = $this->input('template_key');

            if ($type === null || blank($key)) {
                return;
            }

            $template = PlanTemplates::find((string) $key);

            // A crisis management template applied to a site plan produces a
            // document whose sections contradict its own title, and nobody
            // notices until an exercise.
            if ($template !== null && $template['plan_type'] !== $type->value) {
                $validator->errors()->add(
                    'template_key',
                    'The "'.$template['label'].'" template is written for a '
                    .PlanType::from($template['plan_type'])->label().', not a '.$type->label().'.'
                );
            }
        });
    }
}
