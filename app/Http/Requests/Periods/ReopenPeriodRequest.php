<?php

namespace App\Http\Requests\Periods;

use App\Models\Period;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reopen a closed period (migration Phase 4.2).
 *
 * The reason is mandatory and has a floor of ten characters, carried across
 * unchanged: this is the one operation that can change a number a board pack
 * has already been built on, and "fix" is not an audit trail.
 */
class ReopenPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $period = $this->route('period');

        return $period instanceof Period && $this->user()->can('reopen', $period);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Reopening a closed period needs a reason.',
            'reason.min' => 'Give a reason of at least 10 characters — this is read by an examiner.',
        ];
    }
}
