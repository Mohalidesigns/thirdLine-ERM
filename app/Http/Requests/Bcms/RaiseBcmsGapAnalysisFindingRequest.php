<?php

namespace App\Http\Requests\Bcms;

use App\Services\Bcms\Compliance\GapAnalyserService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a gap-analyser citation as a finding.
 *
 * B8 (gate 1 code review #1): this endpoint had no form request at all — an
 * unknown `clause_ref` reached `FindingService::raise()` uncaught, 500ing
 * instead of failing validation, and `finding` text carried no length bound.
 * The valid `clause_ref` set is read LIVE from
 * `GapAnalyserService::draftFindings()` — the SAME mandatory red/amber rows
 * the gap panel is currently showing — so a clause that has since gone
 * green, or was never mandatory/red/amber, cannot be raised as if it still
 * were.
 */
class RaiseBcmsGapAnalysisFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bcms.finding.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $currentGaps = collect(app(GapAnalyserService::class)->draftFindings())->pluck('clause_ref');

        return [
            'clause_ref' => ['required', 'string', Rule::in($currentGaps)],
            'finding' => ['required', 'string', 'max:2000'],
        ];
    }
}
