<?php

namespace App\Http\Requests\Tprm;

use App\Enums\Tprm\RiskTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving a draft ruleset.
 *
 * THE WEIGHTS ARE NOT REQUIRED TO TOTAL 100 HERE, only at publish. A risk
 * officer editing seven weights passes through a dozen invalid intermediate
 * states on the way to a valid one, and a validator that rejects each of them
 * makes the editor unusable. Publish is where it has to hold, and that is
 * where it is checked.
 *
 * Knockout conditions are validated as SHAPE only — that they are arrays the
 * DSL can walk. Whether a rule is a good rule is a risk judgement, and the
 * sandbox is how it gets made.
 */
class SaveRulesetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tprm.ruleset.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'factors' => ['required', 'array', 'min:1'],
            'factors.*.label' => ['required', 'string', 'max:160'],
            'factors.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'factors.*.options' => ['required', 'array', 'min:1'],
            'factors.*.options.*.value' => ['required', 'string', 'max:60'],
            'factors.*.options.*.label' => ['required', 'string', 'max:200'],
            // A factor score outside 0–1 breaks the weighted mean's range, and
            // the resulting IR would no longer be a 0–100 number.
            'factors.*.options.*.score' => ['required', 'numeric', 'min:0', 'max:1'],

            'knockouts' => ['present', 'array'],
            'knockouts.*.code' => ['required', 'string', 'max:40'],
            'knockouts.*.name' => ['required', 'string', 'max:255'],
            'knockouts.*.floor' => ['required', Rule::in(RiskTier::values())],
            // A rule with no citation is a rule a user cannot check. AC-02
            // requires the citation to be displayed, so it cannot be optional.
            'knockouts.*.citation' => ['required', 'string', 'max:255'],
            'knockouts.*.condition' => ['required', 'array'],
            'knockouts.*.suspends' => ['boolean'],

            'band_edges' => ['required', 'array'],
            'band_edges.*' => ['array', 'size:2'],
            'band_edges.*.*' => ['integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'knockouts.*.citation.required' => 'Every knockout needs a citation — a rule a user cannot check is a '
                .'rule they will not trust.',
            'factors.*.options.*.score.max' => 'A factor option scores between 0 and 1; the weight is what scales it.',
        ];
    }
}
