<?php

namespace App\Http\Requests\Admin\Scoring;

use App\Models\ScoringProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST admin/scoring-profiles/preview — how many risks move band if these bands
 * are saved as they stand.
 *
 * Also a draft rather than a record: the bands being previewed are the ones on
 * screen, which is the point. Band CONTIGUITY is deliberately not checked here
 * — StoreScoringProfileRequest owns that, because a half-typed set of bands is
 * expected to have gaps and refusing to preview it would defeat the feature.
 */
class PreviewBandsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ScoringProfile::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'matrix_rows' => ['required', 'integer', 'min:3', 'max:10'],
            'matrix_cols' => ['required', 'integer', 'min:3', 'max:10'],
            'rating_bands' => ['required', 'array', 'min:1'],
            'rating_bands.*.label' => ['required', 'string', 'max:60'],
            'rating_bands.*.min' => ['required', 'integer', 'min:0'],
            'rating_bands.*.max' => ['required', 'integer', 'min:0'],
        ];
    }
}
