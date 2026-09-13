<?php

namespace App\Http\Requests\Thresholds;

use App\Models\MeasureThreshold;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reject a queued threshold re-baselining (migration Phase 4.2).
 *
 * A reason is required: the band in force stays in force because someone
 * decided it should, and that decision is read later.
 */
class RejectRebaselineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rebaselineApprove', MeasureThreshold::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
