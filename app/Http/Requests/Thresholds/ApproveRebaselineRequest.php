<?php

namespace App\Http\Requests\Thresholds;

use App\Models\MeasureThreshold;
use Illuminate\Foundation\Http\FormRequest;

/** Approve a queued threshold re-baselining (migration Phase 4.2). */
class ApproveRebaselineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('rebaselineApprove', MeasureThreshold::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comments' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
