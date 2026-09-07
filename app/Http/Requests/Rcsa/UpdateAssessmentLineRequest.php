<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaAssessmentLine;
use App\Models\Rcsa\RcsaMethodology;
use App\Models\Rcsa\RcsaRiskBand;
use App\Models\Rcsa\RcsaScaleItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Answer one line — the per-cell autosave of the grid (rcsa.assessments.lines.update).
 *
 * WHAT IT ACCEPTS IS THE POINT. Only the assessor's own answers: likelihood,
 * impact, control effectiveness, the ASSESSED-mode residual pair, a treatment
 * override with its justification, and a rationale. Every calculated column is
 * absent from these rules and discarded by the service, because a server that
 * accepted a residual score from a browser would let anyone put any number
 * into a regulatory return by editing a request.
 *
 * THE ENUMS ARE READ FROM THE LINE'S OWN METHODOLOGY, not from a constant. A
 * line scored under the 2026 methodology must keep accepting 2026's labels
 * after the bank activates a 2027 one — validating against whatever is active
 * today would make every historical line unsavable the morning a new
 * methodology went live.
 */
class UpdateAssessmentLineRequest extends FormRequest
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
        $methodology = $this->methodology();

        $likelihood = array_keys($methodology?->scale(RcsaScaleItem::TYPE_LIKELIHOOD) ?? []);
        $impact = array_keys($methodology?->scale(RcsaScaleItem::TYPE_IMPACT) ?? []);

        $controlLabels = array_values(array_map(
            fn (RcsaScaleItem $item) => $item->label,
            $methodology?->scale(RcsaScaleItem::TYPE_CONTROL_EFFECTIVENESS) ?? []
        ));

        $treatments = $methodology?->bands
            ->map(fn (RcsaRiskBand $band) => $band->treatment)
            ->unique()
            ->values()
            ->all() ?? [];

        return [
            // Nullable throughout: the grid saves a cell at a time and clearing
            // an answer is a legitimate edit. A required rule here would mean an
            // assessor could never un-answer a question they got wrong.
            'inherent_likelihood' => ['nullable', 'integer', Rule::in($likelihood)],
            'inherent_impact' => ['nullable', 'integer', Rule::in($impact)],
            'control_effectiveness' => ['nullable', 'string', Rule::in($controlLabels)],

            'residual_likelihood' => ['nullable', 'integer', Rule::in($likelihood)],
            'residual_impact' => ['nullable', 'integer', Rule::in($impact)],

            'treatment_override' => ['nullable', 'string', Rule::in($treatments)],
            'treatment_override_reason' => ['nullable', 'string', 'max:2000'],
            'assessment_rationale' => ['nullable', 'string', 'max:2000'],

            // The version the client last read. Absent means "no check", which
            // is what a bulk apply and a first write want; the grid always
            // sends it.
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $line = $this->route('line');

            if (! $line instanceof RcsaAssessmentLine) {
                return;
            }

            /* --- The residual pair only exists in ASSESSED mode --------- */

            $methodology = $this->methodology();

            if ($methodology?->isCalculatedResidual()
                && (filled($this->input('residual_likelihood')) || filled($this->input('residual_impact')))) {
                $validator->errors()->add(
                    'residual_likelihood',
                    'This methodology calculates residual risk from the inherent score and the control rating. '
                        .'It does not accept a residual rating of its own.'
                );
            }

            /* --- An override needs a reason ---------------------------- */

            // Checked here as well as in the submission gate, so the assessor
            // is told at the moment they make the change rather than at the end
            // of the assessment when they have forgotten why they made it.
            if (filled($this->input('treatment_override'))
                && $this->input('treatment_override') !== $line->risk_treatment
                && blank($this->input('treatment_override_reason', $line->treatment_override_reason))) {
                $validator->errors()->add(
                    'treatment_override_reason',
                    'Say why you are overriding the calculated treatment.'
                );
            }
        });
    }

    /**
     * The methodology this LINE is pinned to.
     */
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
