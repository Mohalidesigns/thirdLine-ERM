<?php

namespace App\Http\Requests\Rcsa;

use App\Models\Rcsa\RcsaAssessment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Apply one answer to several lines of an assessment (rcsa.assessments.bulk-apply).
 *
 * THE SHAPE ONLY. Deliberately narrow, as the service is: control effectiveness
 * and the two inherent ratings, and nothing else — a general "set these
 * columns on these rows" action is how a bulk edit comes to rewrite risk
 * statements. Which of the ids belong to THIS assessment, and which are still
 * open for editing, is answered by the controller against the assessment's own
 * lines, so an id from another assessment simply does not come back; this
 * request does not need (and must not use) a tenant lookup for them.
 */
class BulkApplyAssessmentLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assessment = $this->route('assessment');

        return $assessment instanceof RcsaAssessment
            && $this->user()->can('complete', $assessment);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'line_ids' => ['required', 'array', 'min:1', 'max:500'],
            'line_ids.*' => ['integer'],
            'control_effectiveness' => ['nullable', 'string'],
            'inherent_likelihood' => ['nullable', 'integer', 'min:1', 'max:10'],
            'inherent_impact' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }
}
