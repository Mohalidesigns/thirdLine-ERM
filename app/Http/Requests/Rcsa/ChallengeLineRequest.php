<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaScaleItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The ORM's challenge on one line (§9.2): a comment, and optionally the rating
 * the reviewer thinks it should carry.
 *
 * THE SUGGESTED RATING IS VALIDATED AGAINST THE LINE'S OWN METHODOLOGY, exactly
 * as the assessor's answer is — the same reason, too: a suggestion the assessor
 * could not legally apply is not a suggestion, it is a trap. And it is
 * validated even though nothing applies it, because a challenge that says
 * "should be a 7" on a five-point scale would sit in the audit trail for ever.
 *
 * THE COMMENT IS MANDATORY AND THE RATING IS NOT. A flag with no words is the
 * thing that makes review cycles run twice — the assessor is sent back a row
 * with a red mark and no idea what to change.
 */
class ChallengeLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $line = $this->route('line');

        return $line instanceof RcsaAssessmentLine
            && $this->user()->can('review', $line->assessment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $methodology = $this->methodology();

        $likelihood = array_keys($methodology?->scale(RcsaScaleItem::TYPE_LIKELIHOOD) ?? []);
        $impact = array_keys($methodology?->scale(RcsaScaleItem::TYPE_IMPACT) ?? []);
        $controls = array_values(array_map(
            fn (RcsaScaleItem $item) => $item->label,
            $methodology?->scale(RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS) ?? []
        ));

        return [
            'body' => ['required', 'string', 'min:10', 'max:2000'],

            // `challenged` when there is a rating to argue about, `flagged`
            // when the reviewer simply wants it looked at again. Both reopen
            // the line on return — see RcsaAssessmentLine::ORM_REOPENS.
            'verdict' => ['nullable', Rule::in(RcsaAssessmentLine::ORM_REOPENS)],

            'suggested.inherent_likelihood' => ['nullable', 'integer', Rule::in($likelihood)],
            'suggested.inherent_impact' => ['nullable', 'integer', Rule::in($impact)],
            'suggested.control_effectiveness' => ['nullable', 'string', Rule::in($controls)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'Say what you want changed — a flag with no words sends the assessment back twice.',
            'body.min' => 'Give the assessor enough to act on.',
        ];
    }

    /**
     * The suggested values, with the nulls stripped.
     *
     * @return array<string, mixed>
     */
    public function suggestedValues(): array
    {
        return array_filter(
            (array) data_get($this->validated(), 'suggested', []),
            fn ($value) => $value !== null && $value !== '',
        );
    }

    private function methodology(): ?RcsaMethodology
    {
        $line = $this->route('line');

        if (! $line instanceof RcsaAssessmentLine) {
            return null;
        }

        return RcsaMethodology::withoutGlobalScopes()
            ->with(['scaleItems', 'bands'])
            ->find($line->methodology_id);
    }
}
