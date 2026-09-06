<?php

namespace App\Http\Requests\Admin\Scoring;

use App\Models\ScoringProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin/scoring-profiles/validate-formula — can this residual formula run?
 *
 * Asked while the operator is still typing, so it validates a DRAFT rather than
 * a record. That is why 6.4 left it as an inline validate() and why it is a
 * Form Request now regardless: the authorisation is the same either way, and
 * a formula string reaching FormulaEvaluator is worth bounding in one place.
 */
class ValidateFormulaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ScoringProfile::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'formula' => ['required', 'string', 'max:500'],
            'matrix_rows' => ['nullable', 'integer', 'min:3', 'max:10'],
            'matrix_cols' => ['nullable', 'integer', 'min:3', 'max:10'],
        ];
    }
}
